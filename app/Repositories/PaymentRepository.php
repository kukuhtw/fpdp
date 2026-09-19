<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

/**
 * Persists gateway-agnostic payment state (`payments`) and the individual
 * webhook/status events received for it (`payment_transactions`), so a
 * payment's lifecycle survives beyond a single gateway response array.
 */
final class PaymentRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function create(
        string $uuid,
        string $orderId,
        string $gatewayCode,
        ?string $externalTransactionId,
        ?string $paymentMethod,
        string $currency,
        float $amount,
        string $status,
        ?string $paymentUrl,
        ?string $expiredAt,
        ?array $metadata,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO payments (uuid, order_id, gateway_code, external_transaction_id, payment_method,
                                    currency, amount, status, payment_url, expired_at, metadata)
             VALUES (:uuid, :order_id, :gateway_code, :external_transaction_id, :payment_method,
                     :currency, :amount, :status, :payment_url, :expired_at, :metadata)',
        );
        $statement->execute([
            'uuid' => $uuid,
            'order_id' => $orderId,
            'gateway_code' => $gatewayCode,
            'external_transaction_id' => $externalTransactionId,
            'payment_method' => $paymentMethod,
            'currency' => $currency,
            'amount' => $amount,
            'status' => $status,
            'payment_url' => $paymentUrl,
            'expired_at' => $expiredAt,
            'metadata' => $metadata !== null ? json_encode($metadata) : null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(string $orderId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM payments WHERE order_id = :order_id');
        $statement->execute(['order_id' => $orderId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM payments WHERE uuid = :uuid');
        $statement->execute(['uuid' => $uuid]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function markStatus(int $id, string $status): void
    {
        $paidAtAssignment = $status === 'PAID' ? ', paid_at = CURRENT_TIMESTAMP' : '';
        $statement = $this->connection->prepare(
            "UPDATE payments SET status = :status{$paidAtAssignment}, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
        );
        $statement->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Records one webhook/status event for a payment. Returns false without
     * writing anything if this (provider, external_id) pair was already
     * recorded, so callers can treat retried webhook deliveries as a safe,
     * side-effect-free no-op instead of re-applying the same state change.
     */
    public function recordTransactionEvent(
        int $paymentId,
        string $provider,
        ?string $externalId,
        string $eventType,
        string $status,
        ?string $payloadJson,
    ): bool {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO payment_transactions (payment_id, provider, external_id, event_type, status, payload)
                 VALUES (:payment_id, :provider, :external_id, :event_type, :status, :payload)',
            );
            $statement->execute([
                'payment_id' => $paymentId,
                'provider' => $provider,
                'external_id' => $externalId,
                'event_type' => $eventType,
                'status' => $status,
                'payload' => $payloadJson,
            ]);

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return false;
        }
    }
}
