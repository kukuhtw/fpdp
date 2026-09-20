<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Core\Config;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use RuntimeException;

/**
 * Owner uploads for post media (`app/Views/post-editor.php`'s media
 * attachment field): validates and stores the file outside the public
 * webroot, and reads it back for the public serving route. There is no
 * database table for this — the returned URL is just another HTTPS URL as
 * far as PostService/`post_media` are concerned, the same as a pasted CDN
 * link, so a post's media list needs no schema change to support uploads.
 *
 * Storage keys are unguessable UUIDs; that IS the access control (the same
 * model as any "unlisted" CDN link, and the same threat model a pasted
 * external URL already carries) — orphaned files from an upload that was
 * never attached to a saved post are not garbage-collected.
 */
final class MediaUploadService
{
    /**
     * Server-verified-MIME allowlist per requested media type. SVG is
     * deliberately excluded from IMAGE — it can carry an embedded
     * <script>, so accepting it would let a signed-in owner store XSS that
     * fires for every visitor who views the post. "FILE" is deliberately
     * narrow (PDF only) rather than an arbitrary blob, for the same
     * reason: this is served back with its real content type, so anything
     * HTML/script-capable here is a stored-XSS vector.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED_TYPES = [
        'IMAGE' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'],
        'VIDEO' => ['video/mp4' => 'mp4', 'video/webm' => 'webm'],
        'AUDIO' => ['audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav'],
        'FILE' => ['application/pdf' => 'pdf'],
    ];

    private const STORAGE_KEY_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.([a-z0-9]{2,4})$/';

    public function __construct(private readonly string $storageDirectory)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{storage_key: string, content_type: string, media_type: string}
     */
    public function upload(array $input): array
    {
        $mediaType = strtoupper((string) ($input['media_type'] ?? ''));
        $contentBase64 = (string) ($input['content_base64'] ?? '');

        if (!isset(self::ALLOWED_TYPES[$mediaType])) {
            throw new ValidationException([['field' => 'media_type', 'reason' => 'invalid_value']]);
        }

        $decoded = $contentBase64 === '' ? false : base64_decode($contentBase64, true);
        if ($decoded === false || $decoded === '') {
            throw new ValidationException([['field' => 'content_base64', 'reason' => 'invalid_encoding']]);
        }

        $maxBytes = (int) Config::get('MEDIA_MAX_FILE_SIZE_BYTES', '10485760');
        if (strlen($decoded) > $maxBytes) {
            throw new ValidationException([['field' => 'content_base64', 'reason' => 'file_too_large']]);
        }

        // The client-declared content type is never trusted: the actual
        // bytes are sniffed server-side (finfo) and checked against the
        // allowlist for the requested media_type, so a mislabeled or
        // disguised upload (e.g. HTML claiming to be image/png) is
        // rejected instead of stored and served back with the wrong type.
        $detectedType = self::detectMimeType($decoded);
        $extension = $detectedType !== null ? (self::ALLOWED_TYPES[$mediaType][$detectedType] ?? null) : null;
        if ($extension === null) {
            throw new ValidationException([['field' => 'content_base64', 'reason' => 'unsupported_file_type']]);
        }

        $storageKey = Uuid::v4() . '.' . $extension;
        $this->writeFile($storageKey, $decoded);

        return ['storage_key' => $storageKey, 'content_type' => $detectedType, 'media_type' => $mediaType];
    }

    /**
     * @return array{content: string, content_type: string}|null
     */
    public function read(string $storageKey): ?array
    {
        if (!preg_match(self::STORAGE_KEY_PATTERN, $storageKey, $matches)) {
            return null;
        }

        $contentType = self::extensionToContentType($matches[1]);
        if ($contentType === null) {
            return null;
        }

        $path = rtrim($this->storageDirectory, '/') . '/' . $storageKey;
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : ['content' => $content, 'content_type' => $contentType];
    }

    private static function detectMimeType(string $bytes): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('The fileinfo extension is required to validate uploaded media.');
        }
        $type = finfo_buffer($finfo, $bytes);
        finfo_close($finfo);

        return $type === false ? null : $type;
    }

    private static function extensionToContentType(string $extension): ?string
    {
        foreach (self::ALLOWED_TYPES as $mimeToExtension) {
            $mimeType = array_search($extension, $mimeToExtension, true);
            if ($mimeType !== false) {
                return $mimeType;
            }
        }

        return null;
    }

    private function writeFile(string $storageKey, string $contents): void
    {
        if (!is_dir($this->storageDirectory) && !mkdir($this->storageDirectory, 0770, true) && !is_dir($this->storageDirectory)) {
            throw new RuntimeException("Unable to create media storage directory: {$this->storageDirectory}");
        }

        $path = rtrim($this->storageDirectory, '/') . '/' . $storageKey;
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write media file: {$path}");
        }
    }
}
