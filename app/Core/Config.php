<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private const REQUIRED_KEYS = ['APP_NAME', 'APP_ENV'];
    private const VALID_ENVIRONMENTS = ['local', 'testing', 'production'];

    /** @var array<string, string>|null */
    private static ?array $values = null;

    /**
     * Loads configuration from defaults, an optional .env file, and real
     * environment variables (which take precedence), then validates it.
     */
    public static function load(?string $envPath = null): void
    {
        $envPath ??= dirname(__DIR__, 2) . '/.env';
        $values = self::defaults();

        foreach (self::parseEnvFile($envPath) as $key => $value) {
            $values[$key] = $value;
        }

        foreach (array_keys($values) as $key) {
            $fromEnv = getenv($key);
            if ($fromEnv !== false) {
                $values[$key] = $fromEnv;
            }
        }

        self::validate($values);

        self::$values = $values;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$values === null) {
            self::load();
        }

        // Container platforms commonly inject variables without creating a
        // physical .env file. Always let a real process environment value
        // override file/default configuration, including keys not in defaults.
        $fromEnv = getenv($key);
        if ($fromEnv !== false) {
            return $fromEnv;
        }

        return self::$values[$key] ?? $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        return [
            'APP_NAME' => 'FPDP',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_VERSION' => '0.1.0',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $values = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value, " \t\"'");

            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private static function validate(array $values): void
    {
        $missing = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if (($values[$key] ?? '') === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException('Missing required configuration keys: ' . implode(', ', $missing));
        }

        if (!in_array($values['APP_ENV'], self::VALID_ENVIRONMENTS, true)) {
            throw new \RuntimeException(sprintf(
                'Invalid APP_ENV "%s". Expected one of: %s',
                $values['APP_ENV'],
                implode(', ', self::VALID_ENVIRONMENTS),
            ));
        }
    }
}
