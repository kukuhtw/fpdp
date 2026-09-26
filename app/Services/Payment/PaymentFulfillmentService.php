<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Exceptions\ConflictException;
use App\Services\Chatbot\VisitorWalletService;
use App\Services\Cv\CvAccessService;
use App\Services\Marketplace\MarketplaceService;

/**
 * Applies, or undoes, whatever a payment was for — based on the `purpose`
 * recorded in its metadata at creation time. Shared by every path that
 * changes a payment's status: gateway webhooks, the owner's manual
 * confirm/cancel/refund, and scripts/reconcile-payments.php.
 */
final class PaymentFulfillmentService
{
    public function __construct(
        private readonly CvAccessService $cvAccess,
        private readonly ?MarketplaceService $marketplace = null,
        private readonly ?VisitorWalletService $wallet = null,
    ) {
    }

    /**
     * A payment became PAID: grant CV access, complete the order, or credit
     * the wallet.
     *
     * @param array<string, mixed> $payment
     */
    public function fulfill(array $payment): void
    {
        $metadata = self::metadata($payment);
        $purpose = $metadata['purpose'] ?? null;

        if ($purpose === 'cv_access') {
            $documentId = (int) ($metadata['document_id'] ?? 0);
            $visitorId = (int) ($metadata['visitor_id'] ?? 0);
            if ($documentId > 0 && $visitorId > 0) {
                $this->cvAccess->confirmPayment($documentId, $visitorId, (string) $payment['order_id']);
            }
        }

        if ($purpose === 'marketplace_order') {
            $orderPublicId = (string) ($metadata['order_public_id'] ?? '');
            if ($orderPublicId !== '') {
                $this->marketplace?->confirmPayment($orderPublicId, (string) $payment['order_id']);
            }
        }

        if ($purpose === 'wallet_topup') {
            $walletId = (int) ($metadata['wallet_id'] ?? 0);
            if ($walletId > 0) {
                $this->wallet?->confirmTopUp($walletId, (string) $payment['amount'], (string) $payment['order_id']);
            }
        }
    }

    /**
     * Throws before an owner refund reaches the gateway if its effect could
     * not be undone afterwards — today only a wallet top-up the visitor has
     * already partly spent. Refunding it would hand back money for chat the
     * visitor already used.
     *
     * @param array<string, mixed> $payment
     */
    public function assertReversible(array $payment): void
    {
        $metadata = self::metadata($payment);
        if (($metadata['purpose'] ?? null) !== 'wallet_topup' || $this->wallet === null) {
            return;
        }

        $walletId = (int) ($metadata['wallet_id'] ?? 0);
        if ($walletId > 0 && !$this->wallet->canReverseTopUp($walletId, (string) $payment['amount'])) {
            throw new ConflictException(
                'The visitor has already spent part of this top-up, so the wallet no longer holds the full amount. '
                . 'Refund a partial amount instead, or record a manual refund after adjusting the wallet.',
            );
        }
    }

    /**
     * A payment was fully refunded: revoke CV access, mark the order
     * REFUNDED, or take the top-up back out of the wallet. Returns notes on
     * anything that could not be undone (a webhook refund of a top-up the
     * visitor already spent), for the caller to show or log.
     *
     * @param array<string, mixed> $payment
     * @return array<int, string>
     */
    public function reverse(array $payment): array
    {
        $metadata = self::metadata($payment);
        $purpose = $metadata['purpose'] ?? null;
        $notes = [];

        if ($purpose === 'cv_access') {
            $documentId = (int) ($metadata['document_id'] ?? 0);
            $visitorId = (int) ($metadata['visitor_id'] ?? 0);
            if ($documentId > 0 && $visitorId > 0) {
                $this->cvAccess->revokeAccess($documentId, $visitorId);
            }
        }

        if ($purpose === 'marketplace_order') {
            $orderPublicId = (string) ($metadata['order_public_id'] ?? '');
            if ($orderPublicId !== '') {
                $this->marketplace?->closeOrderForPayment($orderPublicId, 'REFUNDED');
            }
        }

        if ($purpose === 'wallet_topup' && $this->wallet !== null) {
            $walletId = (int) ($metadata['wallet_id'] ?? 0);
            if ($walletId > 0 && !$this->wallet->reverseTopUp($walletId, (string) $payment['amount'], (string) $payment['order_id'])) {
                $notes[] = 'Wallet balance no longer covers the refunded top-up; the wallet was not debited.';
            }
        }

        return $notes;
    }

    /**
     * A PENDING payment was cancelled: close its order so it no longer shows
     * as awaiting payment. CV access and top-ups granted nothing yet.
     *
     * @param array<string, mixed> $payment
     */
    public function cancel(array $payment): void
    {
        $metadata = self::metadata($payment);
        if (($metadata['purpose'] ?? null) === 'marketplace_order') {
            $orderPublicId = (string) ($metadata['order_public_id'] ?? '');
            if ($orderPublicId !== '') {
                $this->marketplace?->closeOrderForPayment($orderPublicId, 'CANCELLED');
            }
        }
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private static function metadata(array $payment): array
    {
        $raw = $payment['metadata'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        return is_array($decoded) ? $decoded : [];
    }
}
