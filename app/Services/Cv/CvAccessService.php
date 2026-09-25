<?php

declare(strict_types=1);

namespace App\Services\Cv;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\PaymentRequiredException;
use App\Core\Exceptions\ValidationException;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Repositories\NodeRepository;
use App\Services\Payment\PaymentService;
use RuntimeException;

/**
 * Visitor-facing side of paid CV/resume access: pricing, payment, grants,
 * and reading the stored file for a visitor who already has access.
 *
 * Payment integration note: the gateway used is the node's active gateway
 * (set via PUT /api/v1/me/payment-gateways/{code}/activate, the same
 * selection used for orders elsewhere). A priced CV cannot be sold until
 * the owner has activated one — see resolveGatewayCode(). DUMMY, if
 * explicitly activated, has no asynchronous confirmation step, so
 * grantAccess() still confirms it synchronously (for local dev/tests). Any
 * other gateway (e.g. PAYWUZ) is asynchronous: grantAccess() only creates a
 * PENDING payment and returns `granted: false`; the actual grant happens
 * later, when PaymentController::webhook() verifies the gateway's
 * payment-confirmation webhook and calls confirmPayment().
 */
final class CvAccessService
{
    private const SYNCHRONOUS_GATEWAY = 'DUMMY';

    public function __construct(
        private readonly CvDocumentRepository $documents,
        private readonly CvAccessGrantRepository $grants,
        private readonly PaymentService $payments,
        private readonly NodeRepository $nodes,
        private readonly string $storageDirectory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocumentForNode(int $nodeId): array
    {
        $document = $this->documents->findByNodeId($nodeId);
        if ($document === null) {
            throw new NotFoundException('This profile has not published a CV.');
        }

        return $document;
    }

    /**
     * @return array{document: array<string, mixed>, price_amount: float, has_access: bool}
     */
    public function describe(int $nodeId, ?int $visitorId): array
    {
        $document = $this->getDocumentForNode($nodeId);
        $priceAmount = (float) $document['price_amount'];

        $hasAccess = $priceAmount <= 0.0;
        if (!$hasAccess && $visitorId !== null) {
            $hasAccess = $this->grants->find((int) $document['id'], $visitorId) !== null;
        }

        return ['document' => $document, 'price_amount' => $priceAmount, 'has_access' => $hasAccess];
    }

    /**
     * Grants a visitor access to a node's CV, paying for it first if priced.
     * Idempotent: a visitor who already has access is simply confirmed as such.
     *
     * @return array{granted: bool, payment: array<string, mixed>|null}
     */
    public function grantAccess(int $nodeId, int $visitorId, string $visitorEmail, ?string $returnUrl = null, ?string $cancelUrl = null, ?string $buyerName = null, ?string $buyerPhone = null): array
    {
        $document = $this->getDocumentForNode($nodeId);
        $documentId = (int) $document['id'];
        $priceAmount = (float) $document['price_amount'];

        if ($this->grants->find($documentId, $visitorId) !== null) {
            return ['granted' => true, 'payment' => null];
        }

        if ($priceAmount <= 0.0) {
            $this->grants->grant($documentId, $visitorId, null);

            return ['granted' => true, 'payment' => null];
        }

        $gatewayCode = $this->resolveGatewayCode($nodeId);

        if ($buyerName === null || trim($buyerName) === '' || $buyerPhone === null || trim($buyerPhone) === '') {
            throw new ValidationException([['field' => 'name', 'reason' => 'required'], ['field' => 'phone', 'reason' => 'required']]);
        }

        $payment = $this->payments->createPayment($gatewayCode, [
            'order_id' => sprintf('CV-%d-%d-%d', $documentId, $visitorId, time()),
            'amount' => $priceAmount,
            'currency' => $document['price_currency'],
            'description' => 'CV access: ' . $document['title'],
            'payer_email' => $visitorEmail,
            'metadata' => [
                'purpose' => 'cv_access',
                'document_id' => $documentId,
                'visitor_id' => $visitorId,
                'buyer_name' => $buyerName,
                'buyer_phone' => $buyerPhone,
                'buyer_email' => $visitorEmail,
            ],
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);

        if ($gatewayCode === self::SYNCHRONOUS_GATEWAY) {
            $this->grants->grant($documentId, $visitorId, (string) ($payment['payment_id'] ?? ''));

            return ['granted' => true, 'payment' => $payment];
        }

        // Asynchronous gateway: access is granted later by confirmPayment(),
        // once PaymentController::webhook() verifies the payment succeeded.
        return ['granted' => false, 'payment' => $payment];
    }

    /**
     * Called by PaymentController::webhook() once a payment for this CV has
     * been confirmed as PAID. Idempotent via CvAccessGrantRepository::grant().
     */
    public function confirmPayment(int $documentId, int $visitorId, ?string $paymentReference): void
    {
        $this->grants->grant($documentId, $visitorId, $paymentReference);
    }

    /**
     * The gateway to charge for this node's priced CV: whichever one the
     * owner has activated (PaymentService::setActiveGateway(), the same
     * selection orders use). Refuses to fall back to a default — without an
     * explicitly activated gateway there is nothing that can actually
     * collect payment, so "buy access" must not be allowed to proceed.
     */
    private function resolveGatewayCode(int $nodeId): string
    {
        $gatewayCode = $this->nodes->getActiveGateway($nodeId);
        if ($gatewayCode === null || $gatewayCode === '') {
            throw new ConflictException(
                'This CV is priced, but no payment gateway is active yet. Activate one under Settings > Payments before it can be sold.',
            );
        }

        return strtoupper($gatewayCode);
    }

    /**
     * @return array{content: string, content_type: string, filename: string}
     */
    public function readForDownload(int $nodeId, int $visitorId): array
    {
        $document = $this->getDocumentForNode($nodeId);
        $documentId = (int) $document['id'];
        $priceAmount = (float) $document['price_amount'];

        if ($priceAmount > 0.0 && $this->grants->find($documentId, $visitorId) === null) {
            throw new PaymentRequiredException('Pay to access this CV before downloading it.');
        }

        $path = rtrim($this->storageDirectory, '/') . '/' . $document['storage_key'];
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Unable to read stored CV file: {$path}");
        }

        return [
            'content' => $content,
            'content_type' => $document['content_type'],
            'filename' => $document['title'] . self::extensionFor($document['content_type']),
        ];
    }

    private static function extensionFor(string $contentType): string
    {
        return match ($contentType) {
            'application/pdf' => '.pdf',
            'application/msword' => '.doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            default => '',
        };
    }
}
