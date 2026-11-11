<?php
declare(strict_types=1);

namespace ListenLab\Monitoring;

use ListenLab\AppError;
use ListenLab\Config;
use ListenLab\Database;
use PDO;

final class MonitorAccess
{
    private const MARKER = 'ListenLab managed monitor role v1';
    private const TABLES = [
        'data_sources', 'ingestion_batches', 'scheduler_runtime',
        'raw_objects', 'raw_storage_refs', 'raw_storage_runs', 'ingestion_issues',
    ];

    public function __construct(private readonly PDO $db, private readonly Config $config) {}

    /** Administrative CLI operation; it never uses or changes the owner's password. */
    public function provision(): array
    {
        $role = $this->config->get('MONITOR_DB_USER', 'listenlab_monitor');
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $role)) throw new AppError('MONITOR_DB_USER inválido.');
        if ($this->db->inTransaction()) throw new AppError('Provisione o monitor fora de uma transação.');
        $this->db->exec('SELECT pg_advisory_lock(194701, 4)');
        try {
            $existing = Database::query($this->db, "SELECT shobj_description(oid,'pg_authid') AS marker FROM pg_roles WHERE rolname=?", [$role])->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing['marker'] !== self::MARKER) {
                throw new AppError('O usuário escolhido já existe e não é gerenciado pelo ListenLab. Escolha outro MONITOR_DB_USER.');
            }
            $path = $this->config->get('MONITOR_DB_PASSWORD_FILE', $this->config->root . '/storage/secrets/monitor-db-password');
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) $path = $this->config->root . '/' . $path;
            $password = $this->password($path);
            $schema = $this->identifier((string) $this->db->query('SELECT current_schema()')->fetchColumn());
            $database = $this->identifier((string) $this->db->query('SELECT current_database()')->fetchColumn());
            $quotedRole = $this->identifier($role);
            $this->db->beginTransaction();
            try {
                if (!$existing) $this->db->exec('CREATE ROLE ' . $quotedRole);
                $this->db->exec('ALTER ROLE ' . $quotedRole . ' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS PASSWORD ' . $this->db->quote($password));
                $this->db->exec('COMMENT ON ROLE ' . $quotedRole . ' IS ' . $this->db->quote(self::MARKER));
                $memberships=Database::query($this->db,'SELECT parent.rolname FROM pg_auth_members m JOIN pg_roles parent ON parent.oid=m.roleid JOIN pg_roles child ON child.oid=m.member WHERE child.rolname=?',[$role])->fetchAll(PDO::FETCH_COLUMN);
                foreach($memberships as $parent)$this->db->exec('REVOKE '.$this->identifier($parent).' FROM '.$quotedRole);
                $this->db->exec('REVOKE ALL PRIVILEGES ON DATABASE ' . $database . ' FROM ' . $quotedRole);
                $this->db->exec('REVOKE ALL PRIVILEGES ON SCHEMA ' . $schema . ' FROM ' . $quotedRole);
                $this->db->exec('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA ' . $schema . ' FROM ' . $quotedRole);
                $this->db->exec('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA ' . $schema . ' FROM ' . $quotedRole);
                $columns=Database::query($this->db,'SELECT table_name,column_name FROM information_schema.column_privileges WHERE grantee=? AND table_schema=current_schema() AND privilege_type=\'SELECT\'',[$role])->fetchAll(PDO::FETCH_ASSOC);
                foreach($columns as $column)$this->db->exec('REVOKE SELECT ('.$this->identifier($column['column_name']).') ON '.$schema.'.'.$this->identifier($column['table_name']).' FROM '.$quotedRole);
                $this->db->exec('GRANT CONNECT ON DATABASE ' . $database . ' TO ' . $quotedRole);
                $this->db->exec('GRANT USAGE ON SCHEMA ' . $schema . ' TO ' . $quotedRole);
                foreach (self::TABLES as $table) {
                    $this->db->exec('GRANT SELECT ON ' . $schema . '.' . $this->identifier($table) . ' TO ' . $quotedRole);
                }
                // Local analytics reads event dates and catalog identities, never private playback context or RAW payloads.
                foreach ([
                    'plays' => 'id,track_id,played_at',
                    'tracks' => 'id,name,album_id',
                    'artists' => 'id,name',
                    'albums' => 'id,name',
                    'track_artists' => 'track_id,artist_id,position',
                ] as $table => $columns) {
                    $this->db->exec('GRANT SELECT (' . $columns . ') ON ' . $schema . '.' . $this->identifier($table) . ' TO ' . $quotedRole);
                }
                $this->db->exec('GRANT SELECT (id,batch_id,path,sha256,size_bytes,received_at,http_status) ON ' . $schema . '.raw_artifacts TO ' . $quotedRole);
                $this->db->exec('ALTER ROLE ' . $quotedRole . ' IN DATABASE ' . $database . ' SET default_transaction_read_only = on');
                $this->db->exec('ALTER ROLE ' . $quotedRole . ' IN DATABASE ' . $database . ' SET statement_timeout = 5000');
                $this->db->exec('ALTER ROLE ' . $quotedRole . ' IN DATABASE ' . $database . ' SET lock_timeout = 2000');
                $this->db->exec('ALTER ROLE ' . $quotedRole . ' IN DATABASE ' . $database . ' SET search_path TO ' . $schema);
                $this->db->commit();
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $error;
            }
            return ['provisioned' => true, 'role' => $role, 'read_only' => true];
        } finally { $this->db->exec('SELECT pg_advisory_unlock(194701, 4)'); }
    }

    private function password(string $path): string
    {
        if (is_link($path)) throw new AppError('O secret do monitor não pode ser um link simbólico.');
        if (!file_exists($path)) {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new AppError('Não foi possível criar o diretório privado do monitor.');
            $file = fopen($path, 'xb');
            if (!$file) throw new AppError('Não foi possível criar o secret do monitor.');
            try {
                chmod($path, 0600);
                $password = bin2hex(random_bytes(32));
                if (fwrite($file, $password) !== strlen($password) || !fflush($file) || !fsync($file)) throw new AppError('Não foi possível persistir o secret do monitor.');
            } finally { fclose($file); }
        }
        $password = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($password) || !preg_match('/^[0-9a-f]{64}$/D', $password)) throw new AppError('Secret do monitor inválido; o arquivo existente foi preservado.');
        return $password;
    }

    private function identifier(string $value): string { return '"' . str_replace('"', '""', $value) . '"'; }
}
