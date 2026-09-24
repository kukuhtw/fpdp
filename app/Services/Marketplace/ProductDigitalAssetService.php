<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Config;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\ProductDigitalAssetRepository;
use App\Repositories\ProductRepository;
use RuntimeException;

/**
 * Owner-facing upload and metadata for a digital product's gated files
 * (PDF and/or source-code archive), stored outside the public webroot.
 * Download gating (proof of purchase) lives in MarketplaceService::
 * verifyDigitalPurchase(), already used by the legacy digital_asset_url
 * flow — this service only stores/reads bytes, mirroring how
 * CvDocumentService/CvAccessService split "store" from "gate+serve".
 */
final class ProductDigitalAssetService
{
    private const ALLOWED_KINDS = ['PDF', 'SOURCE_CODE'];

    /** @var array<string, array<string, string>> kind => allowed mime => extension */
    private const ALLOWED_TYPES = [
        'PDF' => ['application/pdf' => 'pdf'],
        'SOURCE_CODE' => ['application/zip' => 'zip', 'application/x-zip-compressed' => 'zip'],
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductDigitalAssetRepository $assets,
        private readonly string $storageDirectory,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function upload(int $nodeId, string $productPublicId, array $input): array
    {
        $product = $this->products->findByPublicId($productPublicId);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }
        if ((int) $product['node_id'] !== $nodeId) {
            throw new ForbiddenException('You do not own this product.');
        }
        if ((string) $product['product_type'] !== 'DIGITAL') {
            throw new ValidationException([['field' => 'product_type', 'reason' => 'not_a_digital_product']]);
        }

        $kind = strtoupper((string) ($input['kind'] ?? ''));
        if (!in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new ValidationException([['field' => 'kind', 'reason' => 'invalid_value']]);
        }

        $contentBase64 = (string) ($input['content_base64'] ?? '');
        $decoded = $contentBase64 === '' ? false : base64_decode($contentBase64, true);
        if ($decoded === false || $decoded === '') {
            throw new ValidationException([['field' => 'content_base64', 'reason' => 'invalid_encoding']]);
        }

        $maxBytes = (int) Config::get('PRODUCT_ASSET_MAX_FILE_SIZE_BYTES', '20971520');
        if (strlen($decoded) > $maxBytes) {
            throw new ValidationException([['field' => 'content_base64', 'reason' => 'file_too_large']]);
        }

        $detectedType = self::detectMimeType($decoded);
        $extension = $detectedType !== null ? (self::ALLOWED_TYPES[$kind][$detectedType] ?? null) : null;
        if ($extension === null) {
            throw new ValidationException([['field' => 'content_base64', 'reason' => 'unsupported_file_type']]);
        }

        $originalFilename = isset($input['filename']) ? mb_substr((string) $input['filename'], 0, 255) : null;

        $previous = $this->assets->findByProductAndKind((int) $product['id'], $kind);
        $storageKey = Uuid::v4() . '.' . $extension;
        $this->writeFile($storageKey, $decoded);

        $assetId = $this->assets->upsert((int) $product['id'], $kind, $storageKey, $originalFilename, $detectedType, strlen($decoded));

        if ($previous !== null && $previous['storage_key'] !== $storageKey) {
            @unlink(rtrim($this->storageDirectory, '/') . '/' . $previous['storage_key']);
        }

        return $this->assets->listByProduct((int) $product['id'])[0] ?? ['id' => $assetId];
    }

    /**
     * @return array{content: string, content_type: string, filename: string}
     */
    public function readForDownload(int $productId, string $kind): array
    {
        $asset = $this->assets->findByProductAndKind($productId, strtoupper($kind));
        if ($asset === null) {
            throw new NotFoundException('This product has no ' . strtolower($kind) . ' file.');
        }

        $path = rtrim($this->storageDirectory, '/') . '/' . $asset['storage_key'];
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Unable to read stored product asset: {$path}");
        }

        $extension = pathinfo((string) $asset['storage_key'], PATHINFO_EXTENSION);

        return [
            'content' => $content,
            'content_type' => (string) $asset['content_type'],
            'filename' => ($asset['original_filename'] ?? $asset['storage_key']) . ($asset['original_filename'] ? '' : ''),
        ] + ['filename' => (string) ($asset['original_filename'] ?: ('download.' . $extension))];
    }

    /**
     * Metadata only (kind, filename, size) — no file content. Used by both
     * the owner's product edit view and the buyer's post-purchase download list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForProduct(int $productId): array
    {
        return array_map(
            static fn (array $row): array => [
                'kind' => $row['kind'],
                'original_filename' => $row['original_filename'],
                'content_type' => $row['content_type'],
                'size_bytes' => (int) $row['size_bytes'],
            ],
            $this->assets->listByProduct($productId),
        );
    }

    private static function detectMimeType(string $bytes): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('The fileinfo extension is required to validate uploaded files.');
        }
        $type = finfo_buffer($finfo, $bytes);

        return $type === false ? null : $type;
    }

    private function writeFile(string $storageKey, string $contents): void
    {
        if (!is_dir($this->storageDirectory) && !mkdir($this->storageDirectory, 0770, true) && !is_dir($this->storageDirectory)) {
            throw new RuntimeException("Unable to create product asset storage directory: {$this->storageDirectory}");
        }

        $path = rtrim($this->storageDirectory, '/') . '/' . $storageKey;
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write product asset file: {$path}");
        }
    }
}
