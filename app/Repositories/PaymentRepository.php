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
     * Adds $amount to the payment's refunded total and moves it to
     * $newStatus (PARTIALLY_REFUNDED or REFUNDED). The caller has already
     * validated that $amount does not exceed what is left to refund.
     */
    public function addRefund(int $id, float $amount, string $newStatus): void
    {
        $statement = $this->connection->prepare(
            'UPDATE payments SET refunded_amount = refunded_amount + :amount, status = :status,
                    refunded_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
        );
        $statement->execute(['amount' => $amount, 'status' => $newStatus, 'id' => $id]);
    }

    /**
     * Local CANCELLED/FAILED payments created in the last $days days, for
     * reconciliation to double-check against the provider (a visitor can
     * still complete a payment the owner already cancelled locally).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRecentlyClosedUnpaid(int $days, int $limit): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM payments WHERE status IN ('CANCELLED', 'FAILED') AND created_at >= :since
             ORDER BY id DESC LIMIT :limit",
        );
        $statement->bindValue('since', gmdate('Y-m-d H:i:s', time() - $days * 86400));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * PENDING payments created before $olderThan (UTC 'Y-m-d H:i:s'), oldest
     * first, for reconciliation against the provider's status API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPendingOlderThan(string $olderThan, int $limit): array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM payments WHERE status = 'PENDING' AND created_at <= :older_than ORDER BY id ASC LIMIT :limit",
        );
        $statement->bindValue('older_than', $olderThan);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Dashboard summary. `payments` has no node/profile scope (FPDP is
     * single-owner-per-deployment today, see the progress report), so this
     * is a global aggregate across the whole install, not per-tenant.
     * `available_balance` is PAID plus PARTIALLY_REFUNDED amounts minus what
     * was refunded from them, not a real settlement ledger — no fees,
     * payouts, or withdrawals are tracked.
     *
     * @return array{available_balance: float, pending_settlement: float, paid_count: int, failed_count: int, cancelled_count: int, refunded_count: int, refunded_amount: float, success_rate: float, revenue_this_month: float, currency: string}
     */
    public function getSummary(): array
    {
        $paid = $this->sumAndCountByStatus('PAID');
        $partiallyRefunded = $this->sumAndCountByStatus('PARTIALLY_REFUNDED');
        $refunded = $this->sumAndCountByStatus('REFUNDED');
        $pending = $this->sumAndCountByStatus('PENDING');
        $failed = $this->sumAndCountByStatus('FAILED');
        $cancelled = $this->sumAndCountByStatus('CANCELLED');

        // A refunded payment still succeeded as a payment, so it counts
        // toward the success rate; the refund shows up in the balance instead.
        $succeededCount = $paid['count'] + $partiallyRefunded['count'] + $refunded['count'];
        $terminalCount = $succeededCount + $failed['count'] + $cancelled['count'];
        $successRate = $terminalCount > 0 ? round($succeededCount / $terminalCount * 100, 2) : 0.0;

        $statement = $this->connection->prepare(
            "SELECT COALESCE(SUM(amount - refunded_amount), 0) FROM payments
             WHERE status IN ('PAID', 'PARTIALLY_REFUNDED') AND paid_at >= :month_start",
        );
        $statement->execute(['month_start' => gmdate('Y-m-01 00:00:00')]);
        $revenueThisMonth = (float) $statement->fetchColumn();

        return [
            'available_balance' => $paid['amount'] + $partiallyRefunded['amount'] - $partiallyRefunded['refunded'],
            'pending_settlement' => $pending['amount'],
            'paid_count' => $paid['count'] + $partiallyRefunded['count'],
            'failed_count' => $failed['count'],
            'cancelled_count' => $cancelled['count'],
            'refunded_count' => $refunded['count'] + $partiallyRefunded['count'],
            'refunded_amount' => $refunded['refunded'] + $partiallyRefunded['refunded'],
            'success_rate' => $successRate,
            'revenue_this_month' => $revenueThisMonth,
            'currency' => 'IDR',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecent(int $limit = 20): array
    {
        $statement = $this->connection->prepare('SELECT * FROM payments ORDER BY id DESC LIMIT :limit');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(string $status, int $limit = 50): array
    {
        $statement = $this->connection->prepare('SELECT * FROM payments WHERE status = :status ORDER BY id DESC LIMIT :limit');
        $statement->bindValue('status', $status);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @return array{count: int, amount: float, refunded: float}
     */
    private function sumAndCountByStatus(string $status): array
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(refunded_amount), 0) AS refunded
             FROM payments WHERE status = :status',
        );
        $statement->execute(['status' => $status]);
        $row = $statement->fetch();

        return ['count' => (int) $row['c'], 'amount' => (float) $row['total'], 'refunded' => (float) $row['refunded']];
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
