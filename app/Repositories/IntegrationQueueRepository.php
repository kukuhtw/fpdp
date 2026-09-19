<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class IntegrationQueueRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(int $userId, string $provider, string $jobType, array $payload = []): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO integration_queue (user_id, provider, job_type, payload, status)
             VALUES (:user_id, :provider, :job_type, :payload, :status)',
        );
        $statement->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'job_type' => $jobType,
            'payload' => json_encode($payload),
            'status' => 'QUEUED',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchReady(int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM integration_queue
             WHERE status = 'QUEUED'
               AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP)
             ORDER BY created_at ASC
             LIMIT :limit",
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function markProcessing(int $id): void
    {
        $statement = $this->connection->prepare(
            "UPDATE integration_queue SET status = 'PROCESSING', updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $statement->execute(['id' => $id]);
    }

    public function markCompleted(int $id): void
    {
        $statement = $this->connection->prepare(
            "UPDATE integration_queue SET status = 'COMPLETED', updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $statement->execute(['id' => $id]);
    }

    public function markFailed(int $id, string $error, int $maxRetries = 3): void
    {
        $job = $this->findById($id);
        $retryCount = ($job ? (int) $job['retry_count'] : 0) + 1;

        if ($retryCount >= $maxRetries) {
            $statement = $this->connection->prepare(
                "UPDATE integration_queue SET status = 'FAILED', retry_count = :retry_count, last_error = :last_error, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            );
        } else {
            $backoffSeconds = min(300, 30 * (2 ** ($retryCount - 1))); // 30, 60, 120, 240, 300...
            $statement = $this->connection->prepare(
                "UPDATE integration_queue SET status = 'QUEUED', retry_count = :retry_count,
                 next_retry_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :backoff SECOND),
                 last_error = :last_error, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            );
            $statement->bindValue('backoff', $backoffSeconds, PDO::PARAM_INT);
        }

        $statement->execute([
            'id' => $id,
            'retry_count' => $retryCount,
            'last_error' => $error,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM integration_queue WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array{queued: int, processing: int, completed: int, failed: int}
     */
    public function getStats(): array
    {
        $stats = ['QUEUED' => 0, 'PROCESSING' => 0, 'COMPLETED' => 0, 'FAILED' => 0];
        $statement = $this->connection->query("SELECT status, COUNT(*) AS count FROM integration_queue GROUP BY status");
        foreach ($statement->fetchAll() as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }

        return [
            'queued' => $stats['QUEUED'],
            'processing' => $stats['PROCESSING'],
            'completed' => $stats['COMPLETED'],
            'failed' => $stats['FAILED'],
        ];
    }
}