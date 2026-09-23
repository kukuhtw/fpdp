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

    /**
     * @param array<int, string> $capabilities
     */
    public function updateCapabilities(int $id, array $capabilities): void
    {
        $statement = $this->connection->prepare(
            'UPDATE nodes SET capabilities = :capabilities WHERE id = :id',
        );
        $statement->execute(['capabilities' => json_encode(array_values($capabilities)), 'id' => $id]);
    }

    public function getActiveGateway(int $id): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT active_gateway FROM nodes WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function setActiveGateway(int $id, ?string $gatewayCode): void
    {
        $statement = $this->connection->prepare(
            'UPDATE nodes SET active_gateway = :gateway WHERE id = :id',
        );
        $statement->execute(['gateway' => $gatewayCode, 'id' => $id]);
    }

    public function updateTheme(int $id, string $slug): void
    {
        $statement = $this->connection->prepare(
            'UPDATE nodes SET theme = :theme WHERE id = :id',
        );
        $statement->execute(['theme' => $slug, 'id' => $id]);
    }

    /**
     * Returns the first locally-hosted node. FPDP deployments currently host
     * a single owner node, so this is used to resolve "our own node" for
     * endpoints (like federation capability discovery) that a remote server
     * fetches without any local credentials.
     *
     * @return array<string, mixed>|null
     */
    public function findFirst(): ?array
    {
        $statement = $this->connection->query('SELECT * FROM nodes ORDER BY id ASC LIMIT 1');
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
