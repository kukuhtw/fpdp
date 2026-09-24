<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RagFaqRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $documentId, string $question, string $answer): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO rag_faqs (public_id, document_id, question, answer) VALUES (:public_id, :document_id, :question, :answer)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'document_id' => $documentId,
            'question' => $question,
            'answer' => $answer,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM rag_faqs WHERE public_id = :public_id');
        $statement->execute(['public_id' => $publicId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM rag_faqs WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByDocumentId(int $documentId): array
    {
        $statement = $this->connection->prepare('SELECT * FROM rag_faqs WHERE document_id = :document_id ORDER BY id ASC');
        $statement->execute(['document_id' => $documentId]);

        return $statement->fetchAll();
    }

    /**
     * Every embedded FAQ across all of a node's documents — the candidate
     * pool for the chatbot's retrieval step (ChatbotService does the actual
     * similarity ranking; this just scopes the brute-force scan to one node).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEmbeddedByNodeId(int $nodeId): array
    {
        $statement = $this->connection->prepare(
            'SELECT rag_faqs.* FROM rag_faqs
             INNER JOIN rag_documents ON rag_documents.id = rag_faqs.document_id
             WHERE rag_documents.node_id = :node_id AND rag_faqs.embedding IS NOT NULL',
        );
        $statement->execute(['node_id' => $nodeId]);

        return $statement->fetchAll();
    }

    /**
     * Updates the question/answer text and clears any embedding — the old
     * vector no longer matches the new text, so it must be regenerated
     * before this FAQ is usable for retrieval again.
     */
    public function updateText(int $id, string $question, string $answer): void
    {
        $statement = $this->connection->prepare(
            'UPDATE rag_faqs SET question = :question, answer = :answer,
                 embedding = NULL, embedding_model = NULL, embedding_generated_at = NULL
             WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'question' => $question, 'answer' => $answer]);
    }

    /**
     * @param array<int, float> $vector
     */
    public function updateEmbedding(int $id, array $vector, string $model): void
    {
        $statement = $this->connection->prepare(
            'UPDATE rag_faqs SET embedding = :embedding, embedding_model = :model, embedding_generated_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'embedding' => json_encode($vector), 'model' => $model]);
    }

    public function delete(int $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM rag_faqs WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
