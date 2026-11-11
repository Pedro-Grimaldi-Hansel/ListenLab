<?php
declare(strict_types=1);

namespace ListenLab\Analytics;

use DateTimeImmutable;
use ListenLab\Database;
use PDO;

final class Analytics
{
    public function __construct(private readonly PDO $db) {}

    /** The caller provides one REPEATABLE READ, READ ONLY snapshot for all panels. */
    public function overview(Period $period): array
    {
        [$where, $params] = $period->selection();
        $selected = 'WITH selected AS (SELECT p.id,p.track_id,p.played_at FROM plays p WHERE ' . $where . ') ';
        $dataset = $this->db->query('SELECT count(*) AS plays,min(played_at) AS first_play_at,max(played_at) AS last_play_at FROM plays')->fetch(PDO::FETCH_ASSOC);
        $summary = Database::query($this->db, $selected . 'SELECT count(*) AS plays,count(DISTINCT p.track_id) AS tracks,
            count(DISTINCT t.album_id) AS albums,min(p.played_at) AS first_play_at,max(p.played_at) AS last_play_at,
            count(*) FILTER (WHERE t.album_id IS NULL) AS plays_without_album,
            count(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM track_artists ta WHERE ta.track_id=p.track_id)) AS plays_without_artist,
            (SELECT count(DISTINCT ta.artist_id) FROM selected s JOIN track_artists ta ON ta.track_id=s.track_id) AS artists
            FROM selected p JOIN tracks t ON t.id=p.track_id', $params)->fetch(PDO::FETCH_ASSOC);
        foreach (['plays', 'tracks', 'artists', 'albums', 'plays_without_album', 'plays_without_artist'] as $key) $summary[$key] = (int) $summary[$key];
        $total = $summary['plays'];
        $tracks = Database::query($this->db, $selected . ', counts AS (SELECT track_id,count(*) AS plays FROM selected GROUP BY track_id)
            SELECT t.id,t.name,al.name AS album,c.plays,
                (SELECT string_agg(COALESCE(NULLIF(a.name,\'\'),\'Artista sem nome\'),\', \' ORDER BY ta.position)
                 FROM track_artists ta JOIN artists a ON a.id=ta.artist_id WHERE ta.track_id=t.id) AS artists
            FROM counts c JOIN tracks t ON t.id=c.track_id LEFT JOIN albums al ON al.id=t.album_id
            ORDER BY c.plays DESC,lower(COALESCE(t.name,\'\')),t.id LIMIT ?', [...$params, $period->limit])->fetchAll(PDO::FETCH_ASSOC);
        $artists = Database::query($this->db, $selected . 'SELECT a.id,a.name,count(DISTINCT p.id) AS plays
            FROM selected p JOIN track_artists ta ON ta.track_id=p.track_id JOIN artists a ON a.id=ta.artist_id
            GROUP BY a.id,a.name ORDER BY plays DESC,lower(COALESCE(a.name,\'\')),a.id LIMIT ?', [...$params, $period->limit])->fetchAll(PDO::FETCH_ASSOC);
        $albums = Database::query($this->db, $selected . 'SELECT al.id,al.name,count(*) AS plays
            FROM selected p JOIN tracks t ON t.id=p.track_id JOIN albums al ON al.id=t.album_id
            GROUP BY al.id,al.name ORDER BY plays DESC,lower(COALESCE(al.name,\'\')),al.id LIMIT ?', [...$params, $period->limit])->fetchAll(PDO::FETCH_ASSOC);
        $hours = Database::query($this->db, $selected . 'SELECT extract(hour FROM played_at AT TIME ZONE ?)::int AS bucket,count(*) AS plays
            FROM selected GROUP BY 1 ORDER BY 1', [...$params, $period->timezone->getName()])->fetchAll(PDO::FETCH_KEY_PAIR);
        $weekdays = Database::query($this->db, $selected . 'SELECT extract(isodow FROM played_at AT TIME ZONE ?)::int AS bucket,count(*) AS plays
            FROM selected GROUP BY 1 ORDER BY 1', [...$params, $period->timezone->getName()])->fetchAll(PDO::FETCH_KEY_PAIR);
        $dataset['plays'] = (int) $dataset['plays'];
        foreach (['first_play_at', 'last_play_at'] as $key) {
            $dataset[$key] = $this->date($dataset[$key]);
            $summary[$key] = $this->date($summary[$key]);
        }
        return [
            'server_time' => gmdate(DATE_ATOM), 'timezone' => $period->timezone->getName(), 'period' => $period->describe(),
            'dataset' => $dataset, 'summary' => $summary,
            'rankings' => ['tracks' => $this->rank($tracks, $total), 'artists' => $this->rank($artists, $total), 'albums' => $this->rank($albums, $total)],
            'distribution' => [
                'hours' => $this->buckets($hours, range(0, 23), $total),
                'weekdays' => $this->buckets($weekdays, range(1, 7), $total),
                'daily' => $this->daily($period, $selected, $params, $summary),
            ],
        ];
    }

    private function daily(Period $period, string $selected, array $params, array $summary): array
    {
        $from = $period->from ?? ($summary['first_play_at'] ? (new DateTimeImmutable($summary['first_play_at']))->setTimezone($period->timezone)->setTime(0, 0) : null);
        $until = $period->until ?? ($summary['last_play_at'] ? (new DateTimeImmutable($summary['last_play_at']))->setTimezone($period->timezone)->setTime(0, 0)->modify('+1 day')->setTime(0, 0) : null);
        if (!$from || !$until) return ['from' => null, 'to' => null, 'truncated' => false, 'items' => []];
        // Bound the calendar chart independently; rankings/hour/weekday still use the complete selection.
        $window = $until->modify('-90 days')->setTime(0, 0);
        $truncated = $from < $window;
        if ($truncated) $from = $window;
        $rows = Database::query($this->db, $selected . "SELECT to_char(played_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day,count(*) AS plays
            FROM selected WHERE played_at >= ?::timestamptz AND played_at < ?::timestamptz GROUP BY 1 ORDER BY 1",
            [...$params, $period->timezone->getName(), Period::utc($from), Period::utc($until)])->fetchAll(PDO::FETCH_KEY_PAIR);
        $items = [];
        for ($day = $from; $day < $until; $day = $day->modify('+1 day')->setTime(0, 0)) {
            $label = $day->format('Y-m-d');
            $items[] = ['date' => $label, 'plays' => (int) ($rows[$label] ?? 0)];
        }
        return ['from' => $from->format('Y-m-d'), 'to' => $until->modify('-1 day')->format('Y-m-d'), 'truncated' => $truncated, 'items' => $items];
    }

    private function rank(array $rows, int $total): array
    {
        foreach ($rows as $index => &$row) {
            $row['id'] = (int) $row['id'];
            $row['rank'] = $index + 1;
            $row['plays'] = (int) $row['plays'];
            $row['share_pct'] = $this->share($row['plays'], $total);
        }
        return $rows;
    }

    private function buckets(array $counts, array $keys, int $total): array
    {
        return array_map(fn (int $key): array => ['bucket' => $key, 'plays' => (int) ($counts[$key] ?? 0), 'share_pct' => $this->share((int) ($counts[$key] ?? 0), $total)], $keys);
    }

    private function share(int $count, int $total): float { return $total > 0 ? round($count / $total * 100, 2) : 0.0; }
    private function date(?string $value): ?string { return $value === null ? null : Period::utc(new DateTimeImmutable($value)); }
}
