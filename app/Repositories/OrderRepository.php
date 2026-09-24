<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OrderRepository
{
    private const SELECT = '
        SELECT o.*, n.domain AS node_domain
        FROM orders o
        INNER JOIN nodes n ON n.id = o.node_id
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        string $publicId,
        int $nodeId,
        string $totalAmount,
        string $currency = 'IDR',
        ?string $buyerEmail = null,
        ?string $buyerName = null,
        ?string $notes = null,
        ?int $visitorId = null,
        ?string $shippingAddress = null,
    ): array {
        $statement = $this->connection->prepare(
            'INSERT INTO orders (public_id, node_id, visitor_id, buyer_email, buyer_name, total_amount, currency, notes, shipping_address)
             VALUES (:public_id, :node_id, :visitor_id, :buyer_email, :buyer_name, :total_amount, :currency, :notes, :shipping_address)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'node_id' => $nodeId,
            'visitor_id' => $visitorId,
            'buyer_email' => $buyerEmail,
            'buyer_name' => $buyerName,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'notes' => $notes,
            'shipping_address' => $shippingAddress,
        ]);

        return $this->findByPublicId($publicId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE o.public_id = :public_id',
        );
        $statement->execute(['public_id' => $publicId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByNodeId(int $nodeId, int $limit = 20, ?int $beforeId = null): array
    {
        $where = ['o.node_id = :node_id'];
        $parameters = ['node_id' => $nodeId];

        if ($beforeId !== null) {
            $where[] = 'o.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY o.id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function countByNodeId(int $nodeId, ?string $status = null): int
    {
        $where = ['node_id = :node_id'];
        $parameters = ['node_id' => $nodeId];
        if ($status !== null) {
            $where[] = 'status = :status';
            $parameters['status'] = $status;
        }

        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM orders WHERE ' . implode(' AND ', $where),
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function updateStatus(string $publicId, string $status): array
    {
        $statement = $this->connection->prepare(
            "UPDATE orders SET status = :status WHERE public_id = :public_id",
        );
        $statement->execute(['public_id' => $publicId, 'status' => $status]);

        return $this->findByPublicId($publicId);
    }

    /**
     * Marks an order paid/fulfilled and records the payment that did it —
     * called from the checkout path (synchronous gateway) or from
     * PaymentController's webhook fulfillment (asynchronous gateway),
     * never directly by an owner API call (see updateStatus() for that).
     */
    public function markPaid(string $publicId, string $status, ?string $paymentReference): array
    {
        $statement = $this->connection->prepare(
            'UPDATE orders SET status = :status, payment_reference = :payment_reference WHERE public_id = :public_id',
        );
        $statement->execute(['public_id' => $publicId, 'status' => $status, 'payment_reference' => $paymentReference]);

        return $this->findByPublicId($publicId);
    }
}