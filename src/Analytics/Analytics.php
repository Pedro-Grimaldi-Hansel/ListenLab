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
        $summary = $this->summary($selected, $params, $period->timezone->getName());
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
        }
        $comparison = $this->comparison($period, $dataset, $summary);
        $rankings = ['tracks' => $this->rank($tracks, $total), 'artists' => $this->rank($artists, $total), 'albums' => $this->rank($albums, $total)];
        foreach ($rankings as $type => &$rows) $rows = $this->details($type, $rows, $comparison);
        unset($rows);
        return [
            'server_time' => gmdate(DATE_ATOM), 'timezone' => $period->timezone->getName(), 'period' => $period->describe(),
            'dataset' => $dataset, 'summary' => $summary, 'comparison' => $comparison,
            'rankings' => $rankings,
            'distribution' => [
                'hours' => $this->buckets($hours, range(0, 23), $total),
                'weekdays' => $this->buckets($weekdays, range(1, 7), $total),
                'daily' => $this->daily($period, $selected, $params, $summary),
                'release_decades' => $this->releaseDecades($selected, $params, $total),
            ],
        ];
    }

    private function summary(string $selected, array $params, string $timezone): array
    {
        $summary = Database::query($this->db, $selected . 'SELECT count(*) AS plays,count(DISTINCT p.track_id) AS tracks,
            count(DISTINCT t.album_id) AS albums,min(p.played_at) AS first_play_at,max(p.played_at) AS last_play_at,
            count(DISTINCT (p.played_at AT TIME ZONE ?)::date) AS active_days,
            count(*) FILTER (WHERE t.album_id IS NULL) AS plays_without_album,
            count(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM track_artists ta WHERE ta.track_id=p.track_id)) AS plays_without_artist,
            (SELECT count(DISTINCT ta.artist_id) FROM selected s JOIN track_artists ta ON ta.track_id=s.track_id) AS artists
            FROM selected p JOIN tracks t ON t.id=p.track_id', [...$params, $timezone])->fetch(PDO::FETCH_ASSOC);
        foreach (['plays', 'tracks', 'artists', 'albums', 'active_days', 'plays_without_album', 'plays_without_artist'] as $key) $summary[$key] = (int) $summary[$key];
        $summary['plays_per_active_day'] = $summary['active_days'] ? round($summary['plays'] / $summary['active_days'], 2) : null;
        // EXISTS counts a collaboration only once, even when multiple Top 10 artists are credited.
        $concentration = Database::query($this->db, $selected . ', top_artists AS (
            SELECT a.id,count(DISTINCT p.id) AS plays FROM selected p
            JOIN track_artists ta ON ta.track_id=p.track_id JOIN artists a ON a.id=ta.artist_id
            GROUP BY a.id,a.name ORDER BY plays DESC,lower(COALESCE(a.name,\'\')),a.id LIMIT 10)
            SELECT count(*) AS plays,(SELECT count(*) FROM top_artists) AS artists FROM selected p
            WHERE EXISTS (SELECT 1 FROM track_artists ta JOIN top_artists a ON a.id=ta.artist_id WHERE ta.track_id=p.track_id)', $params)->fetch(PDO::FETCH_ASSOC);
        $summary['top_artists_plays'] = (int) $concentration['plays'];
        $summary['top_artists_count'] = (int) $concentration['artists'];
        $summary['top_artists_share_pct'] = $summary['plays'] ? $this->share($summary['top_artists_plays'], $summary['plays']) : null;
        foreach (['first_play_at', 'last_play_at'] as $key) $summary[$key] = $this->date($summary[$key]);
        return $summary;
    }

    private function comparison(Period $period, array $dataset, array $summary): array
    {
        $comparison = $period->comparison() + ['available' => false, 'previous' => null, 'deltas' => []];
        if ($comparison['status'] !== 'ready') return $comparison;
        $firstDay = $dataset['first_play_at'] ? (new DateTimeImmutable($dataset['first_play_at']))->setTimezone($period->timezone)->format('Y-m-d') : null;
        if ($firstDay === null || $firstDay > $comparison['from']) {
            $comparison['status'] = 'insufficient_history';
            return $comparison;
        }
        $selected = 'WITH selected AS (SELECT p.id,p.track_id,p.played_at FROM plays p WHERE p.played_at >= ?::timestamptz AND p.played_at < ?::timestamptz) ';
        $previous = $this->summary($selected, [$comparison['from_at'], $comparison['until_at']], $period->timezone->getName());
        $comparison['status'] = 'available';
        $comparison['available'] = true;
        $comparison['previous'] = $previous;
        foreach (['plays', 'tracks', 'artists', 'albums', 'plays_per_active_day', 'top_artists_share_pct'] as $metric) {
            $before = $previous[$metric];
            $current = $summary[$metric];
            $comparison['deltas'][$metric] = [
                'previous' => $before,
                'change_pct' => $before !== null && $before > 0 && $current !== null ? round(($current - $before) / $before * 100, 1) : null,
                'change_pp' => $metric === 'top_artists_share_pct' && $before !== null && $current !== null ? round($current - $before, 1) : null,
            ];
        }
        return $comparison;
    }

    private function details(string $type, array $rows, array $comparison): array
    {
        if (!$rows) return [];
        $ids = array_column($rows, 'id');
        $slots = implode(',', array_fill(0, count($ids), '?'));
        [$identity, $joins] = match ($type) {
            'tracks' => ['t', 'JOIN tracks t ON t.id=p.track_id'],
            'artists' => ['a', 'JOIN track_artists ta ON ta.track_id=p.track_id JOIN artists a ON a.id=ta.artist_id'],
            'albums' => ['al', 'JOIN tracks t ON t.id=p.track_id JOIN albums al ON al.id=t.album_id'],
        };
        $dates = Database::query($this->db, "SELECT {$identity}.id,min(p.played_at) AS first_play_at,max(p.played_at) AS last_play_at
            FROM plays p {$joins} WHERE {$identity}.id IN ({$slots}) GROUP BY {$identity}.id", $ids)->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $previousRanks = [];
        if ($comparison['available']) {
            // Rank the entire previous selection before filtering to the currently displayed identities.
            $previousRanks = Database::query($this->db, "WITH counts AS (
                SELECT {$identity}.id,{$identity}.name,count(DISTINCT p.id) AS plays FROM plays p {$joins}
                WHERE p.played_at >= ?::timestamptz AND p.played_at < ?::timestamptz GROUP BY {$identity}.id,{$identity}.name),
                ranked AS (SELECT id,row_number() OVER (ORDER BY plays DESC,lower(COALESCE(name,'')),id) AS rank FROM counts)
                SELECT id,rank FROM ranked WHERE id IN ({$slots})", [$comparison['from_at'], $comparison['until_at'], ...$ids])->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        foreach ($rows as &$row) {
            $row['first_play_at'] = $this->date($dates[$row['id']]['first_play_at']);
            $row['last_play_at'] = $this->date($dates[$row['id']]['last_play_at']);
            $row['previous_rank'] = isset($previousRanks[$row['id']]) ? (int) $previousRanks[$row['id']] : null;
            $row['rank_change'] = $comparison['available'] && $row['previous_rank'] !== null ? $row['previous_rank'] - $row['rank'] : null;
            $row['change_status'] = !$comparison['available'] ? 'unavailable' : ($row['previous_rank'] === null ? 'new'
                : ($row['rank_change'] > 0 ? 'up' : ($row['rank_change'] < 0 ? 'down' : 'same')));
        }
        return $rows;
    }

    private function releaseDecades(string $selected, array $params, int $total): array
    {
        $rows = Database::query($this->db, $selected . "SELECT CASE
            WHEN al.release_date ~ '^[0-9]{4}($|-)' AND left(al.release_date,4) <> '0000'
            THEN left(al.release_date,4)::int / 10 * 10 ELSE NULL END AS decade,count(*) AS plays
            FROM selected p JOIN tracks t ON t.id=p.track_id LEFT JOIN albums al ON al.id=t.album_id
            GROUP BY 1 ORDER BY decade NULLS LAST", $params)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['decade'] = $row['decade'] === null ? null : (int) $row['decade'];
            $row['plays'] = (int) $row['plays'];
            $row['share_pct'] = $this->share($row['plays'], $total);
        }
        return $rows;
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
