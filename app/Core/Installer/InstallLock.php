<?php

declare(strict_types=1);

namespace App\Core\Installer;

/**
 * Marks the web installer as completed so it refuses to run again (and
 * cannot be used to overwrite a live node's database credentials) until an
 * operator deliberately deletes the lock file.
 */
final class InstallLock
{
    private static function path(string $rootPath): string
    {
        return rtrim($rootPath, '/') . '/storage/installed.lock';
    }

    public static function isInstalled(string $rootPath): bool
    {
        return is_file(self::path($rootPath));
    }

    public static function lock(string $rootPath): void
    {
        $path = self::path($rootPath);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create {$directory}.");
        }

        $written = file_put_contents(
            $path,
            'Installed at ' . gmdate('c') . "\nDelete this file to allow the installer to run again.\n",
            LOCK_EX,
        );

        if ($written === false) {
            throw new \RuntimeException("Unable to write {$path}.");
        }
    }
}
