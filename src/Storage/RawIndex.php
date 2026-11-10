<?php
declare(strict_types=1);

namespace ListenLab\Storage;

use ListenLab\Database;
use PDO;

final class RawIndex
{
    public function __construct(private readonly PDO $db, private readonly RawStore $store) {}

    public function register(int $artifactId, string $path, string $hash, int $size, string $receivedAt): void
    {
        $info = $this->store->objectInfo($hash);
        Database::query($this->db, 'INSERT INTO raw_objects(sha256,size_bytes,stored_bytes,encoding,tier,first_received_at)
            VALUES (?,?,?,?,?,?) ON CONFLICT(sha256) DO UPDATE SET stored_bytes=EXCLUDED.stored_bytes,
            encoding=EXCLUDED.encoding,tier=EXCLUDED.tier,first_received_at=LEAST(raw_objects.first_received_at,EXCLUDED.first_received_at),updated_at=now()',
            [$hash,$size,$info['stored_bytes'],$info['encoding'],$info['tier'],$receivedAt]);
        Database::query($this->db, 'INSERT INTO raw_storage_refs(artifact_id,sha256,reference_bytes) VALUES (?,?,?)
            ON CONFLICT(artifact_id) DO UPDATE SET reference_bytes=EXCLUDED.reference_bytes', [$artifactId,$hash,$this->store->referenceBytes($path)]);
    }

    public function stats(): array
    {
        $row = $this->db->query("SELECT
            (SELECT count(*) FROM raw_artifacts) AS responses,
            (SELECT count(DISTINCT sha256) FROM raw_artifacts) AS unique_contents,
            (SELECT coalesce(sum(size_bytes),0) FROM raw_artifacts) AS logical_bytes,
            (SELECT coalesce(sum(a.size_bytes),0) FROM raw_artifacts a LEFT JOIN raw_storage_refs r ON r.artifact_id=a.id WHERE r.artifact_id IS NULL) AS legacy_bytes,
            (SELECT coalesce(sum(reference_bytes),0) FROM raw_storage_refs) AS reference_bytes,
            (SELECT coalesce(sum(stored_bytes) FILTER (WHERE tier='local'),0) FROM raw_objects) AS object_bytes,
            (SELECT coalesce(sum(stored_bytes) FILTER (WHERE tier='archive'),0) FROM raw_objects) AS archived_bytes,
            (SELECT count(*) FROM raw_objects WHERE encoding='gzip') AS compressed_contents,
            (SELECT count(*) FROM raw_objects WHERE tier='archive') AS archived_contents")->fetch(PDO::FETCH_ASSOC);
        $row = array_map(intval(...), $row);
        $row['local_bytes'] = $row['legacy_bytes'] + $row['reference_bytes'] + $row['object_bytes'];
        $row['saved_local_bytes'] = max(0, $row['logical_bytes'] - $row['local_bytes']);
        return $row;
    }
}
