<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RagDocumentRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $nodeId, string $title, ?string $originalFilename, string $content): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO rag_documents (public_id, node_id, title, original_filename, content)
             VALUES (:public_id, :node_id, :title, :original_filename, :content)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'node_id' => $nodeId,
            'title' => $title,
            'original_filename' => $originalFilename,
            'content' => $content,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM rag_documents WHERE public_id = :public_id');
        $statement->execute(['public_id' => $publicId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM rag_documents WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByNodeId(int $nodeId): array
    {
        $statement = $this->connection->prepare('SELECT * FROM rag_documents WHERE node_id = :node_id ORDER BY id DESC');
        $statement->execute(['node_id' => $nodeId]);

        return $statement->fetchAll();
    }

    public function delete(int $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM rag_documents WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
