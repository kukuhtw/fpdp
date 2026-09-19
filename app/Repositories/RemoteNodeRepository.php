<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RemoteNodeRepository
{
    private const SELECT = '
        SELECT id, public_id, domain, name, status, trust_state,
               last_seen_at, created_at, updated_at
        FROM remote_nodes
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, string $domain, ?string $name = null): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO remote_nodes (public_id, domain, name, status, trust_state)
             VALUES (:public_id, :domain, :name, :status, :trust_state)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'domain' => $domain,
            'name' => $name,
            'status' => 'ACTIVE',
            'trust_state' => 'UNKNOWN',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findByDomain(string $domain): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE domain = :domain');
        $statement->execute(['domain' => $domain]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE public_id = :public_id');
        $statement->execute(['public_id' => $publicId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function updateTrustState(int $id, string $trustState): void
    {
        $statement = $this->connection->prepare(
            'UPDATE remote_nodes SET trust_state = :trust_state WHERE id = :id',
        );
        $statement->execute(['trust_state' => $trustState, 'id' => $id]);
    }

    public function updateLastSeen(int $id): void
    {
        $statement = $this->connection->prepare(
            'UPDATE remote_nodes SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
    }

    /**
     * @return array<int, string> Domain list of blocked remote nodes
     */
    public function findBlockedDomains(): array
    {
        $statement = $this->connection->prepare(
            "SELECT domain FROM remote_nodes WHERE trust_state = 'BLOCKED' OR status = 'SUSPENDED'",
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}