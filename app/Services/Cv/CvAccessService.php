<?php

declare(strict_types=1);

namespace App\Services\Cv;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\PaymentRequiredException;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Services\Payment\PaymentService;
use RuntimeException;

/**
 * Visitor-facing side of paid CV/resume access: pricing, payment, grants,
 * and reading the stored file for a visitor who already has access.
 *
 * Payment integration note: PaymentService::createPayment() does not yet
 * persist a `payments` row (Phase 5 of the main roadmap has not built that
 * layer), so `payment_reference` stores the gateway's returned payment_id
 * string rather than a foreign key. The only gateway implemented today is
 * DUMMY, which has no asynchronous confirmation step, so a successful
 * createPayment() call is treated as confirmed for this MVP; a real
 * production gateway must switch this to webhook-driven confirmation
 * before going live (see AI-MONETIZATION-STRATEGY.en.md Section 10).
 */
final class CvAccessService
{
    private const GATEWAY_CODE = 'DUMMY';

    public function __construct(
        private readonly CvDocumentRepository $documents,
        private readonly CvAccessGrantRepository $grants,
        private readonly PaymentService $payments,
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
    public function grantAccess(int $nodeId, int $visitorId, string $visitorEmail): array
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

        $payment = $this->payments->createPayment(self::GATEWAY_CODE, [
            'order_id' => sprintf('CV-%d-%d-%d', $documentId, $visitorId, time()),
            'amount' => $priceAmount,
            'currency' => $document['price_currency'],
            'description' => 'CV access: ' . $document['title'],
            'payer_email' => $visitorEmail,
        ]);

        $this->grants->grant($documentId, $visitorId, (string) ($payment['payment_id'] ?? ''));

        return ['granted' => true, 'payment' => $payment];
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
