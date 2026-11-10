<?php
declare(strict_types=1);

namespace ListenLab;

use PDO;

final class History
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function recent(int $limit): array
    {
        return Database::query($this->db, "SELECT p.id, p.played_at, t.name AS track, a.names AS artists, al.name AS album,
                s.sources AS source, t.external_id AS track_id, t.external_uri AS track_uri, t.duration_ms, t.is_local,
                p.context_type, p.context_uri, p.ms_played, p.skipped
            FROM plays p
            JOIN tracks t ON t.id = p.track_id
            LEFT JOIN albums al ON al.id = t.album_id
            LEFT JOIN LATERAL (
                SELECT string_agg(ar.name, ', ' ORDER BY ta.position) AS names
                FROM track_artists ta JOIN artists ar ON ar.id = ta.artist_id WHERE ta.track_id = t.id
            ) a ON true
            LEFT JOIN LATERAL (
                SELECT string_agg(DISTINCT ds.code, ', ' ORDER BY ds.code) AS sources
                FROM play_evidence pe JOIN data_sources ds ON ds.id = pe.source_id WHERE pe.play_id = p.id
            ) s ON true
            ORDER BY p.played_at DESC, p.id DESC LIMIT ?", [$limit])->fetchAll(PDO::FETCH_ASSOC);
    }
}
