<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService;

use PDO;

final class Database
{
    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $config
     */
    public static function connect(array $config, bool $readOnly = true): PDO
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

        // The monolith stores timestamps in UTC (config/app.php). Keeping the
        // same session time zone makes the read model deterministic.
        $pdo->exec("SET SESSION time_zone = '+00:00'");

        if ($readOnly) {
            // Defence in depth for the shared-schema first increment. The
            // service only issues SELECT statements, and the session itself
            // rejects writes before a dedicated read-only DB user is added.
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        }

        return $pdo;
    }
}
