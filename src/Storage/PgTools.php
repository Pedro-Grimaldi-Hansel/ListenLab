<?php
declare(strict_types=1);

namespace ListenLab\Storage;

use ListenLab\AppError;
use ListenLab\Config;

final class PgTools
{
    public static function run(Config $config, string $program, array $arguments): void
    {
        $environment = array_merge(getenv(), [
            'PGPASSWORD' => $config->required('POSTGRES_PASSWORD'),
            'PGCONNECT_TIMEOUT' => '10', 'PGOPTIONS' => '-c timezone=UTC',
        ]);
        $connection = ['--host', $config->get('DB_HOST','127.0.0.1'), '--port', $config->get('DB_PORT','5432'),
            '--username', $config->get('POSTGRES_USER','listenlab')];
        $error = tmpfile(); $output = tmpfile();
        if (!$error || !$output) throw new AppError('Não foi possível abrir os logs privados do backup.');
        $oldMask = umask(0077);
        try {
            $process = proc_open([$program, ...$connection, ...$arguments], [0 => ['file','/dev/null','r'],1 => $output,2 => $error], $pipes, null, $environment);
            if (!is_resource($process)) throw new AppError('Não foi possível iniciar a ferramenta PostgreSQL do backup.');
            $deadline = microtime(true) + 600;
            try {
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) break;
                    if (microtime(true) > $deadline) { proc_terminate($process); throw new AppError('Backup excedeu o tempo limite; nenhuma cópia RAW será removida.'); }
                    usleep(100000);
                } while (true);
                if ($status['exitcode'] !== 0) throw new AppError($program . ' falhou (código ' . $status['exitcode'] . '); backup não aprovado e RAW preservado.');
            } finally { if (proc_get_status($process)['running']) proc_terminate($process,9); proc_close($process); }
        } finally { umask($oldMask); fclose($error); fclose($output); }
    }
}
