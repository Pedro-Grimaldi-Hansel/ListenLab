<?php
declare(strict_types=1);

namespace ListenLab\Monitoring;

use ListenLab\Config;
use ListenLab\Database;
use ListenLab\Scheduling\Runtime;
use ListenLab\Storage\RawStore;
use ListenLab\Storage\RawIndex;
use PDO;

final class Monitor
{
    public function __construct(private readonly PDO $db, private readonly Config $config)
    {
    }

    public function overview(): array
    {
        $interval = $this->config->integer('SYNC_INTERVAL_SECONDS', 900, 15, 86400);
        $latest = $this->db->query("SELECT b.*,ds.code AS source FROM ingestion_batches b JOIN data_sources ds ON ds.id=b.source_id
            WHERE b.operation='sync' ORDER BY b.started_at DESC,b.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $counts = $this->db->query("SELECT count(*) AS total, count(*) FILTER (WHERE status='completed') AS completed,
            count(*) FILTER (WHERE status='failed') AS failed, count(*) FILTER (WHERE status='partial') AS partial,
            count(*) FILTER (WHERE status='running') AS running FROM ingestion_batches
            WHERE started_at >= now() - interval '24 hours'")->fetch(PDO::FETCH_ASSOC);
        $raw = $this->db->query('SELECT count(*) AS files,COALESCE(sum(size_bytes),0) AS bytes FROM raw_artifacts')->fetch(PDO::FETCH_ASSOC);
        return [
            'server_time' => gmdate(DATE_ATOM), 'timezone' => $this->config->get('MONITOR_TIMEZONE','America/Sao_Paulo'),
            'demo' => $this->config->get('MONITOR_DEMO') === '1',
            'refresh_seconds' => 5, 'heartbeat_stale_seconds' => Runtime::STALE_SECONDS,
            'scheduler' => (new Runtime($this->db))->snapshot($interval),
            'latest_sync' => $latest ? $this->batch($latest) : null,
            'last_success_at' => $this->date($this->db->query("SELECT max(finished_at) FROM ingestion_batches WHERE operation='sync' AND status='completed'")->fetchColumn() ?: null),
            'today' => array_map(intval(...), $counts),
            'preserved' => ['plays' => (int) $this->db->query('SELECT count(*) FROM plays')->fetchColumn(), 'raw_files' => (int) $raw['files'], 'raw_bytes' => (int) $raw['bytes']],
            'storage' => $this->storage(),
        ];
    }

    public function batches(int $page = 1, string $status = 'all'): array
    {
        $where = $status === 'all' ? '' : ' WHERE b.status = ?';
        $params = $status === 'all' ? [] : [$status];
        $total = (int) Database::query($this->db, 'SELECT count(*) FROM ingestion_batches b' . $where, $params)->fetchColumn();
        $pages = max(1, (int) ceil($total / 15));
        $page = min($page, $pages);
        $rows = Database::query($this->db, 'SELECT b.*,ds.code AS source FROM ingestion_batches b JOIN data_sources ds ON ds.id=b.source_id' . $where
            . ' ORDER BY b.started_at DESC,b.id DESC LIMIT 15 OFFSET ?', [...$params, ($page - 1) * 15])->fetchAll(PDO::FETCH_ASSOC);
        return ['items' => array_map($this->batch(...), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function detail(int $id): ?array
    {
        $row = Database::query($this->db, 'SELECT b.*,ds.code AS source FROM ingestion_batches b JOIN data_sources ds ON ds.id=b.source_id WHERE b.id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $batch = $this->batch($row);
        // Only explicitly selected operational fields are exposed. No item payloads,
        // token files, credentials or arbitrary batch metadata reach the browser.
        $raw = Database::query($this->db, "SELECT a.id,a.path,a.sha256,a.size_bytes,a.received_at,a.http_status,
            COALESCE(o.tier,'legacy') AS storage_tier,COALESCE(o.encoding,'identity') AS encoding
            FROM raw_artifacts a LEFT JOIN raw_storage_refs r ON r.artifact_id=a.id
            LEFT JOIN raw_objects o ON o.sha256=r.sha256 WHERE a.batch_id=? ORDER BY a.id", [$id])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw as &$artifact) {
            $artifact['id'] = (int) $artifact['id'];
            $artifact['size_bytes'] = (int) $artifact['size_bytes'];
            $artifact['received_at'] = $this->date($artifact['received_at']);
        }
        unset($artifact);
        $issues = Database::query($this->db, 'SELECT code,message,created_at FROM ingestion_issues WHERE batch_id=? ORDER BY id LIMIT 100', [$id])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($issues as &$issue) {
            $issue['created_at'] = $this->date($issue['created_at']);
        }
        unset($issue);
        return $batch + ['artifacts' => $raw, 'issues' => $issues];
    }

    private function batch(array $row): array
    {
        $metadata = json_decode($row['metadata'], true) ?: [];
        $duration = $row['finished_at'] !== null
            ? max(0, (float) (new \DateTimeImmutable($row['finished_at']))->format('U.u') - (float) (new \DateTimeImmutable($row['started_at']))->format('U.u')) : null;
        return [
            'id' => (int) $row['id'], 'operation' => $row['operation'], 'source' => $row['source'], 'status' => $row['status'],
            'started_at' => $this->date($row['started_at']), 'finished_at' => $this->date($row['finished_at']), 'duration_seconds' => $duration,
            'received' => (int) $row['records_received'], 'new_records' => (int) $row['records_inserted'],
            'existing' => (int) $row['records_skipped'], 'failed' => (int) $row['records_failed'], 'plays_created' => (int) $row['plays_created'],
            'pages' => isset($metadata['pages']) ? (int) $metadata['pages'] : null,
            'stop_reason' => is_string($metadata['stop_reason'] ?? null) ? $metadata['stop_reason'] : null,
            'oldest' => $this->date($metadata['oldest'] ?? null), 'newest' => $this->date($metadata['newest'] ?? null),
        ];
    }

    private function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function storage(): array
    {
        $stats=(new RawIndex($this->db,new RawStore($this->config->path('RAW_STORAGE_PATH','storage/raw'))))->stats();
        $last=$this->db->query('SELECT status,operation,started_at,finished_at,backup_verified,artifacts,objects,local_bytes_before,local_bytes_after,archive_bytes,snapshot_count FROM raw_storage_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $backup=$this->db->query("SELECT max(finished_at) FROM raw_storage_runs WHERE status='completed' AND backup_verified=true")->fetchColumn();
        $measurement=$this->db->query("SELECT archive_bytes,snapshot_count,finished_at FROM raw_storage_runs WHERE status='completed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if($last){$last['started_at']=$this->date($last['started_at']);$last['finished_at']=$this->date($last['finished_at']);}
        return $stats+['maintenance'=>$last?:null,'last_verified_backup_at'=>$this->date($backup?:null),
            'archive_bytes'=>(int)($measurement['archive_bytes']??0),'snapshot_count'=>(int)($measurement['snapshot_count']??0),'measured_at'=>$this->date($measurement['finished_at']??null),
            'archive_after_days'=>$this->config->integer('RAW_ARCHIVE_AFTER_DAYS',30,0,36500),'maintenance_enabled'=>$this->config->get('RAW_MAINTENANCE_ENABLED','1')==='1',
            'backup_keep'=>$this->config->integer('RAW_BACKUP_KEEP',3,0,50),
            'backup_location'=>'private_local_folder'];
    }
}
