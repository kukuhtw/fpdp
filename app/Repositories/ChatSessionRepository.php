<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ChatSessionRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $nodeId, int $visitorId): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO chat_sessions (public_id, node_id, visitor_id) VALUES (:public_id, :node_id, :visitor_id)',
        );
        $statement->execute(['public_id' => $publicId, 'node_id' => $nodeId, 'visitor_id' => $visitorId]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Most recent ACTIVE session for this visitor on this node, or null —
     * one conversation thread reused across the visitor's questions rather
     * than a new session per message.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveForVisitor(int $nodeId, int $visitorId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM chat_sessions WHERE node_id = :node_id AND visitor_id = :visitor_id AND status = 'ACTIVE'
             ORDER BY id DESC LIMIT 1",
        );
        $statement->execute(['node_id' => $nodeId, 'visitor_id' => $visitorId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM chat_sessions WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function recordMessage(int $sessionId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE chat_sessions SET message_count = message_count + 1, last_message_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $statement->execute(['id' => $sessionId]);
    }
}
