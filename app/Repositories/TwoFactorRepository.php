<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Storage for the owner's second factor: the TOTP secret columns on users,
 * one-time recovery codes, and the short-lived login challenges between
 * "password correct" and "code correct".
 */
final class TwoFactorRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function setPendingSecret(int $userId, ?string $encryptedSecret): void
    {
        $statement = $this->connection->prepare('UPDATE users SET totp_pending_secret = :secret WHERE id = :id');
        $statement->execute(['secret' => $encryptedSecret, 'id' => $userId]);
    }

    public function enable(int $userId, string $encryptedSecret, int $step, string $now): void
    {
        $statement = $this->connection->prepare(
            'UPDATE users SET totp_secret = :secret, totp_pending_secret = NULL, totp_enabled_at = :now, totp_last_step = :step WHERE id = :id',
        );
        $statement->execute(['secret' => $encryptedSecret, 'now' => $now, 'step' => $step, 'id' => $userId]);
    }

    public function disable(int $userId): void
    {
        $this->connection->prepare(
            'UPDATE users SET totp_secret = NULL, totp_pending_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = :id',
        )->execute(['id' => $userId]);
        $this->connection->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
        $this->connection->prepare('DELETE FROM mfa_challenges WHERE user_id = :id')->execute(['id' => $userId]);
    }

    /**
     * Records the step of a code just accepted. Conditional on the stored
     * step being older, so two requests racing with the same code can't
     * both succeed.
     */
    public function claimStep(int $userId, int $step): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE users SET totp_last_step = :step WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :step2)',
        );
        $statement->execute(['step' => $step, 'step2' => $step, 'id' => $userId]);

        return $statement->rowCount() === 1;
    }

    /**
     * @param array<int, string> $codeHashes
     */
    public function replaceRecoveryCodes(int $userId, array $codeHashes): void
    {
        $this->connection->prepare('DELETE FROM user_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
        $insert = $this->connection->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (:user_id, :code_hash)');
        foreach ($codeHashes as $hash) {
            $insert->execute(['user_id' => $userId, 'code_hash' => $hash]);
        }
    }

    /**
     * @return array<int, array{id: int, code_hash: string}>
     */
    public function unusedRecoveryCodes(int $userId): array
    {
        $statement = $this->connection->prepare('SELECT id, code_hash FROM user_recovery_codes WHERE user_id = :id AND used_at IS NULL');
        $statement->execute(['id' => $userId]);

        return $statement->fetchAll();
    }

    /** Marks a recovery code used; false if it was used concurrently. */
    public function useRecoveryCode(int $codeId, string $now): bool
    {
        $statement = $this->connection->prepare('UPDATE user_recovery_codes SET used_at = :now WHERE id = :id AND used_at IS NULL');
        $statement->execute(['now' => $now, 'id' => $codeId]);

        return $statement->rowCount() === 1;
    }

    public function createChallenge(int $userId, string $tokenHash, string $expiresAt): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO mfa_challenges (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, :expires_at)',
        );
        $statement->execute(['user_id' => $userId, 'token_hash' => $tokenHash, 'expires_at' => $expiresAt]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findChallenge(string $tokenHash): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM mfa_challenges WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function countChallengeAttempt(int $challengeId): void
    {
        $this->connection->prepare('UPDATE mfa_challenges SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $challengeId]);
    }

    public function deleteChallenge(int $challengeId): void
    {
        $this->connection->prepare('DELETE FROM mfa_challenges WHERE id = :id')->execute(['id' => $challengeId]);
    }

    public function deleteExpiredChallenges(string $now): void
    {
        $this->connection->prepare('DELETE FROM mfa_challenges WHERE expires_at < :now')->execute(['now' => $now]);
    }
}
