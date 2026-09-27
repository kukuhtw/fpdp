<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * What this node's owner ordered from other FPDP nodes (buyer side of
 * node-to-node orders). The seller's node stays the source of truth; rows
 * here are refreshed from it.
 */
final class FederatedPurchaseRepository
{
    public const OPEN_STATUSES = ['SUBMITTING', 'PENDING', 'CONFIRMED', 'PROCESSING'];

    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function create(array $row): array
    {
        $statement = $this->connection->prepare(
            'INSERT INTO federated_purchases (public_id, node_id, seller_domain, seller_actor_uri, order_endpoint, product_object_uri,
                                              items, total_amount, currency, status, buyer_name, buyer_email, shipping_address, notes)
             VALUES (:public_id, :node_id, :seller_domain, :seller_actor_uri, :order_endpoint, :product_object_uri,
                     :items, :total_amount, :currency, :status, :buyer_name, :buyer_email, :shipping_address, :notes)',
        );
        $statement->execute([
            'public_id' => $row['public_id'],
            'node_id' => $row['node_id'],
            'seller_domain' => $row['seller_domain'],
            'seller_actor_uri' => $row['seller_actor_uri'],
            'order_endpoint' => $row['order_endpoint'],
            'product_object_uri' => $row['product_object_uri'] ?? null,
            'items' => json_encode($row['items'], JSON_UNESCAPED_UNICODE),
            'total_amount' => $row['total_amount'],
            'currency' => $row['currency'],
            'status' => $row['status'] ?? 'SUBMITTING',
            'buyer_name' => $row['buyer_name'] ?? null,
            'buyer_email' => $row['buyer_email'] ?? null,
            'shipping_address' => $row['shipping_address'] ?? null,
            'notes' => $row['notes'] ?? null,
        ]);

        return $this->findByPublicId((string) $row['public_id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM federated_purchases WHERE public_id = :public_id');
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();

        return $row === false ? null : self::decode($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByNode(int $nodeId, int $limit = 50): array
    {
        $statement = $this->connection->prepare('SELECT * FROM federated_purchases WHERE node_id = :node_id ORDER BY id DESC LIMIT ' . max(1, $limit));
        $statement->execute(['node_id' => $nodeId]);

        return array_map([self::class, 'decode'], $statement->fetchAll());
    }

    /**
     * Open purchases not refreshed since $before, oldest first (for the cron).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOpenForSync(string $before, int $limit = 50): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM federated_purchases
             WHERE status IN ('SUBMITTING', 'PENDING', 'CONFIRMED', 'PROCESSING')
               AND (last_synced_at IS NULL OR last_synced_at < :before)
             ORDER BY COALESCE(last_synced_at, created_at) ASC LIMIT " . max(1, $limit),
        );
        $statement->execute(['before' => $before]);

        return array_map([self::class, 'decode'], $statement->fetchAll());
    }

    /**
     * @param array<string, mixed> $fields column => value (items/downloads as arrays)
     */
    public function update(string $publicId, array $fields): array
    {
        $allowed = ['remote_order_id', 'items', 'total_amount', 'currency', 'status', 'payment_url', 'downloads', 'last_error', 'last_synced_at'];
        $sets = [];
        $parameters = ['public_id' => $publicId];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $sets[] = "{$column} = :{$column}";
            $parameters[$column] = in_array($column, ['items', 'downloads'], true) && $value !== null ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
        }
        if ($sets !== []) {
            $this->connection->prepare('UPDATE federated_purchases SET ' . implode(', ', $sets) . ' WHERE public_id = :public_id')->execute($parameters);
        }

        return $this->findByPublicId($publicId);
    }

    public function delete(string $publicId): void
    {
        $this->connection->prepare('DELETE FROM federated_purchases WHERE public_id = :public_id')->execute(['public_id' => $publicId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        foreach (['items', 'downloads'] as $column) {
            $decoded = is_string($row[$column] ?? null) ? json_decode($row[$column], true) : null;
            $row[$column] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }
}
