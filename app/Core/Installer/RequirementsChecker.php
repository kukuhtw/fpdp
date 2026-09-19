<?php

declare(strict_types=1);

namespace App\Core\Installer;

/**
 * Checks the PHP runtime, required extensions, and filesystem permissions
 * the web installer needs before it is safe to collect database credentials.
 */
final class RequirementsChecker
{
    private const MIN_PHP_VERSION = '8.2.0';
    private const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'simplexml', 'json', 'mbstring'];

    /**
     * @return list<array{label: string, passed: bool, detail: string}>
     */
    public static function check(string $rootPath): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'PHP version >= ' . self::MIN_PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
            'detail' => 'Detected PHP ' . PHP_VERSION,
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = [
                'label' => "Extension: {$extension}",
                'passed' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'Loaded' : 'Missing — enable it in php.ini',
            ];
        }

        $envPath = rtrim($rootPath, '/') . '/.env';
        $envWritable = is_file($envPath) ? is_writable($envPath) : is_writable(dirname($envPath));
        $checks[] = [
            'label' => 'Project root is writable (for .env)',
            'passed' => $envWritable,
            'detail' => $envWritable ? 'Writable' : "Not writable: {$rootPath}",
        ];

        $storagePath = rtrim($rootPath, '/') . '/storage';
        $storageReady = is_dir($storagePath) ? is_writable($storagePath) : @mkdir($storagePath, 0755, true);
        $checks[] = [
            'label' => 'storage/ directory is writable (for the install lock)',
            'passed' => (bool) $storageReady,
            'detail' => $storageReady ? 'Writable' : "Not writable: {$storagePath}",
        ];

        return $checks;
    }

    public static function allPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['passed'] === false) {
                return false;
            }
        }

        return true;
    }
}
