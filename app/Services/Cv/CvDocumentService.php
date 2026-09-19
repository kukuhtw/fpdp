<?php

declare(strict_types=1);

namespace App\Services\Cv;

use App\Core\Config;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use RuntimeException;

/**
 * Handles the owner-facing side of paid CV/resume access: validating and
 * storing the uploaded file outside the public webroot. Visitor-facing
 * access (pricing checks, grants, downloads) lives in CvAccessService.
 */
final class CvDocumentService
{
    private const ALLOWED_CURRENCIES = ['IDR', 'USD'];

    public function __construct(
        private readonly CvDocumentRepository $documents,
        private readonly CvAccessGrantRepository $grants,
        private readonly string $storageDirectory,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function upload(int $nodeId, array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $priceAmount = $input['price_amount'] ?? 0;
        $priceCurrency = strtoupper((string) ($input['price_currency'] ?? 'IDR'));
        $contentType = (string) ($input['content_type'] ?? 'application/pdf');
        $contentBase64 = (string) ($input['content_base64'] ?? '');

        $errors = [];

        if ($title === '' || mb_strlen($title) > 128) {
            $errors[] = ['field' => 'title', 'reason' => 'invalid_length'];
        }

        if (!is_numeric($priceAmount) || (float) $priceAmount < 0) {
            $errors[] = ['field' => 'price_amount', 'reason' => 'invalid_value'];
        }

        if (!in_array($priceCurrency, self::ALLOWED_CURRENCIES, true)) {
            $errors[] = ['field' => 'price_currency', 'reason' => 'unsupported_currency'];
        }

        if ($contentType === '' || mb_strlen($contentType) > 128) {
            $errors[] = ['field' => 'content_type', 'reason' => 'invalid_value'];
        }

        $decoded = $contentBase64 === '' ? false : base64_decode($contentBase64, true);
        if ($decoded === false || $decoded === '') {
            $errors[] = ['field' => 'content_base64', 'reason' => 'invalid_encoding'];
        }

        $maxBytes = (int) Config::get('CV_MAX_FILE_SIZE_BYTES', '5242880');
        if ($decoded !== false && strlen($decoded) > $maxBytes) {
            $errors[] = ['field' => 'content_base64', 'reason' => 'file_too_large'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $previous = $this->documents->findByNodeId($nodeId);

        $storageKey = Uuid::v4() . '.bin';
        $this->writeFile($storageKey, (string) $decoded);

        $documentId = $this->documents->upsert(
            Uuid::v4(),
            $nodeId,
            $title,
            $storageKey,
            $contentType,
            number_format((float) $priceAmount, 2, '.', ''),
            $priceCurrency,
        );

        // The uploaded content just changed (or this is the first upload): any
        // grant against the previous content no longer applies to this one.
        $this->grants->revokeAllForDocument($documentId);

        if ($previous !== null && $previous['storage_key'] !== $storageKey) {
            @unlink(rtrim($this->storageDirectory, '/') . '/' . $previous['storage_key']);
        }

        return $this->documents->findById($documentId);
    }

    private function writeFile(string $storageKey, string $contents): void
    {
        if (!is_dir($this->storageDirectory) && !mkdir($this->storageDirectory, 0770, true) && !is_dir($this->storageDirectory)) {
            throw new RuntimeException("Unable to create CV storage directory: {$this->storageDirectory}");
        }

        $path = rtrim($this->storageDirectory, '/') . '/' . $storageKey;
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write CV file: {$path}");
        }
    }
}
