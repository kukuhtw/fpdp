<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CvDocumentRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * One CV document per node: creates it on first upload, replaces its
     * metadata (and, by convention, its stored file) on subsequent uploads.
     */
    public function upsert(
        string $publicId,
        int $nodeId,
        string $title,
        string $storageKey,
        string $contentType,
        string $priceAmount,
        string $priceCurrency,
    ): int {
        $existing = $this->findByNodeId($nodeId);

        if ($existing === null) {
            $statement = $this->connection->prepare(
                'INSERT INTO cv_documents (public_id, node_id, title, storage_key, content_type, price_amount, price_currency, status)
                 VALUES (:public_id, :node_id, :title, :storage_key, :content_type, :price_amount, :price_currency, :status)',
            );
            $statement->execute([
                'public_id' => $publicId,
                'node_id' => $nodeId,
                'title' => $title,
                'storage_key' => $storageKey,
                'content_type' => $contentType,
                'price_amount' => $priceAmount,
                'price_currency' => $priceCurrency,
                'status' => 'ACTIVE',
            ]);

            return (int) $this->connection->lastInsertId();
        }

        $statement = $this->connection->prepare(
            'UPDATE cv_documents
             SET title = :title, storage_key = :storage_key, content_type = :content_type,
                 price_amount = :price_amount, price_currency = :price_currency
             WHERE id = :id',
        );
        $statement->execute([
            'title' => $title,
            'storage_key' => $storageKey,
            'content_type' => $contentType,
            'price_amount' => $priceAmount,
            'price_currency' => $priceCurrency,
            'id' => $existing['id'],
        ]);

        return (int) $existing['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNodeId(int $nodeId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM cv_documents WHERE node_id = :node_id');
        $statement->execute(['node_id' => $nodeId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM cv_documents WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
