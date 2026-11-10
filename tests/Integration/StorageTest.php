<?php
declare(strict_types=1);

namespace ListenLab\Tests\Integration;

use ListenLab\AppError;
use ListenLab\Config;
use ListenLab\Database;
use ListenLab\Ingestion\Batches;
use ListenLab\Ingestion\Collector;
use ListenLab\Migrator;
use ListenLab\Spotify\Api;
use ListenLab\Storage\RawBackup;
use ListenLab\Storage\RawIndex;
use ListenLab\Storage\RawMaintenance;
use ListenLab\Storage\RawStore;
use ListenLab\Tests\Support\FakeTokens;
use ListenLab\Tests\Support\FakeTransport;
use ListenLab\Tests\Support\Fixtures;
use ListenLab\Tests\Support\TemporaryDirectory;
use PDO;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    use TemporaryDirectory;
    private PDO $db;
    private Config $config;
    private string $schema;
    private RawStore $raw;

    protected function setUp(): void
    {
        $this->createTemporary();$base=Config::load(dirname(__DIR__,2));
        $name=$base->get('TEST_DB_NAME','listenlab_test');
        if(!preg_match('/^[a-zA-Z0-9_]+_test$/D',$name)||$name===$base->get('POSTGRES_DB','listenlab'))throw new \RuntimeException('Storage tests require a separate database ending in _test.');
        $this->config=new Config($base->root,['RAW_STORAGE_PATH'=>$this->temporary.'/raw','RAW_ARCHIVE_PATH'=>$this->temporary.'/archive']);
        $this->db=Database::connect($this->config,$name);$this->schema='storage_'.bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA '.$this->schema);$this->db->exec('SET search_path TO '.$this->schema);
        (new Migrator($this->db,$base->root.'/migrations'))->migrate();
        $this->raw=new RawStore($this->temporary.'/raw',$this->temporary.'/archive');
    }

    protected function tearDown(): void
    {
        if(isset($this->db,$this->schema)){if($this->db->inTransaction())$this->db->rollBack();$this->db->exec('DROP SCHEMA '.$this->schema.' CASCADE');}
        $this->removeTemporary();
    }

    private function collect(): array
    {
        return (new Collector($this->db,$this->raw,new Api(new FakeTransport([Fixtures::response()]),new FakeTokens())))->collect('sync',5,50);
    }

    private function legacy(): array
    {
        $batch=(new Batches($this->db))->start('sync');$body=Fixtures::body();$path='spotify/api/legacy-'.$batch.'.json';
        if(!is_dir($this->raw->root.'/spotify/api'))mkdir($this->raw->root.'/spotify/api',0700,true);
        file_put_contents($this->raw->root.'/'.$path,$body);chmod($this->raw->root.'/'.$path,0440);
        $id=(int)Database::query($this->db,'INSERT INTO raw_artifacts(batch_id,path,sha256,size_bytes,http_status)VALUES(?,?,?,?,200)RETURNING id',[$batch,$path,hash('sha256',$body),strlen($body)])->fetchColumn();
        $this->db->exec("UPDATE ingestion_batches SET status='completed',finished_at=now() WHERE id=".$batch);
        return ['id'=>$id,'path'=>$path,'sha256'=>hash('sha256',$body),'body'=>$body];
    }

    public function testIdenticalResponsesShareOneObjectAndKeepAuditableOccurrences(): void
    {
        $this->collect();$this->collect();
        self::assertSame(1,(int)$this->db->query('SELECT count(*) FROM raw_objects')->fetchColumn());
        self::assertSame(2,(int)$this->db->query('SELECT count(*) FROM raw_storage_refs')->fetchColumn());
        self::assertSame(6,(int)$this->db->query('SELECT count(*) FROM ingestion_records')->fetchColumn());
        self::assertSame(3,(int)$this->db->query('SELECT count(*) FROM plays')->fetchColumn());
        self::assertSame('gzip',$this->db->query('SELECT encoding FROM raw_objects')->fetchColumn());
        self::assertSame(6,(int)$this->db->query('SELECT count(*) FROM ingestion_records WHERE payload IS NULL AND payload_sha256 IS NOT NULL')->fetchColumn());
    }

    public function testLegacyCompactionPreservesTheIndexAndRestoresAllRawPaths(): void
    {
        $first=$this->legacy();$this->legacy();$before=$this->db->query('SELECT * FROM raw_artifacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $maintenance=new RawMaintenance($this->db,$this->config,$this->raw);
        $plan=$maintenance->run();self::assertTrue($plan['dry_run']);self::assertSame(2,$plan['legacy_artifacts']);
        self::assertSame($first['body'],file_get_contents($this->raw->root.'/'.$first['path']));
        $result=$maintenance->run(true);self::assertTrue($result['backup_verified']);
        self::assertSame($before,$this->db->query('SELECT * FROM raw_artifacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($first['body'],$this->raw->readVerified($first['path'],$first['sha256']));
        self::assertLessThan($result['local_bytes_before'],$result['local_bytes_after']);
        $restored=$this->temporary.'/restored';
        self::assertSame(2,(new RawBackup($this->db,$this->config,$this->raw))->restoreRaw($result['snapshot'],$restored));
        self::assertSame($first['body'],(new RawStore($restored,$this->raw->archiveRoot))->readVerified($first['path'],$first['sha256']));
    }

    public function testArchiveRestoresTheDatabaseBeforeEvictionAndReplayStillWorks(): void
    {
        $this->collect();$id=(int)$this->db->query('SELECT min(id) FROM raw_artifacts')->fetchColumn();
        $result=(new RawMaintenance($this->db,$this->config,$this->raw))->run(true,true,0);
        self::assertTrue($result['backup_verified']);self::assertSame(1,$result['archived_contents']);
        self::assertSame('archive',$this->db->query('SELECT tier FROM raw_objects')->fetchColumn());
        self::assertSame([],glob($this->raw->root.'/objects/*/*json*'));
        $replay=(new Collector($this->db,$this->raw))->replay($id);
        self::assertSame(0,$replay['plays_created']);self::assertSame(3,$replay['already_ingested']);
        self::assertSame(3,(int)$this->db->query('SELECT count(*) FROM plays')->fetchColumn());
    }

    public function testCorruptArchivePreventsMaintenanceFromRemovingLocalContents(): void
    {
        $this->collect();$backup=new RawBackup($this->db,$this->config,$this->raw);$backup->create();
        $object=glob($this->raw->archiveRoot.'/objects/*/*.gz')[0];chmod($object,0600);file_put_contents($object,'broken');
        try{(new RawMaintenance($this->db,$this->config,$this->raw))->run(true,true,0);self::fail('Corrupt archive must fail.');}catch(AppError){}
        self::assertCount(1,glob($this->raw->root.'/objects/*/*.gz'));
        self::assertSame('local',$this->db->query('SELECT tier FROM raw_objects')->fetchColumn());
        self::assertSame('failed',$this->db->query('SELECT status FROM raw_storage_runs ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    public function testChangedDatabaseDumpCannotAuthorizeLocalRemoval(): void
    {
        $this->collect();$backup=new RawBackup($this->db,$this->config,$this->raw);$snapshot=$backup->create()['snapshot'];
        $dump=$this->raw->archiveRoot.'/snapshots/'.$snapshot.'/database.dump';file_put_contents($dump,'tampered',FILE_APPEND);
        $hash=$this->db->query('SELECT sha256 FROM raw_objects')->fetchColumn();
        try{$backup->assertCovers($snapshot,$hash);self::fail('Changed dump must be refused.');}catch(AppError){}
        self::assertSame('local',$this->raw->objectInfo($hash)['tier']);
    }

    public function testRestoreRefusesAnExistingDestination(): void
    {
        $this->collect();$backup=new RawBackup($this->db,$this->config,$this->raw);$snapshot=$backup->create()['snapshot'];
        $this->expectExceptionMessage('pasta de restauração precisa ser nova');$backup->restoreRaw($snapshot,$this->raw->root);
    }

    public function testMissingBackupConfigurationPreservesLegacyBytes(): void
    {
        $legacy=$this->legacy();$store=new RawStore($this->raw->root);
        try{(new RawMaintenance($this->db,$this->config,$store))->run(true);self::fail('Missing backup must fail.');}catch(AppError){}
        self::assertSame($legacy['body'],file_get_contents($this->raw->root.'/'.$legacy['path']));
        self::assertSame(0,(int)$this->db->query('SELECT count(*) FROM raw_storage_refs')->fetchColumn());
    }

    public function testMaintenanceCannotOverlapAnotherIngestion(): void
    {
        $other=Database::connect($this->config,$this->config->get('TEST_DB_NAME','listenlab_test'));$other->exec('SELECT pg_advisory_lock(194701,2)');
        try{$this->expectExceptionMessage('Outra ingestão ou manutenção');(new RawMaintenance($this->db,$this->config,$this->raw))->run(true);}finally{$other->exec('SELECT pg_advisory_unlock(194701,2)');}
    }

    public function testDailyCheckDoesNotCreateUnnecessaryDatabaseBackups(): void
    {
        $this->collect();$maintenance=new RawMaintenance($this->db,$this->config,$this->raw);
        $first=$maintenance->run(true,false);self::assertNull($first['snapshot']);
        $second=$maintenance->run(true,true,30,true);self::assertTrue($second['skipped']);
        self::assertSame([],glob($this->raw->archiveRoot.'/snapshots/*'));
    }

    public function testBackupRotationKeepsTwoVerifiedSnapshotsAndAllRawObjects(): void
    {
        $this->collect();$backup=new RawBackup($this->db,$this->config,$this->raw);
        $backup->create();$backup->create();$current=$backup->create()['snapshot'];$backup->rotate($current,2);
        self::assertCount(2,glob($this->raw->archiveRoot.'/snapshots/*/database.dump'));
        self::assertFileExists($this->raw->archiveRoot.'/snapshots/'.$current.'/manifest.json');
        $hash=$this->db->query('SELECT sha256 FROM raw_objects')->fetchColumn();$this->raw->verifyBackupObject($hash);
        self::assertCount(1,glob($this->raw->archiveRoot.'/objects/*/*.gz'));
    }

    public function testBackupRotationNeverDeletesUnknownFiles(): void
    {
        $this->collect();$backup=new RawBackup($this->db,$this->config,$this->raw);$first=$backup->create()['snapshot'];
        $note=$this->raw->archiveRoot.'/snapshots/'.$first.'/owner-note.txt';file_put_contents($note,'preserve me');
        $backup->create();$current=$backup->create()['snapshot'];$backup->rotate($current,2);
        self::assertSame('preserve me',file_get_contents($note));
        self::assertFileExists($this->raw->archiveRoot.'/snapshots/'.$first.'/database.dump');
    }

    public function testAnOccurrenceCannotBeReinterpretedWithAnotherPayload(): void
    {
        $this->collect();$artifact=$this->db->query('SELECT id,batch_id,received_at FROM raw_artifacts LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $body=json_decode(Fixtures::body());$body->items[0]->track->name='Changed after capture';
        $ingestor=new \ListenLab\Ingestion\Ingestor($this->db,$this->raw,new Batches($this->db));
        $this->expectExceptionMessage('não corresponde ao payload');
        $ingestor->page((int)$artifact['batch_id'],['id'=>$artifact['id'],'observed_at'=>$artifact['received_at']],json_encode($body,JSON_THROW_ON_ERROR),new \ListenLab\Ingestion\Summary());
    }

    public function testExistingJsonOccurrencesRemainCompatible(): void
    {
        $this->collect();$page=json_decode(Fixtures::body());
        $artifact=$this->db->query('SELECT id,batch_id,received_at FROM raw_artifacts LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        foreach($page->items as $index=>$item)Database::query($this->db,'UPDATE ingestion_records SET payload=CAST(? AS jsonb),payload_sha256=NULL WHERE raw_artifact_id=? AND item_index=?',[json_encode($item,JSON_THROW_ON_ERROR),$artifact['id'],$index]);
        $summary=new \ListenLab\Ingestion\Summary();
        (new \ListenLab\Ingestion\Ingestor($this->db,$this->raw,new Batches($this->db)))->page((int)$artifact['batch_id'],['id'=>$artifact['id'],'observed_at'=>$artifact['received_at']],Fixtures::body(),$summary);
        self::assertSame(3,$summary->existing);self::assertSame(0,$summary->failed);
        self::assertSame(3,(int)$this->db->query('SELECT count(*) FROM plays')->fetchColumn());
    }

    public function testDailyMaintenanceBacksUpRecentDataWithoutEvictingIt(): void
    {
        $this->collect();$maintenance=new RawMaintenance($this->db,$this->config,$this->raw);
        $first=$maintenance->run(true,true,30,true);
        self::assertTrue($first['backup_verified']);self::assertSame(0,$first['archived_contents']);
        self::assertSame('local',$this->db->query('SELECT tier FROM raw_objects')->fetchColumn());
        self::assertTrue($maintenance->run(true,true,30,true)['skipped']);
        self::assertCount(1,glob($this->raw->archiveRoot.'/snapshots/*/database.dump'));
    }

    public function testFailedDailyBackupDoesNotRepeatAtEveryCollection(): void
    {
        $this->collect();(new RawBackup($this->db,$this->config,$this->raw))->create();
        $object=glob($this->raw->archiveRoot.'/objects/*/*.gz')[0];chmod($object,0600);file_put_contents($object,'broken');
        $maintenance=new RawMaintenance($this->db,$this->config,$this->raw);
        try{$maintenance->run(true,true,30,true);self::fail('Corrupt archive must fail.');}catch(AppError){}
        $snapshots=glob($this->raw->archiveRoot.'/snapshots/*');
        self::assertSame('failed',$this->db->query('SELECT status FROM raw_storage_runs ORDER BY id DESC LIMIT 1')->fetchColumn());
        self::assertTrue($maintenance->run(true,true,30,true)['skipped']);
        self::assertSame(1,(int)$this->db->query('SELECT count(*) FROM raw_storage_runs')->fetchColumn());
        self::assertSame($snapshots,glob($this->raw->archiveRoot.'/snapshots/*'));
        self::assertCount(1,glob($this->raw->root.'/objects/*/*.gz'));
    }
}
