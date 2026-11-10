<?php
declare(strict_types=1);

namespace ListenLab\Ingestion;

use ListenLab\Database;
use PDO;

final class Batches
{
    public readonly int $sourceId;

    public function __construct(private readonly PDO $db)
    {
        $this->sourceId = (int) $db->query("SELECT id FROM data_sources WHERE code = 'spotify_api'")->fetchColumn();
        if ($this->sourceId === 0) {
            throw new \ListenLab\AppError('Fonte spotify_api ausente. Execute db:migrate.');
        }
    }

    public function start(string $operation): int
    {
        return (int) Database::query($this->db, 'INSERT INTO ingestion_batches(source_id, operation) VALUES (?, ?) RETURNING id', [$this->sourceId, $operation])->fetchColumn();
    }

    public function progress(int $batchId, Summary $summary, string $operation, string $status, string $stopReason, ?string $error = null): array
    {
        $report = $summary->report($batchId, $operation, $status, $stopReason);
        if ($error !== null) {
            $report['error'] = $error;
        }
        Database::query($this->db, 'UPDATE ingestion_batches SET status = ?, finished_at = CASE WHEN ? THEN now() ELSE NULL END,
            records_received = ?, records_processed = ?, records_inserted = ?, records_skipped = ?, records_failed = ?, plays_created = ?, metadata = CAST(? AS jsonb) WHERE id = ?', [
            $status, $status !== 'running', $summary->received, $summary->processed, $summary->inserted,
            $summary->existing, $summary->failed, $summary->inserted, json_encode($report, JSON_THROW_ON_ERROR), $batchId,
        ]);
        return $report;
    }

    public function issue(int $batchId, string $code, string $message, ?int $artifactId = null, ?int $recordId = null): void
    {
        Database::query($this->db, 'INSERT INTO ingestion_issues(batch_id, raw_artifact_id, ingestion_record_id, code, message) VALUES (?, ?, ?, ?, ?)', [$batchId, $artifactId, $recordId, $code, $message]);
    }
}
