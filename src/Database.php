<?php
declare(strict_types=1);

namespace ListenLab;

use PDO;

final class Database
{
    public static function connect(Config $config, ?string $database = null): PDO
    {
        return self::connectAs($config, $database, $config->get('POSTGRES_USER', 'listenlab'), $config->required('POSTGRES_PASSWORD'));
    }

    public static function monitor(Config $config): PDO
    {
        $password = $config->secret('MONITOR_DB_PASSWORD', $config->root . '/storage/secrets/monitor-db-password');
        $db = self::connectAs($config, null, $config->get('MONITOR_DB_USER', 'listenlab_monitor'), $password);
        $db->exec('SET default_transaction_read_only = on');
        return $db;
    }

    private static function connectAs(Config $config, ?string $database, string $user, string $password): PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->integer('DB_PORT', 5432, 1, 65535),
            $database ?? $config->get('POSTGRES_DB', 'listenlab'));
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET TIME ZONE 'UTC'");
        return $pdo;
    }

    public static function query(PDO $db, string $sql, array $params = []): \PDOStatement
    {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }
        $stmt->execute();
        return $stmt;
    }
}
