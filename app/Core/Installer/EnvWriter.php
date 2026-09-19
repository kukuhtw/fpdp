<?php

declare(strict_types=1);

namespace App\Core\Installer;

use PDO;
use PDOException;

/**
 * Renders and writes the .env file the installer collects from the wizard,
 * and validates a database connection before that file is written.
 */
final class EnvWriter
{
    /**
     * @param array<string, string> $values
     */
    public static function write(string $path, array $values): void
    {
        $content = self::render($values);

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write {$path}. Check that the project root is writable.");
        }

        // Best-effort: not every shared-hosting filesystem honors chmod.
        @chmod($path, 0600);
    }

    /**
     * Attempts to open a PDO connection with the given DB_* values without
     * touching the application's cached connection. Returns null on success
     * or a human-readable error message on failure.
     *
     * @param array<string, string> $db
     */
    public static function testConnection(array $db): ?string
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['DB_HOST'],
            $db['DB_PORT'],
            $db['DB_DATABASE'],
            $db['DB_CHARSET'],
        );

        try {
            new PDO($dsn, $db['DB_USERNAME'], $db['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, string> $values
     */
    public static function render(array $values): string
    {
        $get = static fn (string $key, string $default = ''): string => $values[$key] ?? $default;

        $appKey = $get('APP_KEY') !== '' ? $get('APP_KEY') : bin2hex(random_bytes(32));

        $lines = [
            'APP_NAME=' . $get('APP_NAME', 'FPDP'),
            'APP_ENV=' . $get('APP_ENV', 'production'),
            'APP_DEBUG=' . $get('APP_DEBUG', 'false'),
            'APP_VERSION=' . $get('APP_VERSION', '0.1.0'),
            '',
            'DB_CONNECTION=' . $get('DB_CONNECTION', 'mysql'),
            'DB_HOST=' . $get('DB_HOST', '127.0.0.1'),
            'DB_PORT=' . $get('DB_PORT', '3306'),
            'DB_DATABASE=' . $get('DB_DATABASE', 'fpdp'),
            'DB_USERNAME=' . $get('DB_USERNAME', 'root'),
            'DB_PASSWORD=' . $get('DB_PASSWORD', ''),
            'DB_CHARSET=' . $get('DB_CHARSET', 'utf8mb4'),
            '',
            'NODE_DOMAIN=' . $get('NODE_DOMAIN', 'localhost'),
            'NODE_NAME=' . $get('NODE_NAME', 'FPDP Node'),
            'NODE_DEFAULT_LOCALE=' . $get('NODE_DEFAULT_LOCALE', 'id'),
            'NODE_TIMEZONE=' . $get('NODE_TIMEZONE', 'Asia/Jakarta'),
            'AUTH_TOKEN_TTL=' . $get('AUTH_TOKEN_TTL', '604800'),
            '',
            'RATE_LIMIT_LOGIN_MAX=' . $get('RATE_LIMIT_LOGIN_MAX', '5'),
            'RATE_LIMIT_LOGIN_WINDOW=' . $get('RATE_LIMIT_LOGIN_WINDOW', '900'),
            'RATE_LIMIT_REGISTER_MAX=' . $get('RATE_LIMIT_REGISTER_MAX', '5'),
            'RATE_LIMIT_REGISTER_WINDOW=' . $get('RATE_LIMIT_REGISTER_WINDOW', '3600'),
            '',
            '# Long random secret used to sign OAuth state parameters. Generated automatically by the installer.',
            'APP_KEY=' . $appKey,
            '',
            'GOOGLE_CLIENT_ID=' . $get('GOOGLE_CLIENT_ID', ''),
            'GOOGLE_CLIENT_SECRET=' . $get('GOOGLE_CLIENT_SECRET', ''),
            'VISITOR_TOKEN_TTL=' . $get('VISITOR_TOKEN_TTL', '2592000'),
        ];

        return implode("\n", $lines) . "\n";
    }
}
