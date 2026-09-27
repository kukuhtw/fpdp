<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuthTokenRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        int $userId,
        string $tokenHash,
        string $expiresAt,
        string $tokenType = 'ACCESS',
        ?string $publicId = null,
        ?string $userAgent = null,
        ?string $ipHint = null,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO auth_tokens (public_id, user_id, token_hash, token_type, user_agent, ip_hint, expires_at, last_used_at, created_at)
             VALUES (:public_id, :user_id, :token_hash, :token_type, :user_agent, :ip_hint, :expires_at, :now1, :now2)',
        );

        $statement->execute([
            'public_id' => $publicId,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'token_type' => $tokenType,
            'user_agent' => $userAgent,
            'ip_hint' => $ipHint,
            'expires_at' => $expiresAt,
            // Same clock as expires_at/revoked_at: PHP's local time (NODE_TIMEZONE),
            // not the database's CURRENT_TIMESTAMP, which may be in another zone.
            'now1' => $now = date('Y-m-d H:i:s'),
            'now2' => $now,
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

    /**
     * A user's sessions that still work: not revoked, not expired. Newest
     * activity first. Never returns the token hash.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveByUserId(int $userId, string $now): array
    {
        $statement = $this->connection->prepare(
            'SELECT public_id, user_agent, ip_hint, created_at, last_used_at, expires_at
             FROM auth_tokens
             WHERE user_id = :user_id AND revoked_at IS NULL AND expires_at > :now
             ORDER BY COALESCE(last_used_at, created_at) DESC, id DESC',
        );
        $statement->execute(['user_id' => $userId, 'now' => $now]);

        return $statement->fetchAll();
    }

    /**
     * Revokes one active session of this user. Returns false if there is no
     * such active session (unknown id, another user's, already revoked).
     */
    public function revokeByPublicId(int $userId, string $publicId, string $revokedAt): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE auth_tokens SET revoked_at = :revoked_at
             WHERE user_id = :user_id AND public_id = :public_id AND revoked_at IS NULL',
        );
        $statement->execute(['revoked_at' => $revokedAt, 'user_id' => $userId, 'public_id' => $publicId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Revokes every active session of this user except the one whose hash is
     * $keepTokenHash (the caller's own). Returns how many were revoked.
     */
    public function revokeAllExcept(int $userId, string $keepTokenHash, string $revokedAt): int
    {
        $statement = $this->connection->prepare(
            'UPDATE auth_tokens SET revoked_at = :revoked_at
             WHERE user_id = :user_id AND token_hash <> :keep AND revoked_at IS NULL AND expires_at > :now',
        );
        $statement->execute(['revoked_at' => $revokedAt, 'user_id' => $userId, 'keep' => $keepTokenHash, 'now' => $revokedAt]);

        return $statement->rowCount();
    }

    public function touchLastUsed(string $tokenHash, string $now): void
    {
        $statement = $this->connection->prepare('UPDATE auth_tokens SET last_used_at = :now WHERE token_hash = :token_hash');
        $statement->execute(['now' => $now, 'token_hash' => $tokenHash]);
    }

    /**
     * Deletes this user's tokens that expired or were revoked before
     * $before — they can never be used again and only keep old device and
     * network hints around.
     */
    public function deleteStale(int $userId, string $before): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM auth_tokens
             WHERE user_id = :user_id AND (expires_at < :before1 OR (revoked_at IS NOT NULL AND revoked_at < :before2))',
        );
        $statement->execute(['user_id' => $userId, 'before1' => $before, 'before2' => $before]);
    }
}
