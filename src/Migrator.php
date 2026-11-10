<?php
declare(strict_types=1);

namespace ListenLab;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $db, private readonly string $directory)
    {
    }

    public function migrate(): array
    {
        $this->db->exec('SELECT pg_advisory_lock(194701, 1)');
        try {
            $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version text PRIMARY KEY, sha256 char(64) NOT NULL, applied_at timestamptz NOT NULL DEFAULT now())');
            $applied = [];
            $files = glob($this->directory . '/*.sql');
            sort($files);
            foreach ($files as $file) {
                $version = basename($file);
                $hash = hash_file('sha256', $file);
                $previous = Database::query($this->db, 'SELECT sha256 FROM schema_migrations WHERE version = ?', [$version])->fetchColumn();
                if ($previous !== false) {
                    if (!hash_equals($previous, $hash)) {
                        throw new AppError('Migration já aplicada foi alterada: ' . $version . '. Crie outra migration.');
                    }
                    continue;
                }
                $this->db->beginTransaction();
                try {
                    $this->db->exec(file_get_contents($file));
                    Database::query($this->db, 'INSERT INTO schema_migrations(version, sha256) VALUES (?, ?)', [$version, $hash]);
                    $this->db->commit();
                } catch (\Throwable $error) {
                    $this->db->rollBack();
                    throw $error;
                }
                $applied[] = $version;
            }
            return $applied;
        } finally {
            $this->db->exec('SELECT pg_advisory_unlock(194701, 1)');
        }
    }
}
