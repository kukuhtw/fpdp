<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $connection,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * Applies every migration in $migrationsPath that is not yet recorded
     * as applied, in filename order, and returns the filenames it ran.
     *
     * @return array<int, string>
     */
    public function run(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedMigrations();
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        sort($files);

        $ranMigrations = [];

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Unable to read migration file: {$file}");
            }

            $this->connection->exec($sql);
            $this->recordMigration($name);
            $ranMigrations[] = $name;
        }

        return $ranMigrations;
    }

    private function ensureMigrationsTable(): void
    {
        $idColumn = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'id INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'id INT AUTO_INCREMENT PRIMARY KEY';

        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                {$idColumn},
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * @return array<int, string>
     */
    private function appliedMigrations(): array
    {
        $statement = $this->connection->query('SELECT migration FROM migrations');

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function recordMigration(string $name): void
    {
        $statement = $this->connection->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
        $statement->execute(['migration' => $name]);
    }
}
