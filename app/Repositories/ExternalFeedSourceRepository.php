<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Crypto;
use PDO;

final class ExternalFeedSourceRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findDueForSync(int $limit = 10): array
    {
        $statement = $this->connection->prepare(
            "SELECT efs.*, ea.access_token, ea.external_account_id AS provider_account_id, ea.external_username, ea.display_name AS account_display_name
             FROM external_feed_sources efs
             LEFT JOIN external_accounts ea ON ea.id = efs.external_account_id
             WHERE efs.sync_enabled = 1
               AND efs.status = 'ACTIVE'
               AND (efs.next_sync_at IS NULL OR efs.next_sync_at <= CURRENT_TIMESTAMP)
             ORDER BY (efs.next_sync_at IS NOT NULL) ASC, efs.next_sync_at ASC
             LIMIT :limit",
        );
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            if (in_array(($row['provider'] ?? ''), ['FACEBOOK','LINKEDIN'], true) && !empty($row['access_token'])) {
                try { $row['access_token'] = Crypto::decrypt((string) $row['access_token']); }
                catch (\Throwable) { $row['access_token'] = null; }
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM external_feed_sources WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllByUserId(int $userId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM external_feed_sources WHERE user_id = :user_id ORDER BY created_at DESC',
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function findAllForSyncByUserId(int $userId, int $limit = 10): array
    {
        $statement = $this->connection->prepare(
            "SELECT efs.*, ea.access_token, ea.external_account_id AS provider_account_id,
                    ea.external_username, ea.display_name AS account_display_name
             FROM external_feed_sources efs
             LEFT JOIN external_accounts ea ON ea.id = efs.external_account_id
             WHERE efs.user_id = :user_id AND efs.sync_enabled = 1
             ORDER BY efs.id ASC LIMIT :limit",
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            if (in_array(($row['provider'] ?? ''), ['FACEBOOK', 'LINKEDIN'], true) && !empty($row['access_token'])) {
                try { $row['access_token'] = Crypto::decrypt((string) $row['access_token']); }
                catch (\Throwable) { $row['access_token'] = null; }
            }
        }
        unset($row);
        return $rows;
    }

    public function existsForUser(int $userId, string $provider, string $sourceUrl): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM external_feed_sources WHERE user_id = :user_id AND provider = :provider AND source_url = :source_url LIMIT 1',
        );
        $statement->execute(['user_id' => $userId, 'provider' => $provider, 'source_url' => $sourceUrl]);
        return $statement->fetchColumn() !== false;
    }

    public function deleteForUser(int $userId, int $sourceId): bool
    {
        $this->connection->beginTransaction();
        try {
            $posts = $this->connection->prepare('DELETE FROM external_posts WHERE feed_source_id = :source_id AND user_id = :user_id');
            $posts->execute(['source_id' => $sourceId, 'user_id' => $userId]);
            $source = $this->connection->prepare('DELETE FROM external_feed_sources WHERE id = :id AND user_id = :user_id');
            $source->execute(['id' => $sourceId, 'user_id' => $userId]);
            $deleted = $source->rowCount() > 0;
            $this->connection->commit();
            return $deleted;
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }

    public function updateSyncStatus(int $id, string $status, ?string $lastError = null, int $syncInterval = 3600): void
    {
        $connection = $this->connection;
        $driver = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $nextSyncExpr = match ($driver) {
            'sqlite' => "datetime('now', '+' || :interval || ' seconds')",
            default => 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :interval SECOND)',
        };

        $statement = $connection->prepare(
            "UPDATE external_feed_sources
             SET last_sync_at = CURRENT_TIMESTAMP,
                 next_sync_at = {$nextSyncExpr},
                 status = :status,
                 last_error = :last_error
             WHERE id = :id",
        );
        $statement->execute([
            'id' => $id,
            'interval' => $syncInterval,
            'status' => $status,
            'last_error' => $lastError,
        ]);
    }

    public function updateLastError(int $id, string $error): void
    {
        $statement = $this->connection->prepare(
            'UPDATE external_feed_sources SET last_error = :last_error, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'last_error' => $error]);
    }

    public function create(int $userId, string $provider, string $sourceType, string $sourceUrl, ?int $externalAccountId = null, int $syncInterval = 3600): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO external_feed_sources (user_id, provider, source_type, source_url, external_account_id, sync_interval)
             VALUES (:user_id, :provider, :source_type, :source_url, :external_account_id, :sync_interval)',
        );
        $statement->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'source_type' => $sourceType,
            'source_url' => $sourceUrl,
            'external_account_id' => $externalAccountId,
            'sync_interval' => $syncInterval,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function ensureForExternalAccount(int $userId, string $provider, string $sourceType, string $sourceUrl, int $externalAccountId): int
    {
        $statement = $this->connection->prepare('SELECT id FROM external_feed_sources WHERE user_id=:user_id AND provider=:provider AND external_account_id=:account_id LIMIT 1');
        $statement->execute(['user_id'=>$userId,'provider'=>$provider,'account_id'=>$externalAccountId]);
        $id = $statement->fetchColumn();
        return $id === false ? $this->create($userId,$provider,$sourceType,$sourceUrl,$externalAccountId) : (int)$id;
    }
}
