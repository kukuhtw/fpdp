<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OrderItemRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(int $orderId, int $productId, array $productSnapshot, int $quantity, string $unitPrice, string $subtotal): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO order_items (order_id, product_id, product_snapshot, quantity, unit_price, subtotal)
             VALUES (:order_id, :product_id, :product_snapshot, :quantity, :unit_price, :subtotal)',
        );
        $statement->execute([
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_snapshot' => json_encode($productSnapshot),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByProductId(int $productId): array
    {
        $statement = $this->connection->prepare(
            'SELECT oi.*, o.status AS order_status, o.public_id AS order_public_id, o.visitor_id AS order_visitor_id
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = :product_id
             ORDER BY oi.id DESC',
        );
        $statement->execute(['product_id' => $productId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderId(int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT oi.*, p.public_id AS product_public_id
             FROM order_items oi
             INNER JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC',
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchAll();
    }
}