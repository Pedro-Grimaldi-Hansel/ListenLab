<?php
declare(strict_types=1);

namespace ListenLab\Scheduling;

use ListenLab\AppError;
use ListenLab\Config;
use PDO;

final class Scheduler
{
    private bool $stopping = false;

    public function __construct(private readonly PDO $db, private readonly Config $config)
    {
    }

    /** The optional cycle limit/command allow isolated lifecycle tests, without Spotify. */
    public function run(?int $maxCycles = null, ?array $command = null): void
    {
        $interval = $this->config->integer('SYNC_INTERVAL_SECONDS', 900, 15, 86400);
        $locked = $this->db->query('SELECT pg_try_advisory_lock(194701, 3)')->fetchColumn();
        if (!in_array($locked, [true, 't', 1, '1'], true)) {
            throw new AppError('Já existe um agendador ativo para este banco.');
        }
        $runtime = new Runtime($this->db);
        $instance = bin2hex(random_bytes(16));
        $started = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void { $this->stopping = true; });
            pcntl_signal(SIGINT, function (): void { $this->stopping = true; });
        }
        try {
            $runtime->start($instance, $interval);
            $started = true;
            $cycles = 0;
            while (!$this->stopping) {
                $cycleStart = time();
                $runtime->collecting($instance);
                $exit = $this->child($command ?? [PHP_BINARY, $this->config->root . '/app', 'spotify:sync', '--no-ansi'], $runtime, $instance);
                fwrite(STDOUT, 'scheduler sync_exit=' . $exit . "\n");
                $next = max(time(), $cycleStart + $interval);
                $runtime->waiting($instance, $next, $exit);
                if ($command === null && !$this->stopping && $this->config->get('RAW_MAINTENANCE_ENABLED','1') === '1') {
                    $maintenanceExit=$this->child([PHP_BINARY,$this->config->root.'/app','raw:maintain','--apply','--archive','--if-due','--no-ansi'],$runtime,$instance);
                    fwrite(STDOUT,'scheduler maintenance_exit='.$maintenanceExit."\n");
                }
                if ($this->stopping || ($maxCycles !== null && ++$cycles >= $maxCycles)) {
                    break;
                }
                $lastHeartbeat = time();
                while (!$this->stopping && time() < $next) {
                    sleep(1);
                    if (time() - $lastHeartbeat >= 5) {
                        $runtime->heartbeat($instance);
                        $lastHeartbeat = time();
                    }
                }
            }
        } finally {
            try {
                if ($started) {
                    $runtime->stop($instance);
                }
            } finally {
                $this->db->exec('SELECT pg_advisory_unlock(194701, 3)');
            }
        }
    }

    private function child(array $command, Runtime $runtime, string $instance): int
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $this->config->root);
        if (!is_resource($process)) {
            throw new AppError('Não foi possível iniciar o comando de sincronização.');
        }
        $lastHeartbeat = time();
        $terminated = null;
        try {
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    return $status['exitcode'];
                }
                if ($this->stopping && $terminated === null) {
                    proc_terminate($process);
                    $terminated = time();
                } elseif ($terminated !== null && time() - $terminated > 10) {
                    proc_terminate($process, 9);
                }
                if (time() - $lastHeartbeat >= 5) {
                    $runtime->heartbeat($instance);
                    $lastHeartbeat = time();
                }
                usleep(250000);
            }
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }
}
