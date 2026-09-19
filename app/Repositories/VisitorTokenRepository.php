<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class VisitorTokenRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(int $visitorId, string $tokenHash, string $expiresAt): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO visitor_tokens (visitor_id, token_hash, expires_at)
             VALUES (:visitor_id, :token_hash, :expires_at)',
        );

        $statement->execute([
            'visitor_id' => $visitorId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM visitor_tokens WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function revokeByHash(string $tokenHash, string $revokedAt): void
    {
        $statement = $this->connection->prepare(
            'UPDATE visitor_tokens SET revoked_at = :revoked_at WHERE token_hash = :token_hash',
        );

        $statement->execute([
            'revoked_at' => $revokedAt,
            'token_hash' => $tokenHash,
        ]);
    }
}
