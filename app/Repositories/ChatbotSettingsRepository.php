<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ChatbotSettingsRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNodeId(int $nodeId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM chatbot_settings WHERE node_id = :node_id');
        $statement->execute(['node_id' => $nodeId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function upsert(int $nodeId, string $pricePerQuestion, string $currency, string $status): void
    {
        $existing = $this->findByNodeId($nodeId);

        if ($existing === null) {
            $statement = $this->connection->prepare(
                'INSERT INTO chatbot_settings (node_id, price_per_question, currency, status)
                 VALUES (:node_id, :price_per_question, :currency, :status)',
            );
        } else {
            $statement = $this->connection->prepare(
                'UPDATE chatbot_settings SET price_per_question = :price_per_question, currency = :currency,
                     status = :status, updated_at = CURRENT_TIMESTAMP
                 WHERE node_id = :node_id',
            );
        }

        $statement->execute([
            'node_id' => $nodeId,
            'price_per_question' => $pricePerQuestion,
            'currency' => $currency,
            'status' => $status,
        ]);
    }
}
