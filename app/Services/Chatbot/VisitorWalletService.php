<?php

declare(strict_types=1);

namespace App\Services\Chatbot;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Repositories\NodeRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorWalletRepository;
use App\Services\Payment\PaymentService;

/**
 * Visitor deposit top-ups (paid, via the node's active payment gateway —
 * same pattern as CvAccessService/MarketplaceService) and owner-granted
 * credits (free, e.g. comping a visitor). Both just call
 * VisitorWalletRepository::credit(); the difference is only where the
 * money is asserted to have come from.
 */
final class VisitorWalletService
{
    private const SYNCHRONOUS_GATEWAY = 'DUMMY';
    private const MIN_TOPUP_AMOUNT = 1000.0;
    private const MAX_GRANT_NOTE_LENGTH = 255;

    public function __construct(
        private readonly VisitorWalletRepository $wallets,
        private readonly VisitorRepository $visitors,
        private readonly PaymentService $payments,
        private readonly NodeRepository $nodes,
    ) {
    }

    /**
     * @param array<string, mixed> $visitor
     * @return array{credited: bool, payment: array<string, mixed>, wallet: array<string, mixed>}
     */
    public function topUp(int $nodeId, array $visitor, float $amount, string $currency = 'IDR', ?string $returnUrlBase = null, ?string $cancelUrl = null, ?string $buyerName = null, ?string $buyerPhone = null): array
    {
        if ($amount < self::MIN_TOPUP_AMOUNT) {
            throw new ValidationException([['field' => 'amount', 'reason' => 'below_minimum']]);
        }
        if ($buyerName === null || trim($buyerName) === '' || $buyerPhone === null || trim($buyerPhone) === '') {
            throw new ValidationException([['field' => 'name', 'reason' => 'required'], ['field' => 'phone', 'reason' => 'required']]);
        }

        $visitorId = (int) $visitor['id'];
        $wallet = $this->wallets->getOrCreate($visitorId, $currency);

        $gatewayCode = $this->resolveGatewayCode($nodeId);
        $returnUrl = $returnUrlBase !== null ? $returnUrlBase . '&before=' . urlencode((string) $wallet['balance_amount']) : null;
        $payment = $this->payments->createPayment($gatewayCode, [
            'order_id' => sprintf('WALLET-%d-%d-%d', $wallet['id'], $visitorId, time()),
            'amount' => $amount,
            'currency' => $wallet['currency'],
            'description' => 'Deposit top-up',
            'payer_email' => $visitor['email'] ?? null,
            'metadata' => [
                'purpose' => 'wallet_topup',
                'wallet_id' => (int) $wallet['id'],
                'visitor_id' => $visitorId,
                'buyer_name' => $buyerName,
                'buyer_phone' => $buyerPhone,
                'buyer_email' => $visitor['email'] ?? null,
            ],
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);

        $credited = false;
        if ($gatewayCode === self::SYNCHRONOUS_GATEWAY) {
            $this->wallets->credit((int) $wallet['id'], (string) $amount, 'TOPUP', null, (string) ($payment['payment_id'] ?? ''));
            $credited = true;
        }

        return ['credited' => $credited, 'payment' => $payment, 'wallet' => $this->wallets->findById((int) $wallet['id']) ?? $wallet];
    }

    /**
     * Called by PaymentController::fulfill() once a wallet-topup payment
     * has been confirmed PAID by an asynchronous gateway's webhook.
     */
    public function confirmTopUp(int $walletId, string $amount, ?string $paymentReference): void
    {
        $this->wallets->credit($walletId, $amount, 'TOPUP', null, $paymentReference);
    }

    /**
     * Owner-only, free: credits a specific visitor's wallet directly, no
     * payment involved (e.g. a goodwill credit, a refund made by hand).
     *
     * @return array<string, mixed>
     */
    public function ownerGrant(int $nodeId, string $visitorPublicId, float $amount, ?string $note): array
    {
        if ($amount <= 0.0) {
            throw new ValidationException([['field' => 'amount', 'reason' => 'invalid_value']]);
        }
        if ($note !== null && mb_strlen($note) > self::MAX_GRANT_NOTE_LENGTH) {
            throw new ValidationException([['field' => 'note', 'reason' => 'invalid_length']]);
        }

        $visitor = $this->visitors->findByPublicId($visitorPublicId);
        if ($visitor === null) {
            throw new NotFoundException('Visitor not found.');
        }
        if ((int) $visitor['node_id'] !== $nodeId) {
            throw new ForbiddenException('This visitor does not belong to your node.');
        }

        $wallet = $this->wallets->getOrCreate((int) $visitor['id']);
        $this->wallets->credit((int) $wallet['id'], (string) $amount, 'OWNER_GRANT', null, $note);

        return $this->wallets->findById((int) $wallet['id']) ?? $wallet;
    }

    /**
     * Owner-only: visitors who've interacted with this node, each with
     * their current wallet balance, for the "give a visitor deposit" list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listVisitorsWithBalances(int $nodeId): array
    {
        $visitors = $this->visitors->listByNodeId($nodeId);

        return array_map(function (array $visitor): array {
            $wallet = $this->wallets->findByVisitorId((int) $visitor['id']);

            return [
                'id' => $visitor['public_id'],
                'email' => $visitor['email'],
                'display_name' => $visitor['display_name'],
                'last_seen_at' => $visitor['last_seen_at'],
                'balance_amount' => $wallet['balance_amount'] ?? '0',
                'currency' => $wallet['currency'] ?? 'IDR',
            ];
        }, $visitors);
    }

    /**
     * Mirrors CvAccessService::resolveGatewayCode() — no silent default.
     */
    private function resolveGatewayCode(int $nodeId): string
    {
        $gatewayCode = $this->nodes->getActiveGateway($nodeId);
        if ($gatewayCode === null || $gatewayCode === '') {
            throw new ConflictException('No payment gateway is active yet. Activate one under Settings > Payments before topping up.');
        }

        return strtoupper($gatewayCode);
    }
}
