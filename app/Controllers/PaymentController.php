<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Cv\CvAccessService;
use App\Services\Payment\PaymentService;

final class PaymentController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly CvAccessService $cvAccess,
    ) {
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
    }
}
