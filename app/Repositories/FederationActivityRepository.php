<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FederationActivityRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $nodeId, string $direction, string $activityType, string $actorUri, ?string $objectUri, ?string $targetDomain, array $payload, ?string $signature = null, ?string $status = null, ?string $targetActorUri = null): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO federation_activities (public_id, node_id, direction, activity_type, actor_uri, object_uri, target_node_domain, target_actor_uri, payload, signature, status)
             VALUES (:public_id, :node_id, :direction, :activity_type, :actor_uri, :object_uri, :target_node_domain, :target_actor_uri, :payload, :signature, :status)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'node_id' => $nodeId,
            'direction' => $direction,
            'activity_type' => $activityType,
            'actor_uri' => $actorUri,
            'object_uri' => $objectUri,
            'target_node_domain' => $targetDomain,
            'target_actor_uri' => $targetActorUri,
            'payload' => json_encode($payload),
            'signature' => $signature,
            'status' => $status ?? ($direction === 'INCOMING' ? 'RECEIVED' : 'PENDING'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findPendingOutgoing(int $limit = 10): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM federation_activities
             WHERE direction = 'OUTGOING' AND status = 'PENDING'
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
             ORDER BY created_at ASC LIMIT :limit",
        );
        $statement->bindValue('now', gmdate('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function markDelivered(int $id): void
    {
        $statement = $this->connection->prepare(
            "UPDATE federation_activities SET status = 'DELIVERED', delivered_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $statement->execute(['id' => $id]);
    }

    public function markFailed(int $id, string $error, int $maxRetries = 3): void
    {
        $activity = $this->findById($id);
        $retryCount = ($activity ? (int) $activity['retry_count'] : 0) + 1;

        if ($retryCount >= $maxRetries) {
            $statement = $this->connection->prepare(
                "UPDATE federation_activities SET status = 'FAILED', retry_count = :retry_count, last_error = :last_error, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            );
            $statement->execute(['id' => $id, 'retry_count' => $retryCount, 'last_error' => $error]);
            return;
        }

        $backoffSeconds = min(3600, 60 * (2 ** ($retryCount - 1)));
        $statement = $this->connection->prepare(
            "UPDATE federation_activities SET status = 'PENDING', retry_count = :retry_count, last_error = :last_error, next_attempt_at = :next_attempt_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $statement->execute([
            'id' => $id,
            'retry_count' => $retryCount,
            'last_error' => $error,
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $backoffSeconds),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM federation_activities WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function existsByActivityId(string $activityId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM federation_activities WHERE public_id = :public_id');
        $statement->execute(['public_id' => $activityId]);
        return $statement->fetchColumn() !== false;
    }
}