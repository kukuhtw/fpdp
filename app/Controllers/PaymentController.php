<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Chatbot\VisitorWalletService;
use App\Services\Cv\CvAccessService;
use App\Services\Marketplace\MarketplaceService;
use App\Services\Payment\PaymentFulfillmentService;
use App\Services\Payment\PaymentService;

final class PaymentController
{
    private readonly PaymentFulfillmentService $fulfillment;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PaymentService $payments,
        CvAccessService $cvAccess,
        ?MarketplaceService $marketplace = null,
        ?VisitorWalletService $wallet = null,
    ) {
        $this->fulfillment = new PaymentFulfillmentService($cvAccess, $marketplace, $wallet);
    }

    /**
     * GET /api/v1/me/dashboard/payments
     *
     * Owner-only Payments dashboard: balance/settlement approximation,
     * transaction success rate, and recent transactions.
     */
    public function summary(Request $request): Response
    {
        $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->payments->getPaymentsSummary());
    }

    /**
     * GET /api/v1/me/payments/pending
     *
     * Owner-only: every payment awaiting confirmation, across all three
     * checkout flows (product order, CV access, wallet top-up) — chiefly
     * for gateways like Manual Transfer that have no automatic webhook.
     */
    public function listPending(Request $request): Response
    {
        $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->payments->listPendingPayments());
    }

    /**
     * POST /api/v1/me/payments/{uuid}/confirm
     *
     * Owner-only manual confirmation: marks a PENDING payment PAID and runs
     * the same fulfillment dispatch a gateway webhook would (grant CV
     * access, complete the order, credit the wallet) — see fulfill() below.
     *
     * @param array<string, string> $params
     */
    public function confirmPayment(Request $request, array $params): Response
    {
        $this->auth->authenticate($request->bearerToken());

        $result = $this->payments->confirmPaymentManually((string) ($params['uuid'] ?? ''));

        if ($result['status_changed'] && $this->isConfirmedPaid($result['payment'])) {
            $this->fulfillment->fulfill($result['payment']);
        }

        return JsonEnvelope::success($result['payment']);
    }

    /**
     * POST /api/v1/me/payments/{uuid}/cancel
     *
     * Owner-only: cancels a PENDING payment (at the gateway when it has a
     * cancel API, locally otherwise) and closes its order.
     *
     * @param array<string, string> $params
     */
    public function cancelPayment(Request $request, array $params): Response
    {
        $this->auth->authenticate($request->bearerToken());

        $result = $this->payments->cancelPaymentByOwner((string) ($params['uuid'] ?? ''));
        if ($result['status_changed']) {
            $this->fulfillment->cancel($result['payment']);
        }

        return JsonEnvelope::success([
            'payment' => $result['payment'],
            'provider_cancelled' => $result['provider_cancelled'],
            'provider_message' => $result['provider_message'],
        ]);
    }

    /**
     * POST /api/v1/me/payments/{uuid}/refund
     *
     * Owner-only: refunds a PAID payment in full (no `amount`) or in part.
     * `"manual": true` records a refund the owner already made outside the
     * gateway. A full refund also undoes what the payment bought.
     *
     * @param array<string, string> $params
     */
    public function refundPayment(Request $request, array $params): Response
    {
        $this->auth->authenticate($request->bearerToken());

        $uuid = (string) ($params['uuid'] ?? '');
        $input = $request->json() ?? [];
        $amount = $input['amount'] ?? null;
        if ($amount !== null && !is_int($amount) && !is_float($amount)) {
            throw new ValidationException([['field' => 'amount', 'reason' => 'invalid_value']]);
        }
        $manual = ($input['manual'] ?? false) === true;

        $payment = $this->payments->findPaymentOrFail($uuid);
        $remaining = (float) $payment['amount'] - (float) ($payment['refunded_amount'] ?? 0);
        if ($amount === null || abs($remaining - (float) $amount) < 0.005) {
            $this->fulfillment->assertReversible($payment);
        }

        $result = $this->payments->refundPaymentByOwner($uuid, $amount === null ? null : (float) $amount, $manual);
        $notes = $result['fully_refunded'] ? $this->fulfillment->reverse($result['payment']) : [];

        return JsonEnvelope::success([
            'payment' => $result['payment'],
            'refunded_now' => $result['refunded_now'],
            'fully_refunded' => $result['fully_refunded'],
            'notes' => $notes,
        ]);
    }

    /**
     * POST /api/v1/me/payments/reconcile
     *
     * Owner-only: runs the same check as scripts/reconcile-payments.php on
     * demand and fulfills any payment the provider reports as PAID.
     */
    public function reconcile(Request $request): Response
    {
        $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->reconcileAndFulfill());
    }

    /**
     * @return array<string, mixed>
     */
    public function reconcileAndFulfill(int $minAgeMinutes = 15, int $limit = 100): array
    {
        $report = $this->payments->reconcile($minAgeMinutes, $limit);
        foreach ($report['updated'] as $payment) {
            if ($this->isConfirmedPaid($payment)) {
                $this->fulfillment->fulfill($payment);
            } elseif ((string) ($payment['status'] ?? '') === 'CANCELLED') {
                $this->fulfillment->cancel($payment);
            }
        }

        return $report;
    }

    /**
     * GET /api/v1/me/payment-gateways
     *
     * Owner-only list of supported gateways and which environment(s) have
     * credentials configured. Never returns a decrypted secret.
     */
    public function listGateways(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->payments->listGatewaySettings((int) $context['node']['id']));
    }

    /**
     * PATCH /api/v1/me/payment-gateways/{code}
     *
     * Owner-only: store (encrypted) credentials for one gateway/environment
     * and make that environment active.
     *
     * @param array<string, string> $params
     */
    public function updateGateway(Request $request, array $params): Response
    {
        $this->auth->authenticate($request->bearerToken());

        $input = $request->json() ?? [];
        $environment = (string) ($input['environment'] ?? 'SANDBOX');
        $config = is_array($input['config'] ?? null) ? $input['config'] : [];

        return JsonEnvelope::success(
            $this->payments->updateGatewaySettings((string) ($params['code'] ?? ''), $environment, $config),
        );
    }

    /**
     * PUT /api/v1/me/payment-gateways/{code}/activate
     *
     * Set a configured gateway as the node's default for all checkout flows
     * (orders, CV access, etc.). The gateway must already have credentials
     * stored via updateGateway. Pass code=null in the body to clear.
     *
     * @param array<string, string> $params
     */
    public function activateGateway(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success(
            $this->payments->setActiveGateway((int) $context['node']['id'], (string) ($params['code'] ?? null) ?: null),
        );
    }

    /**
     * POST /api/v1/payments/webhook/{gateway}
     *
     * Public gateway-delivery endpoint — no bearer auth, since the caller is
     * the payment provider's own server, not one of our users. Signature
     * verification (per gateway) is what authenticates the request instead.
     *
     * @param array<string, string> $params
     */
    public function webhook(Request $request, array $params): Response
    {
        $gatewayCode = strtoupper((string) ($params['gateway'] ?? ''));
        $result = $this->payments->handleWebhook($gatewayCode, $request->headers, $request->body ?? '');

        if (!$result['duplicate'] && $result['status_changed'] && $result['payment'] !== null) {
            match ((string) ($result['payment']['status'] ?? '')) {
                'PAID' => $this->fulfillment->fulfill($result['payment']),
                'REFUNDED' => $this->fulfillment->reverse($result['payment']),
                'CANCELLED' => $this->fulfillment->cancel($result['payment']),
                default => null,
            };
        }

        return JsonEnvelope::success(['received' => true, 'duplicate' => $result['duplicate']]);
    }

    /**
     * @param array<string, mixed>|null $payment
     */
    private function isConfirmedPaid(?array $payment): bool
    {
        return $payment !== null && (string) ($payment['status'] ?? '') === 'PAID';
    }
}
