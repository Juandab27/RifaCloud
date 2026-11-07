<?php

declare(strict_types=1);

namespace RifaCloud\PaymentsService;

use PDO;

final class Database
{
    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $config
     */
    public static function connect(array $config, bool $readOnly = false): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);

        // All services store and compare timestamps in UTC.
        $pdo->exec("SET SESSION time_zone = '+00:00'");

        if ($readOnly) {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        }

        return $pdo;
    }
}
