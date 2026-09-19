<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NodeRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        string $publicId,
        string $domain,
        string $name,
        string $defaultLocale,
        string $timezone,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO nodes (public_id, domain, name, default_locale, timezone, status)
             VALUES (:public_id, :domain, :name, :default_locale, :timezone, :status)',
        );

        $statement->execute([
            'public_id' => $publicId,
            'domain' => $domain,
            'name' => $name,
            'default_locale' => $defaultLocale,
            'timezone' => $timezone,
            'status' => 'ACTIVE',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM nodes WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function domainExists(string $domain): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM nodes WHERE domain = :domain');
        $statement->execute(['domain' => $domain]);

        return $statement->fetchColumn() !== false;
    }
}
