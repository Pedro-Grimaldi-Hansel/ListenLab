<?php
declare(strict_types=1);

namespace ListenLab;

use PDO;

final class Database
{
    public static function connect(Config $config, ?string $database = null): PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->integer('DB_PORT', 5432, 1, 65535),
            $database ?? $config->get('POSTGRES_DB', 'listenlab'));
        $pdo = new PDO($dsn, $config->get('POSTGRES_USER', 'listenlab'), $config->required('POSTGRES_PASSWORD'), [
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
