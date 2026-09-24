<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Cv\CvAccessService;
use App\Services\Marketplace\MarketplaceService;
use App\Services\Payment\PaymentService;

final class PaymentController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PaymentService $payments,
        private readonly CvAccessService $cvAccess,
        private readonly ?MarketplaceService $marketplace = null,
    ) {
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

        if (!$result['duplicate'] && $result['status_changed'] && $this->isConfirmedPaid($result['payment'])) {
            $this->fulfill($result['payment']);
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

    /**
     * Dispatches a confirmed payment to whichever feature it was created
     * for, based on the `purpose` recorded in its metadata at creation time.
     *
     * @param array<string, mixed> $payment
     */
    private function fulfill(array $payment): void
    {
        $rawMetadata = $payment['metadata'] ?? null;
        $metadata = is_string($rawMetadata) ? json_decode($rawMetadata, true) : null;
        if (!is_array($metadata)) {
            return;
        }

        if (($metadata['purpose'] ?? null) === 'cv_access') {
            $documentId = (int) ($metadata['document_id'] ?? 0);
            $visitorId = (int) ($metadata['visitor_id'] ?? 0);
            if ($documentId > 0 && $visitorId > 0) {
                $this->cvAccess->confirmPayment($documentId, $visitorId, (string) $payment['order_id']);
            }
        }

        if (($metadata['purpose'] ?? null) === 'marketplace_order') {
            $orderPublicId = (string) ($metadata['order_public_id'] ?? '');
            if ($orderPublicId !== '') {
                $this->marketplace?->confirmPayment($orderPublicId, (string) $payment['order_id']);
            }
        }
    }
}
