<?php
declare(strict_types=1);

namespace ListenLab\Ingestion;

use ListenLab\Database;
use PDO;

final class Catalog
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function persist(ParsedPlay $play, string $observedAt): int
    {
        $albumId = $play->album !== null ? $this->upsert('albums', $play->album, $observedAt)['id'] : null;
        $track = $this->upsert('tracks', $play->track + ['album_id' => $albumId], $observedAt);
        $trackId = (int) $track['id'];
        if ($play->artists !== null && new \DateTimeImmutable($track['observed_at']) <= new \DateTimeImmutable($observedAt)) {
            Database::query($this->db, 'DELETE FROM track_artists WHERE track_id = ?', [$trackId]);
            foreach ($play->artists as $position => $artist) {
                $artistId = $this->upsert('artists', $artist, $observedAt)['id'];
                Database::query($this->db, 'INSERT INTO track_artists(track_id, artist_id, position) VALUES (?, ?, ?)', [$trackId, $artistId, $position]);
            }
        }
        return $trackId;
    }

    private function upsert(string $table, array $fields, string $observedAt): array
    {
        // Identifiers here are hard-coded by this class, never taken from a payload.
        $fields['observed_at'] = $observedAt;
        $names = array_keys($fields);
        $updates = [];
        foreach ($names as $name) {
            if (in_array($name, ['external_id','observed_at'], true)) {
                continue;
            }
            $expression = "COALESCE(EXCLUDED.{$name}, {$table}.{$name})";
            if ($table === 'albums' && $name === 'release_date_precision') {
                $expression = 'CASE WHEN EXCLUDED.release_date IS NOT NULL THEN EXCLUDED.release_date_precision ELSE albums.release_date_precision END';
            }
            $updates[] = "{$name} = CASE WHEN EXCLUDED.observed_at >= {$table}.observed_at THEN {$expression} ELSE {$table}.{$name} END";
        }
        $updates[] = "observed_at = GREATEST(EXCLUDED.observed_at, {$table}.observed_at)";
        $updates[] = 'updated_at = now()';
        $sql = 'INSERT INTO ' . $table . '(' . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ') '
            . 'ON CONFLICT (provider, external_id) DO UPDATE SET ' . implode(',', $updates) . ' RETURNING id, observed_at';
        return Database::query($this->db, $sql, array_values($fields))->fetch(PDO::FETCH_ASSOC);
    }
}
