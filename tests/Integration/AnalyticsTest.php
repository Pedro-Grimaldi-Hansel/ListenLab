<?php
declare(strict_types=1);

namespace ListenLab\Tests\Integration;

use ListenLab\Analytics\Analytics;
use ListenLab\Analytics\Period;
use ListenLab\Config;
use ListenLab\Database;
use ListenLab\Ingestion\Batches;
use ListenLab\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnalyticsTest extends TestCase
{
    private PDO $db;
    private string $schema;

    protected function setUp(): void
    {
        $config = Config::load(dirname(__DIR__, 2));
        $name = $config->get('TEST_DB_NAME', 'listenlab_test');
        if (!preg_match('/^[a-zA-Z0-9_]+_test$/D', $name) || $name === $config->get('POSTGRES_DB', 'listenlab')) {
            throw new \RuntimeException('Analytics tests require a separate database ending in _test.');
        }
        $this->db = Database::connect($config, $name);
        $this->schema = 'analytics_' . bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA ' . $this->schema);
        $this->db->exec('SET search_path TO ' . $this->schema);
        (new Migrator($this->db, $config->root . '/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->schema)) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->db->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    public function testRankingsCountEventsOnceAndCreditEveryDistinctArtist(): void
    {
        $this->seed();
        $data = $this->view();
        self::assertSame(5, $data['summary']['plays']);
        self::assertSame(3, $data['summary']['tracks']);
        self::assertSame(2, $data['summary']['artists']);
        self::assertSame(1, $data['summary']['albums']);
        self::assertSame(1, $data['summary']['plays_without_album']);
        self::assertSame(1, $data['summary']['plays_without_artist']);
        self::assertSame([2, 2, 1], array_column($data['rankings']['tracks'], 'plays'));
        self::assertSame([1, 2, 3], array_column($data['rankings']['tracks'], 'id')); // Same names, different identities.
        self::assertSame([4, 2], array_column($data['rankings']['artists'], 'plays'));
        self::assertSame([80.0, 40.0], array_column($data['rankings']['artists'], 'share_pct'));
        self::assertSame(4, $data['rankings']['albums'][0]['plays']);
        self::assertSame(80.0, $data['rankings']['albums'][0]['share_pct']);
        self::assertSame(5, array_sum(array_column($data['distribution']['hours'], 'plays')));
        self::assertSame(5, array_sum(array_column($data['distribution']['weekdays'], 'plays')));
        self::assertSame(0, $data['distribution']['hours'][1]['plays']);
        self::assertSame(2, $data['distribution']['hours'][23]['plays']);
        self::assertSame([0, 1, 3, 0, 0, 0, 1], array_column($data['distribution']['weekdays'], 'plays'));
    }

    public function testLocalDatesIncludeBothEndpointsAndZeroFillGaps(): void
    {
        $this->seed();
        $data = $this->view(['period' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-11']);
        self::assertSame(4, $data['summary']['plays']);
        self::assertSame(5, $data['dataset']['plays']);
        self::assertSame('2026-11-11T02:59:59+00:00', $data['summary']['first_play_at']);
        self::assertSame('2026-11-12T02:59:59+00:00', $data['summary']['last_play_at']);
        self::assertSame([1, 3], array_column($data['distribution']['daily']['items'], 'plays'));
        $gap = $this->view(['period' => 'custom', 'from' => '2026-11-11', 'to' => '2026-11-15']);
        self::assertSame([3, 0, 0, 0, 1], array_column($gap['distribution']['daily']['items'], 'plays'));
        self::assertSame('2026-11-15', $gap['distribution']['daily']['to']);
        $utc = $this->view(['period' => 'custom', 'from' => '2026-11-11', 'to' => '2026-11-11'], 'UTC');
        self::assertSame(3, $utc['summary']['plays']);
        self::assertSame(1, $utc['distribution']['hours'][2]['plays']);
    }

    public function testEmptySelectionHasZeroBucketsAndNoFabricatedRankings(): void
    {
        $empty = $this->view();
        self::assertSame(0, $empty['summary']['plays']);
        self::assertNull($empty['dataset']['first_play_at']);
        self::assertSame(['tracks' => [], 'artists' => [], 'albums' => []], $empty['rankings']);
        self::assertCount(24, $empty['distribution']['hours']);
        self::assertCount(7, $empty['distribution']['weekdays']);
        self::assertSame(0, array_sum(array_column($empty['distribution']['hours'], 'plays')));
        self::assertSame(0.0, $empty['distribution']['hours'][0]['share_pct']);
        self::assertSame([], $empty['distribution']['daily']['items']);
        $this->seed();
        $selection = $this->view(['period' => 'custom', 'from' => '2026-12-01', 'to' => '2026-12-03']);
        self::assertSame(5, $selection['dataset']['plays']);
        self::assertSame(0, $selection['summary']['plays']);
        self::assertSame([0, 0, 0], array_column($selection['distribution']['daily']['items'], 'plays'));
    }

    public function testDailyWindowAndTopLimitNeverTruncateOtherPanels(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $track = Database::query($this->db, 'INSERT INTO tracks(name,observed_at) VALUES (?,now()) RETURNING id', [sprintf('Faixa sintética %02d', $i)])->fetchColumn();
            Database::query($this->db, "INSERT INTO plays(track_id,played_at,timestamp_semantics) VALUES (?,?,'synthetic')", [$track, $i === 1 ? '2020-01-01T12:00:00Z' : '2026-11-11T12:00:00Z']);
        }
        $data = $this->view();
        self::assertSame(12, $data['summary']['plays']);
        self::assertSame(12, $data['summary']['tracks']);
        self::assertCount(10, $data['rankings']['tracks']);
        self::assertSame(12, array_sum(array_column($data['distribution']['hours'], 'plays')));
        self::assertTrue($data['distribution']['daily']['truncated']);
        self::assertCount(90, $data['distribution']['daily']['items']);
        self::assertSame(11, array_sum(array_column($data['distribution']['daily']['items'], 'plays')));
        self::assertCount(12, $this->view(['limit' => '20'])['rankings']['tracks']);
    }

    public function testCalendarChartHandlesMidnightDaylightSavingTransition(): void
    {
        $track = $this->db->query('INSERT INTO tracks(observed_at) VALUES (now()) RETURNING id')->fetchColumn();
        foreach (['2018-11-04T03:00:00Z', '2018-11-05T01:59:59Z', '2018-11-05T02:00:00Z'] as $date) {
            Database::query($this->db, "INSERT INTO plays(track_id,played_at,timestamp_semantics) VALUES (?,?,'synthetic')", [$track, $date]);
        }
        $data = $this->view(['period' => 'custom', 'from' => '2018-11-04', 'to' => '2018-11-05']);
        self::assertSame([2, 1], array_column($data['distribution']['daily']['items'], 'plays'));
        self::assertSame(2, $this->view(['period' => 'custom', 'from' => '2018-11-04', 'to' => '2018-11-04'])['summary']['plays']);
    }

    private function view(array $query = [], string $timezone = 'America/Sao_Paulo'): array
    {
        $this->db->beginTransaction();
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        try { return (new Analytics($this->db))->overview(Period::fromQuery($query, $timezone)); }
        finally { $this->db->rollBack(); }
    }

    private function seed(): void
    {
        $this->db->exec("INSERT INTO artists(name,observed_at) VALUES ('Artista fictício A',now()),('Artista fictício B',now())");
        $this->db->exec("INSERT INTO albums(name,observed_at) VALUES ('Álbum fictício',now())");
        $this->db->exec("INSERT INTO tracks(name,album_id,observed_at) VALUES ('Mesma faixa',1,now()),('Mesma faixa',1,now()),(NULL,NULL,now())");
        $this->db->exec('INSERT INTO track_artists(track_id,artist_id,position) VALUES (1,1,0),(1,2,1),(1,1,2),(2,1,0)');
        foreach ([
            [1, '2026-11-11T02:59:59Z'], // Nov 10, 23:59:59 local.
            [1, '2026-11-11T03:00:00Z'], // Nov 11, 00:00 local.
            [2, '2026-11-11T12:00:00Z'],
            [2, '2026-11-12T02:59:59Z'],
            [3, '2026-11-15T12:00:00Z'],
        ] as [$track, $date]) Database::query($this->db, "INSERT INTO plays(track_id,played_at,timestamp_semantics,context_uri) VALUES (?,?,'synthetic','synthetic-private-context')", [$track, $date]);
        // Multiple receipts and even multiple pieces of evidence for one event must not inflate counts.
        $batch = (new Batches($this->db))->start('sync');
        $raw = Database::query($this->db, 'INSERT INTO raw_artifacts(batch_id,path,sha256,size_bytes,http_status) VALUES (?,?,?,?,200) RETURNING id', [$batch, 'synthetic/analytics.json', str_repeat('a', 64), 2])->fetchColumn();
        foreach ([0, 1, 2] as $index) {
            $record = Database::query($this->db, "INSERT INTO ingestion_records(raw_artifact_id,item_index,payload,processing_status) VALUES (?,?,'{\"secret\":\"synthetic-private-payload\"}', 'duplicate') RETURNING id", [$raw, $index])->fetchColumn();
            if ($index < 2) Database::query($this->db, "INSERT INTO play_evidence(play_id,source_id,source_record_key,first_ingestion_record_id,match_type) VALUES (1,1,?,?,'exact_source_key')", [hash('sha256', 'synthetic-analytics-' . $index), $record]);
        }
    }
}
