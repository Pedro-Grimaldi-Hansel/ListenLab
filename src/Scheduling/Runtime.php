<?php
declare(strict_types=1);

namespace ListenLab\Scheduling;

use ListenLab\Database;
use PDO;

final class Runtime
{
    public const NAME = 'spotify';
    public const STALE_SECONDS = 20;

    public function __construct(private readonly PDO $db)
    {
    }

    public function start(string $instance, int $interval): void
    {
        Database::query($this->db, "INSERT INTO scheduler_runtime(name,instance_id,state,interval_seconds,started_at,heartbeat_at)
            VALUES (?,?,'waiting',?,clock_timestamp(),clock_timestamp())
            ON CONFLICT(name) DO UPDATE SET instance_id=EXCLUDED.instance_id, state='waiting',
            interval_seconds=EXCLUDED.interval_seconds, started_at=EXCLUDED.started_at, heartbeat_at=EXCLUDED.heartbeat_at,
            cycle_started_at=NULL, next_run_at=NULL, last_exit_code=NULL, stopped_at=NULL", [self::NAME, $instance, $interval]);
    }

    public function collecting(string $instance): void
    {
        Database::query($this->db, "UPDATE scheduler_runtime SET state='collecting', heartbeat_at=clock_timestamp(),
            cycle_started_at=clock_timestamp(),next_run_at=NULL WHERE name=? AND instance_id=?", [self::NAME, $instance]);
    }

    public function waiting(string $instance, int $nextRun, int $exit): void
    {
        Database::query($this->db, "UPDATE scheduler_runtime SET state='waiting', heartbeat_at=clock_timestamp(),
            next_run_at=to_timestamp(?),last_exit_code=? WHERE name=? AND instance_id=?", [$nextRun, $exit, self::NAME, $instance]);
    }

    public function heartbeat(string $instance): void
    {
        Database::query($this->db, 'UPDATE scheduler_runtime SET heartbeat_at=clock_timestamp() WHERE name=? AND instance_id=?', [self::NAME, $instance]);
    }

    public function stop(string $instance): void
    {
        Database::query($this->db, "UPDATE scheduler_runtime SET state='stopped', heartbeat_at=clock_timestamp(),
            stopped_at=clock_timestamp(), next_run_at=NULL WHERE name=? AND instance_id=?", [self::NAME, $instance]);
    }

    public function snapshot(int $configuredInterval, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $row = Database::query($this->db, 'SELECT state,interval_seconds,started_at,heartbeat_at,cycle_started_at,next_run_at,last_exit_code,stopped_at FROM scheduler_runtime WHERE name=?', [self::NAME])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'unknown', 'interval_seconds' => $configuredInterval, 'heartbeat_at' => null, 'next_run_at' => null, 'cycle_started_at' => null, 'last_exit_code' => null];
        }
        foreach (['started_at','heartbeat_at','cycle_started_at','next_run_at','stopped_at'] as $key) {
            $row[$key] = $row[$key] === null ? null : (new \DateTimeImmutable($row[$key]))->format(DATE_ATOM);
        }
        $age = max(0, $now->getTimestamp() - (new \DateTimeImmutable($row['heartbeat_at']))->getTimestamp());
        $status = $row['state'] === 'stopped' ? 'stopped' : ($age > self::STALE_SECONDS ? 'stale' : $row['state']);
        if ($status === 'waiting' && $row['next_run_at'] !== null && $now->getTimestamp() - strtotime($row['next_run_at']) > self::STALE_SECONDS) {
            $status = 'delayed';
        }
        $row['status'] = $status;
        $row['heartbeat_age_seconds'] = $age;
        if (!in_array($status, ['waiting', 'delayed'], true)) {
            $row['next_run_at'] = null;
        }
        return $row;
    }
}
