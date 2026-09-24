<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ChatMessageRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(int $sessionId, string $role, string $content, ?string $costAmount = null): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO chat_messages (session_id, role, content, cost_amount) VALUES (:session_id, :role, :content, :cost_amount)',
        );
        $statement->execute(['session_id' => $sessionId, 'role' => $role, 'content' => $content, 'cost_amount' => $costAmount]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBySession(int $sessionId, int $limit = 50): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM chat_messages WHERE session_id = :session_id ORDER BY id ASC LIMIT ' . max(1, $limit),
        );
        $statement->execute(['session_id' => $sessionId]);

        return $statement->fetchAll();
    }
}
