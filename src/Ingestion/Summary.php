<?php
declare(strict_types=1);

namespace ListenLab\Ingestion;

final class Summary
{
    public int $received = 0;
    public int $processed = 0;
    public int $inserted = 0;
    public int $existing = 0;
    public int $failed = 0;
    public int $partial = 0;
    public int $pages = 0;
    public int $responses = 0;
    public ?\DateTimeImmutable $oldest = null;
    public ?\DateTimeImmutable $newest = null;
    public array $rawPaths = [];
    private array $observedKeys = [];

    public function observeKey(string $key): void
    {
        $this->observedKeys[$key] = true;
    }

    public function observe(\DateTimeImmutable $date): void
    {
        $this->oldest = $this->oldest === null || $date < $this->oldest ? $date : $this->oldest;
        $this->newest = $this->newest === null || $date > $this->newest ? $date : $this->newest;
    }

    public function report(int $batchId, string $operation, string $status, string $stopReason): array
    {
        return [
            'batch_id' => $batchId, 'operation' => $operation, 'status' => $status,
            'stop_reason' => $stopReason,
            'api_records_received' => $this->received, 'new_source_records' => $this->inserted,
            'unique_source_records_observed' => count($this->observedKeys),
            'already_ingested' => $this->existing, 'plays_created' => $this->inserted,
            'records_processed' => $this->processed, 'records_failed' => $this->failed,
            'partially_processed' => $this->partial, 'pages' => $this->pages, 'http_responses' => $this->responses,
            'newest' => $this->newest?->format('Y-m-d\TH:i:s.u\Z'),
            'oldest' => $this->oldest?->format('Y-m-d\TH:i:s.u\Z'),
            'covered_seconds' => $this->oldest !== null && $this->newest !== null ? (float) $this->newest->format('U.u') - (float) $this->oldest->format('U.u') : null,
            'raw_paths' => $this->rawPaths,
            'normalizer_version' => RecentlyPlayedParser::VERSION, 'source_key_version' => RecentlyPlayedParser::KEY_VERSION,
        ];
    }
}
