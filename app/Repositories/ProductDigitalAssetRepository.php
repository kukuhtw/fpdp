<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Reads/writes `product_digital_assets`: at most one row per (product,
 * kind) — PDF and SOURCE_CODE are independent slots, re-uploading a kind
 * replaces it. Mirrors CvDocumentRepository's one-row-per-node upsert.
 */
final class ProductDigitalAssetRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function upsert(int $productId, string $kind, string $storageKey, ?string $originalFilename, string $contentType, int $sizeBytes): int
    {
        $existing = $this->findByProductAndKind($productId, $kind);

        if ($existing === null) {
            $statement = $this->connection->prepare(
                'INSERT INTO product_digital_assets (product_id, kind, storage_key, original_filename, content_type, size_bytes)
                 VALUES (:product_id, :kind, :storage_key, :original_filename, :content_type, :size_bytes)',
            );
            $statement->execute([
                'product_id' => $productId,
                'kind' => $kind,
                'storage_key' => $storageKey,
                'original_filename' => $originalFilename,
                'content_type' => $contentType,
                'size_bytes' => $sizeBytes,
            ]);

            return (int) $this->connection->lastInsertId();
        }

        $statement = $this->connection->prepare(
            'UPDATE product_digital_assets
             SET storage_key = :storage_key, original_filename = :original_filename,
                 content_type = :content_type, size_bytes = :size_bytes, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
        );
        $statement->execute([
            'storage_key' => $storageKey,
            'original_filename' => $originalFilename,
            'content_type' => $contentType,
            'size_bytes' => $sizeBytes,
            'id' => $existing['id'],
        ]);

        return (int) $existing['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProductAndKind(int $productId, string $kind): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM product_digital_assets WHERE product_id = :product_id AND kind = :kind',
        );
        $statement->execute(['product_id' => $productId, 'kind' => $kind]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByProduct(int $productId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM product_digital_assets WHERE product_id = :product_id ORDER BY kind ASC',
        );
        $statement->execute(['product_id' => $productId]);

        return $statement->fetchAll();
    }
}
