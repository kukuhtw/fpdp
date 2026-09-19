<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuthTokenRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(int $userId, string $tokenHash, string $expiresAt, string $tokenType = 'ACCESS'): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, token_type, expires_at)
             VALUES (:user_id, :token_hash, :token_type, :expires_at)',
        );

        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'token_type' => $tokenType,
            'expires_at' => $expiresAt,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM auth_tokens WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function revokeByHash(string $tokenHash, string $revokedAt): void
    {
        $statement = $this->connection->prepare(
            'UPDATE auth_tokens SET revoked_at = :revoked_at WHERE token_hash = :token_hash',
        );

        $statement->execute([
            'revoked_at' => $revokedAt,
            'token_hash' => $tokenHash,
        ]);
    }
}
