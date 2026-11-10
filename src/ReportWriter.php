<?php
declare(strict_types=1);

namespace ListenLab;

final class ReportWriter
{
    public function __construct(private readonly string $directory)
    {
    }

    public function write(array $report): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new AppError('Ingestão registrada no banco, mas o diretório de relatórios não pôde ser criado.');
        }
        $path = $this->directory . '/batch-' . $report['batch_id'] . '.json';
        $bytes = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new AppError('Ingestão registrada no banco, mas o relatório não pôde ser salvo.');
        }
        chmod($path, 0600);
        return $path;
    }
}
