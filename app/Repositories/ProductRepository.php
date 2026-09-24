<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ProductRepository
{
    private const SELECT = '
        SELECT p.*, n.domain AS node_domain
        FROM products p
        INNER JOIN nodes n ON n.id = p.node_id
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $nodeId, string $title, ?string $description, string $price, string $currency = 'IDR', ?array $media = null, string $productType = 'PHYSICAL', ?string $digitalAssetUrl = null, ?array $digitalAssetMetadata = null, bool $isPromoted = false, string $status = 'ACTIVE', string $visibility = 'PUBLIC'): array
    {
        $statement = $this->connection->prepare(
            'INSERT INTO products (public_id, node_id, title, description, price, currency, product_type, digital_asset_url, digital_asset_metadata, media, is_promoted, status, visibility)
             VALUES (:public_id, :node_id, :title, :description, :price, :currency, :product_type, :digital_asset_url, :digital_asset_metadata, :media, :is_promoted, :status, :visibility)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'node_id' => $nodeId,
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'currency' => $currency,
            'product_type' => $productType,
            'digital_asset_url' => $digitalAssetUrl,
            'digital_asset_metadata' => $digitalAssetMetadata !== null ? json_encode($digitalAssetMetadata) : null,
            'media' => $media !== null ? json_encode($media) : null,
            'is_promoted' => $isPromoted ? 1 : 0,
            'status' => $status,
            'visibility' => $visibility,
        ]);

        return $this->findByPublicId($publicId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            self::SELECT . " WHERE p.public_id = :public_id AND p.status != 'ARCHIVED'",
        );
        $statement->execute(['public_id' => $publicId]);

        $row = $statement->fetch();

        return $row === false ? null : self::decodeJsonColumns($row);
    }

    /**
     * `media`/`digital_asset_metadata` are stored as JSON text columns; PDO
     * returns them as raw strings, but every API response and view that
     * reads a product (shop listing, product detail, the owner's product
     * list) expects an actual array — decode them once here rather than at
     * every call site.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decodeJsonColumns(array $row): array
    {
        foreach (['media', 'digital_asset_metadata'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $decoded = json_decode($row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : null;
            }
        }

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublic(int $nodeId, int $limit, ?int $beforeId = null): array
    {
        $where = ["p.node_id = :node_id", "p.status = 'ACTIVE'", "p.visibility = 'PUBLIC'"];
        $parameters = ['node_id' => $nodeId];

        if ($beforeId !== null) {
            $where[] = 'p.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        return array_map([self::class, 'decodeJsonColumns'], $statement->fetchAll());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByNodeId(int $nodeId): array
    {
        $statement = $this->connection->prepare(
            self::SELECT . " WHERE p.node_id = :node_id AND p.status != 'ARCHIVED' ORDER BY p.id DESC",
        );
        $statement->execute(['node_id' => $nodeId]);

        return array_map([self::class, 'decodeJsonColumns'], $statement->fetchAll());
    }

    public function update(string $publicId, array $fields): array
    {
        $assignments = [];
        $parameters = ['public_id' => $publicId];

        foreach (['title', 'description', 'price', 'currency', 'product_type', 'digital_asset_url', 'digital_asset_metadata', 'status', 'visibility', 'media', 'is_promoted'] as $column) {
            if (array_key_exists($column, $fields)) {
                $assignments[] = $column . ' = :' . $column;
                $parameters[$column] = match (true) {
                    in_array($column, ['media', 'digital_asset_metadata'], true) => json_encode($fields[$column]),
                    $column === 'is_promoted' => $fields[$column] ? 1 : 0,
                    default => $fields[$column],
                };
            }
        }

        if ($assignments !== []) {
            $statement = $this->connection->prepare(
                'UPDATE products SET ' . implode(', ', $assignments) . ' WHERE public_id = :public_id',
            );
            $statement->execute($parameters);
        }

        return $this->findByPublicId($publicId);
    }
}