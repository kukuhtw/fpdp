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

    public function create(string $publicId, int $nodeId, string $title, ?string $description, string $price, string $currency = 'IDR', ?array $media = null): array
    {
        $statement = $this->connection->prepare(
            'INSERT INTO products (public_id, node_id, title, description, price, currency, media)
             VALUES (:public_id, :node_id, :title, :description, :price, :currency, :media)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'node_id' => $nodeId,
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'currency' => $currency,
            'media' => $media !== null ? json_encode($media) : null,
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

        return $row === false ? null : $row;
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

        return $statement->fetchAll();
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

        return $statement->fetchAll();
    }

    public function update(string $publicId, array $fields): array
    {
        $assignments = [];
        $parameters = ['public_id' => $publicId];

        foreach (['title', 'description', 'price', 'currency', 'status', 'visibility', 'media'] as $column) {
            if (array_key_exists($column, $fields)) {
                $assignments[] = $column . ' = :' . $column;
                $parameters[$column] = $column === 'media' ? json_encode($fields[$column]) : $fields[$column];
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