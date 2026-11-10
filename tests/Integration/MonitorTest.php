<?php
declare(strict_types=1);

namespace ListenLab\Tests\Integration;

use ListenLab\Config;
use ListenLab\Database;
use ListenLab\Ingestion\Batches;
use ListenLab\Migrator;
use ListenLab\Monitoring\Monitor;
use ListenLab\Scheduling\Runtime;
use ListenLab\Scheduling\Scheduler;
use PDO;
use PHPUnit\Framework\TestCase;

final class MonitorTest extends TestCase
{
    private PDO $db;
    private Config $config;
    private string $schema;

    protected function setUp(): void
    {
        $this->config = Config::load(dirname(__DIR__,2));
        $name = $this->config->get('TEST_DB_NAME','listenlab_test');
        if (!preg_match('/^[a-zA-Z0-9_]+_test$/D',$name) || $name === $this->config->get('POSTGRES_DB','listenlab')) {
            throw new \RuntimeException('Use um TEST_DB_NAME separado do principal e terminado em _test.');
        }
        $this->db = Database::connect($this->config,$name);
        $this->schema = 'monitor_' . bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA ' . $this->schema);
        $this->db->exec('SET search_path TO ' . $this->schema);
        (new Migrator($this->db,$this->config->root . '/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        if (isset($this->db,$this->schema)) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->db->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function testEmptyHistoryDoesNotClaimTheSchedulerIsRunning(): void
    {
        $view = (new Monitor($this->db,$this->config))->overview();
        self::assertSame('unknown',$view['scheduler']['status']);
        self::assertNull($view['scheduler']['next_run_at']);
        self::assertNull($view['latest_sync']);
        self::assertSame(0,$view['preserved']['plays']);
    }

    public function testHeartbeatShowsRealIntervalAndAStaleSignalDisablesCountdown(): void
    {
        $runtime = new Runtime($this->db); $id = str_repeat('a',32);
        $runtime->start($id,900);
        $runtime->collecting($id);
        self::assertSame('collecting',$runtime->snapshot(300)['status']);
        $runtime->waiting($id,time()+900,0);
        $view = $runtime->snapshot(300);
        self::assertSame('waiting',$view['status']);
        self::assertSame(900,$view['interval_seconds']);
        self::assertNotNull($view['next_run_at']);
        $this->db->exec("UPDATE scheduler_runtime SET heartbeat_at=now()-interval '35 seconds'");
        $stale = $runtime->snapshot(300);
        self::assertSame('stale',$stale['status']);
        self::assertNull($stale['next_run_at']);
    }

    public function testOldInstanceCannotStopANewScheduler(): void
    {
        $runtime = new Runtime($this->db);
        $runtime->start(str_repeat('a',32),300);
        $runtime->start(str_repeat('b',32),600);
        $runtime->stop(str_repeat('a',32));
        self::assertSame('waiting',$runtime->snapshot(300)['status']);
        $runtime->stop(str_repeat('b',32));
        self::assertSame('stopped',$runtime->snapshot(300)['status']);
    }

    public function testHistoryFiltersPaginatesAndSeparatesLastSyncFromProbe(): void
    {
        $batches = new Batches($this->db);
        for ($i=0;$i<18;$i++) {
            $id=$batches->start($i===17 ? 'probe' : 'sync');
            Database::query($this->db,'UPDATE ingestion_batches SET status=?,finished_at=now() WHERE id=?',[$i%2===0 ? 'completed' : 'failed',$id]);
        }
        $monitor = new Monitor($this->db,$this->config);
        $first=$monitor->batches();$second=$monitor->batches(2);
        self::assertCount(15,$first['items']);self::assertCount(3,$second['items']);
        self::assertSame(18,$first['total']);self::assertSame(9,$monitor->batches(1,'failed')['total']);
        self::assertSame(2,$second['page']);
        $overview=$monitor->overview();
        self::assertSame('sync',$overview['latest_sync']['operation']);
        self::assertSame(18,$overview['today']['total']);
        self::assertSame(9,$overview['today']['completed']);
    }

    public function testDetailsDoNotExposePayloadsOrArbitraryMetadata(): void
    {
        $id=(new Batches($this->db))->start('sync');
        Database::query($this->db,'UPDATE ingestion_batches SET metadata=CAST(? AS jsonb) WHERE id=?',[
            json_encode(['pages'=>2,'access_token'=>'synthetic-private-token','arbitrary_payload'=>['private'=>'hidden']],JSON_THROW_ON_ERROR),$id]);
        Database::query($this->db,'INSERT INTO raw_artifacts(batch_id,path,sha256,size_bytes,http_status) VALUES (?,?,?,?,?)',[$id,'spotify/api/synthetic.json',str_repeat('a',64),50,200]);
        $detail=(new Monitor($this->db,$this->config))->detail($id);
        self::assertSame(2,$detail['pages']);self::assertCount(1,$detail['artifacts']);
        $json=json_encode($detail,JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-private-token',$json);
        self::assertStringNotContainsString('arbitrary_payload',$json);
        self::assertNull((new Monitor($this->db,$this->config))->detail(999999));
    }

    public function testSchedulerLifecycleRunsAChildAndMarksTheStop(): void
    {
        (new Scheduler($this->db,$this->config))->run(1,[PHP_BINARY,'-r','exit(2);']);
        $view=(new Runtime($this->db))->snapshot(300);
        self::assertSame('stopped',$view['status']);
        self::assertSame(2,$view['last_exit_code']);
        self::assertNull($view['next_run_at']);
    }

    public function testSchedulerKeepsSignallingDuringCollectionAndHandlesSigterm(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('Signal lifecycle validation requires the Docker image with pcntl.');
        }
        $script = '$config = require ' . var_export($this->config->root . '/bootstrap.php', true) . ';'
            . '$db = \\ListenLab\\Database::connect($config);'
            . '(new \\ListenLab\\Scheduling\\Scheduler($db, $config))->run(null, [PHP_BINARY, "-r", "sleep(30);"]);';
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->config->root, array_merge(getenv(), [
            'POSTGRES_DB' => $this->config->get('TEST_DB_NAME', 'listenlab_test'),
            'PGOPTIONS' => '-c search_path=' . $this->schema,
            'SYNC_INTERVAL_SECONDS' => '15',
        ]));
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $runtime = new Runtime($this->db);
            $firstSignal = null;
            $updated = false;
            $deadline = microtime(true) + 10;
            do {
                $view = $runtime->snapshot(300);
                if ($view['status'] === 'collecting') {
                    $firstSignal ??= $view['heartbeat_at'];
                    $updated = $view['heartbeat_at'] !== $firstSignal;
                }
                if ($updated) break;
                usleep(50000);
            } while (microtime(true) < $deadline && proc_get_status($process)['running']);
            self::assertTrue($updated, 'The scheduler must update its heartbeat while its child is still collecting.');
            self::assertSame(15, $view['interval_seconds']);
            self::assertNull($view['next_run_at']);

            self::assertTrue(proc_terminate($process, SIGTERM));
            $deadline = microtime(true) + 5;
            do {
                $view = $runtime->snapshot(300);
                if ($view['status'] === 'stopped') break;
                usleep(50000);
            } while (microtime(true) < $deadline);
            self::assertSame('stopped', $view['status']);
            self::assertNull($view['next_run_at']);
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process, SIGKILL);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testTwoSchedulerProcessesCannotOwnTheSameDatabase(): void
    {
        $other=Database::connect($this->config,$this->config->get('TEST_DB_NAME','listenlab_test'));
        $other->exec('SELECT pg_advisory_lock(194701,3)');
        try {
            $this->expectExceptionMessage('Já existe um agendador');
            (new Scheduler($this->db,$this->config))->run(1,[PHP_BINARY,'-r','exit(0);']);
        } finally {
            $other->exec('SELECT pg_advisory_unlock(194701,3)');
        }
    }

    public function testStorageOverviewSeparatesLogicalBytesAndLastSuccessfulBackup(): void
    {
        $batch=(new Batches($this->db))->start('sync');$hash=str_repeat('a',64);
        Database::query($this->db,"INSERT INTO raw_objects(sha256,size_bytes,stored_bytes,encoding,tier,first_received_at)VALUES(?,1000,200,'gzip','local',now())",[$hash]);
        for($i=0;$i<2;$i++){
            $id=(int)Database::query($this->db,'INSERT INTO raw_artifacts(batch_id,path,sha256,size_bytes)VALUES(?,?,?,1000)RETURNING id',[$batch,'synthetic/'.$i.'.json',$hash])->fetchColumn();
            Database::query($this->db,'INSERT INTO raw_storage_refs(artifact_id,sha256,reference_bytes)VALUES(?,?,100)',[$id,$hash]);
        }
        $this->db->exec("INSERT INTO raw_storage_runs(operation,status,finished_at,backup_verified,archive_bytes,snapshot_count)VALUES('archive','completed',now(),true,2500,1),('archive','failed',now(),false,0,0)");
        $view=(new Monitor($this->db,$this->config))->overview()['storage'];
        self::assertSame(2000,$view['logical_bytes']);self::assertSame(400,$view['local_bytes']);
        self::assertSame(1600,$view['saved_local_bytes']);self::assertSame(1,$view['unique_contents']);
        self::assertSame(2500,$view['archive_bytes']);self::assertSame(1,$view['snapshot_count']);
        self::assertSame('failed',$view['maintenance']['status']);self::assertNotNull($view['last_verified_backup_at']);
    }
}
