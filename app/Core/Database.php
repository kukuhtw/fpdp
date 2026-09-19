<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::connect();
        }

        return self::$connection;
    }

    /**
     * Clears the cached connection so the next call to connection()
     * reconnects using the current configuration.
     */
    public static function reset(): void
    {
        self::$connection = null;
    }

    private static function connect(): PDO
    {
        $driver = Config::get('DB_CONNECTION', 'mysql');

        $dsn = $driver === 'sqlite'
            ? 'sqlite:' . Config::get('DB_DATABASE', ':memory:')
            : sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                Config::get('DB_HOST', '127.0.0.1'),
                Config::get('DB_PORT', '3306'),
                Config::get('DB_DATABASE', 'fpdp'),
                Config::get('DB_CHARSET', 'utf8mb4'),
            );

        try {
            return new PDO(
                $dsn,
                Config::get('DB_USERNAME', 'root'),
                Config::get('DB_PASSWORD', ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
