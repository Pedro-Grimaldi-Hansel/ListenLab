<?php
declare(strict_types=1);

namespace ListenLab\Tests\Integration;

use ListenLab\AppError;
use ListenLab\Config;
use ListenLab\Database;
use ListenLab\History;
use ListenLab\Http\Response;
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
use PDOException;
use PHPUnit\Framework\TestCase;

final class IngestionTest extends TestCase
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
        if (!preg_match('/^[a-zA-Z0-9_]+_test$/D', $this->database)) {
            throw new \RuntimeException('TEST_DB_NAME deve conter um nome de banco terminado em _test. Valor recebido: '
                . json_encode($this->database, JSON_THROW_ON_ERROR) . '. Configure TEST_DB_NAME=listenlab_test no .env.');
        }
        if ($this->database === $this->config->get('POSTGRES_DB', 'listenlab')) {
            throw new \RuntimeException('TEST_DB_NAME e POSTGRES_DB estão iguais. Mantenha POSTGRES_DB como banco principal '
                . 'e TEST_DB_NAME=listenlab_test como banco de testes separado. A suíte não será executada no banco principal.');
        }
        $this->db = Database::connect($this->config, $this->database);
        $this->schema = 'test_' . bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA ' . $this->schema);
        $this->db->exec('SET search_path TO ' . $this->schema);
        (new Migrator($this->db, $this->config->root . '/migrations'))->migrate();
        $this->raw = new RawStore($this->temporary . '/raw');
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->schema)) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        $this->removeTemporary();
    }

    private function collector(array $responses): Collector
    {
        return new Collector($this->db, $this->raw, new Api(new FakeTransport($responses), new FakeTokens(), sleep: static fn () => null));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->db->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }

    public function testSamePayloadTwiceCreatesNoDuplicatePlaysAndKeepsEveryOccurrence(): void
    {
        $response = Fixtures::response();
        $collector = $this->collector([$response, $response]);
        $first = $collector->collect('sync', 5, 50);
        $second = $collector->collect('sync', 5, 50);
        self::assertSame('completed', $first['status']);
        self::assertSame(3, $first['plays_created']);
        self::assertSame(0, $second['plays_created']);
        self::assertSame(3, $second['already_ingested']);
        self::assertSame(3, $this->rowCount('plays'));
        self::assertSame(2, $this->rowCount('tracks'));
        self::assertSame(3, $this->rowCount('play_evidence'));
        self::assertSame(6, $this->rowCount('ingestion_records'));
        self::assertSame(2, $this->rowCount('raw_artifacts'));
        foreach ($second['raw_paths'] as $path) {
            self::assertSame($response->body, $this->raw->readVerified($path,hash('sha256',$response->body)));
        }
    }

    public function testMultipleArtistsNullsAndCatalogDurationAreNotListeningTime(): void
    {
        $this->collector([Fixtures::response([Fixtures::item()])])->collect('sync', 5, 50);
        $row = $this->db->query('SELECT ms_played, skipped, shuffle FROM plays')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['ms_played' => null, 'skipped' => null, 'shuffle' => null], $row);
        $track = $this->db->query('SELECT duration_ms, explicit, is_local FROM tracks')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(180000, $track['duration_ms']);
        self::assertNull($track['explicit']);
        self::assertFalse($track['is_local']);
        self::assertSame(2, $this->rowCount('track_artists'));
        $rows = (new History($this->db))->recent(10);
        self::assertSame('Artista fictício A, Artista fictício B', $rows[0]['artists']);
        self::assertSame('spotify_api', $rows[0]['source']);
    }

    public function testOneMalformedItemDoesNotLoseValidItemsOrRaw(): void
    {
        $bad = Fixtures::item();
        $bad->played_at = '2026-02-30T07:00:00Z';
        $response = Fixtures::response([Fixtures::item(), $bad, Fixtures::item('2026-11-10T07:04:00Z')]);
        $report = $this->collector([$response])->collect('sync', 5, 50);
        self::assertSame('partial', $report['status']);
        self::assertSame(2, $this->rowCount('plays'));
        self::assertSame(1, $report['records_failed']);
        self::assertSame(1, $this->rowCount('ingestion_issues'));
        self::assertSame('rejected', $this->db->query("SELECT processing_status FROM ingestion_records WHERE item_index = 1")->fetchColumn());
        self::assertSame($response->body, $this->raw->readVerified($report['raw_paths'][0],hash('sha256',$response->body)));
    }

    public function testMalformedWholeResponseIsArchivedAndMarkedFailed(): void
    {
        $report = $this->collector([new Response(200, '{broken-json')])->collect('sync', 5, 50);
        self::assertSame('failed', $report['status']);
        self::assertSame(0, $this->rowCount('plays'));
        self::assertSame(1, $this->rowCount('raw_artifacts'));
        self::assertGreaterThanOrEqual(1, $this->rowCount('ingestion_issues'));
        self::assertSame('{broken-json', $this->raw->readVerified($report['raw_paths'][0],hash('sha256','{broken-json')));
    }

    public function testLocalTrackIsValidAndReimportIsIdempotent(): void
    {
        $item = Fixtures::item();
        $item->track->id = null;
        $item->track->uri = 'spotify:local:Ficticio:Disco:Faixa:180';
        $item->track->is_local = true;
        $response = Fixtures::response([$item]);
        $collector = $this->collector([$response, $response]);
        $first = $collector->collect('sync', 5, 50);
        $second = $collector->collect('sync', 5, 50);
        self::assertSame(1, $first['plays_created']);
        self::assertSame(0, $second['plays_created']);
        self::assertSame(1, $this->rowCount('tracks'));
        self::assertNull($this->db->query('SELECT external_id FROM tracks')->fetchColumn());
    }

    public function testReplayVerifiesHashAndReusesSourceEvidence(): void
    {
        $this->collector([Fixtures::response()])->collect('sync', 5, 50);
        $id = (int) $this->db->query('SELECT min(id) FROM raw_artifacts')->fetchColumn();
        $report = (new Collector($this->db, $this->raw))->replay($id);
        self::assertSame('completed', $report['status']);
        self::assertSame(0, $report['plays_created']);
        self::assertSame(3, $report['already_ingested']);
        self::assertSame(3, $this->rowCount('plays'));
        self::assertSame(2, $this->rowCount('raw_artifacts'));
    }

    public function testSameArtifactCanBeProcessedTwiceWithinTheSameBatch(): void
    {
        $batches = new Batches($this->db);
        $batch = $batches->start('test');
        $ingestor = new Ingestor($this->db, $this->raw, $batches);
        $response = Fixtures::response();
        $summary = new Summary();
        $artifact = $ingestor->capture($batch, $response, [], $summary);
        $ingestor->page($batch, $artifact, $response->body, $summary);
        $again = new Summary();
        $ingestor->page($batch, $artifact, $response->body, $again);
        self::assertSame(0, $again->inserted);
        self::assertSame(3, $again->existing);
        self::assertSame(3, $this->rowCount('plays'));
        self::assertSame(3, $this->rowCount('ingestion_records'));
    }

    public function testSyncFollowsNextAndHandlesOverlappingPages(): void
    {
        $next = Api::ENDPOINT . '?before=1794294000000&limit=50';
        $http = new FakeTransport([
            Fixtures::response([Fixtures::item(), Fixtures::item('2026-11-10T07:04:00Z')], $next),
            Fixtures::response([Fixtures::item(), Fixtures::item('2026-11-09T07:00:00Z')]),
        ]);
        $report = (new Collector($this->db, $this->raw, new Api($http, new FakeTokens())))->collect('sync', 5, 50);
        self::assertSame('completed', $report['status']);
        self::assertSame(2, $report['pages']);
        self::assertSame(4, $report['api_records_received']);
        self::assertSame(3, $report['unique_source_records_observed']);
        self::assertSame(3, $report['plays_created']);
        self::assertSame(1, $report['already_ingested']);
        self::assertSame('1794294000000', $http->requests[1]['options']['query']['before']);
        self::assertSame('2026-11-09T07:00:00.000000Z', $report['oldest']);
        self::assertSame('2026-11-10T07:04:00.000000Z', $report['newest']);
        self::assertSame(86640.0, $report['covered_seconds']);
    }

    public function testProbeUsesBeforeEvenIfNextIsNullAndStopsAtEmptyPage(): void
    {
        $http = new FakeTransport([
            Fixtures::response([Fixtures::item()], before: '1794294000000'),
            Fixtures::response([Fixtures::item('2026-11-09T07:00:00Z')], before: '1794207600000'),
            Fixtures::response([]),
        ]);
        $report = (new Collector($this->db, $this->raw, new Api($http, new FakeTokens())))->collect('probe', 10, 50);
        self::assertSame('completed', $report['status']);
        self::assertSame(3, $report['pages']);
        self::assertSame(2, $report['unique_source_records_observed']);
        self::assertSame('empty_page', $report['stop_reason']);
        self::assertSame('1794294000000', $http->requests[1]['options']['query']['before']);
        self::assertSame('1794207600000', $http->requests[2]['options']['query']['before']);
    }

    public function testPageBudgetIsReportedAsPartialRatherThanCompleteHistory(): void
    {
        $report = $this->collector([Fixtures::response(next: Api::ENDPOINT . '?before=1')])->collect('sync', 1, 50);
        self::assertSame('partial', $report['status']);
        self::assertSame('page_limit', $report['stop_reason']);
        self::assertSame(3, $this->rowCount('plays'));
    }

    public function testRepeatedPageTerminatesSafelyWithRawPreserved(): void
    {
        $report = $this->collector([
            Fixtures::response(before: '1794294000000'), Fixtures::response(before: '1794293000000'),
        ])->collect('probe', 10, 50);
        self::assertSame('partial', $report['status']);
        self::assertSame('no_progress', $report['stop_reason']);
        self::assertSame(3, $this->rowCount('plays'));
        self::assertSame(2, $this->rowCount('raw_artifacts'));
    }

    public function testQuotaErrorIsPreservedAndDoesNotClaimSuccess(): void
    {
        $report = $this->collector([new Response(429, '{"error":{"reason":"QUOTA_EXCEEDED"}}')])->collect('sync', 5, 50);
        self::assertSame('failed', $report['status']);
        self::assertSame(1, $this->rowCount('raw_artifacts'));
        self::assertSame(0, $this->rowCount('plays'));
        self::assertStringContainsString('QUOTA_EXCEEDED', $report['error']);
    }

    public function testNonMusicIsPreservedForReview(): void
    {
        $item = Fixtures::item();
        $item->track->type = 'episode';
        $report = $this->collector([Fixtures::response([$item])])->collect('sync', 5, 50);
        self::assertSame('partial', $report['status']);
        self::assertSame(0, $this->rowCount('plays'));
        self::assertSame('pending_review', $this->db->query('SELECT processing_status FROM ingestion_records')->fetchColumn());
    }

    public function testDatabaseConstraintProtectsSourceIdentity(): void
    {
        $collector = $this->collector([Fixtures::response(), Fixtures::response()]);
        $collector->collect('sync', 5, 50);
        $collector->collect('sync', 5, 50);
        $anotherOccurrence = (int) $this->db->query('SELECT max(id) FROM ingestion_records')->fetchColumn();
        $this->expectException(PDOException::class);
        Database::query($this->db, 'INSERT INTO play_evidence(play_id,source_id,source_record_key,first_ingestion_record_id) SELECT play_id,source_id,source_record_key,? FROM play_evidence LIMIT 1', [$anotherOccurrence]);
    }

    public function testConcurrentCollectorIsRejectedByTheDatabaseLock(): void
    {
        $other = Database::connect($this->config, $this->database);
        $other->exec('SELECT pg_advisory_lock(194701, 2)');
        try {
            $this->expectExceptionMessage('Outra ingestão');
            $this->collector([])->collect('sync', 5, 50);
        } finally {
            $other->exec('SELECT pg_advisory_unlock(194701, 2)');
        }
    }

    public function testRawRegistryCannotBeModifiedAndMigrationsAreRepeatable(): void
    {
        self::assertSame([], (new Migrator($this->db, $this->config->root . '/migrations'))->migrate());
        $this->collector([Fixtures::response()])->collect('sync', 5, 50);
        $this->expectException(PDOException::class);
        $this->db->exec("UPDATE raw_artifacts SET sha256 = repeat('0',64)");
    }

    public function testDerivedTablesCanBeRebuiltFromRawWithoutSpotify(): void
    {
        $this->collector([Fixtures::response()])->collect('sync', 5, 50);
        $originalId = (int) $this->db->query('SELECT min(id) FROM raw_artifacts')->fetchColumn();
        $this->db->exec('TRUNCATE play_evidence, ingestion_records, plays, track_artists, tracks, albums, artists, ingestion_issues RESTART IDENTITY');
        self::assertSame(0, $this->rowCount('plays'));
        self::assertSame(1, $this->rowCount('raw_artifacts'));
        $rebuilt = (new Collector($this->db, $this->raw))->replay($originalId);
        self::assertSame('completed', $rebuilt['status']);
        self::assertSame(3, $rebuilt['plays_created']);
        self::assertSame(3, $this->rowCount('plays'));
        self::assertSame(2, $this->rowCount('tracks'));
    }

    public function testJsonValueUnsupportedByPostgresStillSurvivesInRaw(): void
    {
        $bad = Fixtures::item();
        $bad->track->name = "nul\0byte";
        $response = Fixtures::response([$bad, Fixtures::item('2026-11-10T07:04:00Z')]);
        $report = $this->collector([$response])->collect('sync', 5, 50);
        self::assertSame('partial', $report['status']);
        self::assertSame(1, $report['records_failed']);
        self::assertSame(1, $this->rowCount('plays'));
        self::assertSame('unrepresentable_value', $this->db->query('SELECT code FROM ingestion_issues')->fetchColumn());
        self::assertSame($response->body, $this->raw->readVerified($report['raw_paths'][0],hash('sha256',$response->body)));
    }

    public function testMalformedCursorsCannotBeReportedAsAnExhaustedHistory(): void
    {
        $page = json_decode(Fixtures::body());
        $page->cursors = 'invalid-structure';
        $report = $this->collector([new Response(200, json_encode($page, JSON_THROW_ON_ERROR))])->collect('probe', 5, 50);
        self::assertSame('failed', $report['status']);
        self::assertSame(1, $this->rowCount('raw_artifacts'));
        self::assertSame(3, $this->rowCount('plays'));
        self::assertStringContainsString('cursors incompatível', $report['error']);
    }
}
