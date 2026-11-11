<?php
declare(strict_types=1);

namespace ListenLab\Tests\Integration;

use ListenLab\AppError;
use ListenLab\Config;
use ListenLab\Database;
use ListenLab\Ingestion\Batches;
use ListenLab\Ingestion\Collector;
use ListenLab\Ingestion\Ingestor;
use ListenLab\Ingestion\Summary;
use ListenLab\Migrator;
use ListenLab\Spotify\Api;
use ListenLab\Storage\RawStore;
use ListenLab\Tests\Support\FakeTokens;
use ListenLab\Tests\Support\FakeTransport;
use ListenLab\Tests\Support\Fixtures;
use ListenLab\Tests\Support\TemporaryDirectory;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompactAuditTest extends TestCase
{
    use TemporaryDirectory;
    private PDO $db;
    private Config $config;
    private string $database;
    private string $schema;
    private RawStore $raw;

    protected function setUp(): void
    {
        $this->createTemporary();
        $this->config = Config::load(dirname(__DIR__, 2));
        $this->database = $this->config->get('TEST_DB_NAME', 'listenlab_test');
        if (!preg_match('/^[a-zA-Z0-9_]+_test$/D', $this->database) || $this->database === $this->config->get('POSTGRES_DB', 'listenlab')) {
            throw new \RuntimeException('Compact audit tests require a separate database ending in _test.');
        }
        $this->db = Database::connect($this->config, $this->database);
        $this->schema = 'compact_audit_' . bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA ' . $this->schema);
        $this->db->exec('SET search_path TO ' . $this->schema);
        (new Migrator($this->db, $this->config->root . '/migrations'))->migrate();
        $this->raw = new RawStore($this->temporary . '/raw');
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->schema)) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->db->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        $this->removeTemporary();
    }

    private function collect(array $items): array
    {
        return (new Collector($this->db, $this->raw, new Api(new FakeTransport([Fixtures::response($items)]), new FakeTokens())))->collect('sync', 5, 50);
    }

    public function testFiftyReceivedWithTwoNewKeepsOnlyTwoAdditionalItemRecords(): void
    {
        $items = [];
        for ($i = 0; $i < 52; ++$i) {
            $items[] = Fixtures::item((new \DateTimeImmutable('2026-11-01T00:00:00Z'))->modify('+' . $i . ' minutes')->format(DATE_ATOM));
        }
        $this->collect(array_slice($items, 0, 50));
        $original = $this->db->query('SELECT * FROM ingestion_records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $next = $this->collect(array_slice($items, 2, 50));
        self::assertSame(50, $next['api_records_received']);
        self::assertSame(2, $next['plays_created']);
        self::assertSame(48, $next['already_ingested']);
        self::assertSame(2, $next['audit_records_created']);
        self::assertSame(48, $next['duplicate_records_omitted']);
        self::assertSame(52, (int) $this->db->query('SELECT count(*) FROM ingestion_records')->fetchColumn());
        self::assertSame(52, (int) $this->db->query('SELECT count(*) FROM plays')->fetchColumn());
        self::assertSame($original, $this->db->query('SELECT * FROM ingestion_records WHERE id<=50 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $saved = json_decode($this->db->query('SELECT metadata FROM ingestion_batches ORDER BY id DESC LIMIT 1')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('distinct_payloads_v1', $saved['audit_policy']);
        self::assertSame(48, $saved['duplicate_records_omitted']);
    }

    public function testChangedPayloadIsKeptOnceAndItsExactRepetitionIsOmitted(): void
    {
        $this->collect([Fixtures::item()]);
        $changed = Fixtures::item();
        $changed->track->name = 'Nome revisado fictício';
        $changed->unknown_future_field->revision = 'synthetic-change';
        $report = $this->collect([$changed]);
        self::assertSame(0, $report['plays_created']);
        self::assertSame(1, $report['audit_records_created']);
        self::assertSame(0, $report['duplicate_records_omitted']);
        $again = $this->collect([$changed]);
        self::assertSame(0, $again['audit_records_created']);
        self::assertSame(1, $again['duplicate_records_omitted']);
        self::assertSame(2, (int) $this->db->query('SELECT count(*) FROM ingestion_records')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM plays')->fetchColumn());
        self::assertSame(2, (int) $this->db->query('SELECT count(DISTINCT payload_sha256) FROM ingestion_records')->fetchColumn());
        $artifact = (int) $this->db->query('SELECT max(id) FROM raw_artifacts')->fetchColumn();
        $replay = (new Collector($this->db, $this->raw))->replay($artifact);
        self::assertSame(1, $replay['duplicate_records_omitted']);
        self::assertSame(2, (int) $this->db->query('SELECT count(*) FROM ingestion_records')->fetchColumn());
    }

    public function testErrorsReviewsAndIncompleteItemsKeepTheirDetailedOccurrences(): void
    {
        $partial = Fixtures::item(); $partial->track->album = null;
        $bad = Fixtures::item(); $bad->played_at = 'invalid-date';
        $episode = Fixtures::item(); $episode->track->type = 'episode';
        foreach ([1, 2] as $attempt) {
            $report = $this->collect([$partial, $bad, $episode]);
            self::assertSame('partial', $report['status']);
            self::assertSame(3, $report['audit_records_created']);
            self::assertSame(0, $report['duplicate_records_omitted']);
            self::assertSame(2, $report['records_failed']);
        }
        self::assertSame(6, (int) $this->db->query('SELECT count(*) FROM ingestion_records')->fetchColumn());
        self::assertSame(2, (int) $this->db->query("SELECT count(*) FROM ingestion_records WHERE processing_status='rejected'")->fetchColumn());
        self::assertSame(2, (int) $this->db->query("SELECT count(*) FROM ingestion_records WHERE processing_status='pending_review'")->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM plays')->fetchColumn());
    }

    public function testUnrepresentableStringsAreDetailedWhileEscapedTextRemainsValid(): void
    {
        $valid = Fixtures::item(); $valid->unknown_future_field->literal = '\\u0000';
        $badValue = Fixtures::item(); $badValue->unknown_future_field->value = "\0";
        $badKey = Fixtures::item(); $badKey->unknown_future_field->{"bad\0key"} = 'synthetic';
        $first = $this->collect([$valid, $badValue, $badKey]);
        self::assertSame('partial', $first['status']);
        self::assertSame(1, $first['plays_created']);
        self::assertSame(2, $first['records_failed']);
        $again = $this->collect([$valid, $badValue, $badKey]);
        self::assertSame(1, $again['duplicate_records_omitted']);
        self::assertSame(2, $again['audit_records_created']);
        self::assertSame(4, (int) $this->db->query("SELECT count(*) FROM ingestion_issues WHERE code='unrepresentable_value'")->fetchColumn());
        self::assertSame(5, (int) $this->db->query('SELECT count(*) FROM ingestion_records')->fetchColumn());
    }

    public function testOmittedOccurrenceCannotBeReinterpretedUnderAnotherRawPayload(): void
    {
        $this->collect([Fixtures::item()]);
        $this->collect([Fixtures::item()]);
        $row = $this->db->query('SELECT id,batch_id,received_at FROM raw_artifacts ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) Database::query($this->db, 'SELECT count(*) FROM ingestion_records WHERE raw_artifact_id=?', [$row['id']])->fetchColumn());
        $changed = Fixtures::item(); $changed->track->name = 'Falso conteúdo posterior';
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('não corresponde ao payload');
        (new Ingestor($this->db, $this->raw, new Batches($this->db)))->page((int) $row['batch_id'], ['id' => $row['id'], 'observed_at' => $row['received_at']], Fixtures::body([$changed]), new Summary());
    }

    public function testLegacyJsonCanProveEqualityWithoutRewritingTheOldRecord(): void
    {
        $item = Fixtures::item();
        $this->collect([$item]);
        Database::query($this->db, 'UPDATE ingestion_records SET payload=?::jsonb,payload_sha256=NULL', [json_encode($item, JSON_THROW_ON_ERROR)]);
        $before = $this->db->query('SELECT * FROM ingestion_records')->fetchAll(PDO::FETCH_ASSOC);
        $report = $this->collect([$item]);
        self::assertSame(1, $report['duplicate_records_omitted']);
        self::assertSame(0, $report['audit_records_created']);
        self::assertSame($before, $this->db->query('SELECT * FROM ingestion_records')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testAnotherNormalizerVersionCannotSilentlySuppressDetailedProcessing(): void
    {
        $this->collect([Fixtures::item()]);
        $this->db->exec("UPDATE ingestion_records SET normalizer_version='synthetic-older-version'");
        $report = $this->collect([Fixtures::item()]);
        self::assertSame(1, $report['audit_records_created']);
        self::assertSame(0, $report['duplicate_records_omitted']);
        self::assertSame(2, (int) $this->db->query('SELECT count(*) FROM ingestion_records')->fetchColumn());
        self::assertSame(1, $this->collect([Fixtures::item()])['duplicate_records_omitted']);
    }

    public function testConcurrentIngestorsKeepOnePlayAndOneCanonicalOccurrence(): void
    {
        $batches = new Batches($this->db);
        $ingestor = new Ingestor($this->db, $this->raw, $batches);
        $response = Fixtures::response([Fixtures::item()]);
        $jobs = [];
        $script = $this->temporary . '/worker.php';
        file_put_contents($script, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$config=\ListenLab\Config::load($argv[1]);
if(!preg_match('/^[a-zA-Z0-9_]+_test$/D',$argv[2])||$argv[2]===$config->get('POSTGRES_DB','listenlab')||!preg_match('/^compact_audit_[a-f0-9]+$/D',$argv[3]))throw new RuntimeException('Unsafe test target.');
$db=\ListenLab\Database::connect($config,$argv[2]);$db->exec('SET search_path TO '.$argv[3]);
$artifact=\ListenLab\Database::query($db,'SELECT id,batch_id,path,sha256,received_at FROM raw_artifacts WHERE id=?',[$argv[5]])->fetch(PDO::FETCH_ASSOC);
$raw=new \ListenLab\Storage\RawStore($argv[4]);$body=$raw->readVerified($artifact['path'],$artifact['sha256']);
touch($argv[6]);$deadline=microtime(true)+10;
while(!file_exists($argv[7])){if(microtime(true)>$deadline)throw new RuntimeException('Test barrier timed out.');usleep(10000);}
$summary=new \ListenLab\Ingestion\Summary();
(new \ListenLab\Ingestion\Ingestor($db,$raw,new \ListenLab\Ingestion\Batches($db)))->page((int)$artifact['batch_id'],['id'=>$artifact['id'],'observed_at'=>$artifact['received_at']],$body,$summary);
echo json_encode(['inserted'=>$summary->inserted,'audit_created'=>$summary->auditCreated,'omitted'=>$summary->duplicatesOmitted],JSON_THROW_ON_ERROR);
PHP);
        try {
            for ($i = 0; $i < 2; ++$i) {
                $batch = $batches->start('sync');
                $artifact = $ingestor->capture($batch, $response, [], new Summary());
                $ready = $this->temporary . '/ready-' . $i;
                $process = proc_open([PHP_BINARY, $script, $this->config->root, $this->database, $this->schema, $this->raw->root, (string) $artifact['id'], $ready, $this->temporary . '/go'], [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
                if (!is_resource($process)) throw new \RuntimeException('Cannot start concurrent test worker.');
                $jobs[] = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready];
            }
            $deadline = microtime(true) + 5;
            while ((!file_exists($jobs[0]['ready']) || !file_exists($jobs[1]['ready'])) && microtime(true) < $deadline) usleep(10000);
            self::assertFileExists($jobs[0]['ready']); self::assertFileExists($jobs[1]['ready']);
            touch($this->temporary . '/go');
            $results = [];
            foreach ($jobs as &$job) {
                $result = stream_get_contents($job['pipes'][1]);
                $error = stream_get_contents($job['pipes'][2]);
                fclose($job['pipes'][1]); fclose($job['pipes'][2]); $job['pipes'] = [];
                $exit = proc_close($job['process']); $job['process'] = null;
                self::assertSame(0, $exit, $error);
                $results[] = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            }
            self::assertSame(1, array_sum(array_column($results, 'inserted')));
            self::assertSame(1, array_sum(array_column($results, 'audit_created')));
            self::assertSame(1, array_sum(array_column($results, 'omitted')));
            self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM plays')->fetchColumn());
            self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM ingestion_records')->fetchColumn());
            self::assertSame(2, (int) $this->db->query('SELECT count(*) FROM raw_artifacts')->fetchColumn());
        } finally {
            unset($job);
            foreach ($jobs as $job) {
                if (is_resource($job['process'])) {
                    proc_terminate($job['process']);
                    foreach ($job['pipes'] as $pipe) fclose($pipe);
                    proc_close($job['process']);
                }
            }
        }
    }
}
