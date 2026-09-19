<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuditEventRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        int $nodeId,
        ?int $actorUserId,
        string $action,
        ?string $subjectType = null,
        ?string $subjectPublicId = null,
        ?array $metadata = null,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO audit_events (node_id, actor_user_id, action, subject_type, subject_public_id, metadata)
             VALUES (:node_id, :actor_user_id, :action, :subject_type, :subject_public_id, :metadata)',
        );
        $statement->execute([
            'node_id' => $nodeId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'metadata' => $metadata !== null ? json_encode($metadata) : null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByNodeId(int $nodeId, int $limit = 50, ?int $beforeId = null): array
    {
        $where = ['node_id = :node_id'];
        $parameters = ['node_id' => $nodeId];

        if ($beforeId !== null) {
            $where[] = 'id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM audit_events WHERE ' . implode(' AND ', $where)
            . ' ORDER BY id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByUserId(int $userId, int $limit = 50): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM audit_events WHERE actor_user_id = :user_id ORDER BY id DESC LIMIT :limit',
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByAction(string $action, int $limit = 50): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM audit_events WHERE action = :action ORDER BY id DESC LIMIT :limit',
        );
        $statement->bindValue('action', $action, PDO::PARAM_STR);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}