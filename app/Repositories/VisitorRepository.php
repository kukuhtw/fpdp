<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class VisitorRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        string $publicId,
        int $nodeId,
        string $googleSub,
        string $email,
        ?string $displayName,
        ?string $avatarUrl,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO visitor_accounts (public_id, node_id, google_sub, email, display_name, avatar_url)
             VALUES (:public_id, :node_id, :google_sub, :email, :display_name, :avatar_url)',
        );

        $statement->execute([
            'public_id' => $publicId,
            'node_id' => $nodeId,
            'google_sub' => $googleSub,
            'email' => $email,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM visitor_accounts WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNodeAndGoogleSub(int $nodeId, string $googleSub): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM visitor_accounts WHERE node_id = :node_id AND google_sub = :google_sub',
        );
        $statement->execute(['node_id' => $nodeId, 'google_sub' => $googleSub]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function touchLastSeen(int $visitorId, string $seenAt): void
    {
        $statement = $this->connection->prepare(
            'UPDATE visitor_accounts SET last_seen_at = :seen_at WHERE id = :id',
        );
        $statement->execute(['seen_at' => $seenAt, 'id' => $visitorId]);
    }
}
