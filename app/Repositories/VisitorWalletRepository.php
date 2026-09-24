<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Uuid;
use PDO;
use Throwable;

/**
 * Per-visitor deposit balance for paid chatbot conversations. Credits
 * (top-up, owner grant) and debits (chat cost) are both recorded as signed
 * `visitor_wallet_transactions` rows for an auditable ledger, while
 * `visitor_wallets.balance_amount` is the fast-path running total.
 *
 * debit() is a single conditional UPDATE (`WHERE balance_amount >= amount`)
 * rather than a read-then-write, so two concurrent requests against the
 * same wallet can't both succeed past a balance that only covers one of
 * them — no explicit row lock needed, and it works the same on SQLite
 * (tests) and MySQL (production).
 */
final class VisitorWalletRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrCreate(int $visitorId, string $currency = 'IDR'): array
    {
        $wallet = $this->findByVisitorId($visitorId);
        if ($wallet !== null) {
            return $wallet;
        }

        $statement = $this->connection->prepare(
            'INSERT INTO visitor_wallets (visitor_id, balance_amount, currency) VALUES (:visitor_id, 0, :currency)',
        );
        $statement->execute(['visitor_id' => $visitorId, 'currency' => $currency]);

        return $this->findByVisitorId($visitorId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByVisitorId(int $visitorId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM visitor_wallets WHERE visitor_id = :visitor_id');
        $statement->execute(['visitor_id' => $visitorId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM visitor_wallets WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function credit(int $walletId, string $amount, string $type, ?int $paymentId, ?string $note): void
    {
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE visitor_wallets SET balance_amount = balance_amount + :amount, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            );
            $statement->execute(['amount' => $amount, 'id' => $walletId]);
            $this->recordTransaction($walletId, $type, $amount, $paymentId, $note);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Returns false (no row changed, nothing recorded) if the wallet's
     * current balance is below $amount — the caller must treat that as
     * "insufficient balance", not retry.
     */
    public function debit(int $walletId, string $amount, string $type, ?string $note): bool
    {
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE visitor_wallets SET balance_amount = balance_amount - :amount1, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND balance_amount >= :amount2',
            );
            $statement->execute(['amount1' => $amount, 'amount2' => $amount, 'id' => $walletId]);

            if ($statement->rowCount() === 0) {
                $this->connection->rollBack();

                return false;
            }

            $this->recordTransaction($walletId, $type, '-' . $amount, null, $note);
            $this->connection->commit();

            return true;
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTransactions(int $walletId, int $limit = 20): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM visitor_wallet_transactions WHERE wallet_id = :wallet_id ORDER BY id DESC LIMIT ' . max(1, $limit),
        );
        $statement->execute(['wallet_id' => $walletId]);

        return $statement->fetchAll();
    }

    private function recordTransaction(int $walletId, string $type, string $amount, ?int $paymentId, ?string $note): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO visitor_wallet_transactions (public_id, wallet_id, type, amount, payment_id, note)
             VALUES (:public_id, :wallet_id, :type, :amount, :payment_id, :note)',
        );
        $statement->execute([
            'public_id' => Uuid::v4(),
            'wallet_id' => $walletId,
            'type' => $type,
            'amount' => $amount,
            'payment_id' => $paymentId,
            'note' => $note,
        ]);
    }
}
