<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Core\Database;
use App\Repositories\ExternalPostRepository;
use App\Repositories\PostRepository;
use App\Services\Security\AuditService;
use DateTimeImmutable;

final class PostService
{
    private const FIELDS = ['title', 'content', 'post_type', 'visibility', 'published_at', 'media'];
    private const TYPES = ['NOTE', 'ARTICLE', 'MEDIA'];
    private const VISIBILITIES = ['PUBLIC', 'UNLISTED', 'PRIVATE'];
    private const ALLOWED_HTML_TAGS = '<b><i><u><strong><em><a><ul><ol><li><br><p><h1><h2><h3><h4><pre><blockquote><figure><figcaption><img><div><span><sub><sup><code><hr><table><thead><tbody><tr><th><td><caption><iframe>';

    public function __construct(
        private readonly PostRepository $posts,
        private readonly ?ExternalPostRepository $external = null,
        private readonly ?AuditService $audit = null,
    ) {
    }

    private function getExternal(): ExternalPostRepository
    {
        if ($this->external === null) {
            $this->external = new ExternalPostRepository(Database::connection());
        }
        return $this->external;
    }

    public function create(array $context, array $input): array
    {
        $fields = $this->validate($input, false);

        $post = $this->posts->create(
            Uuid::v4(),
            (int) $context['user']['id'],
            (int) $context['profile']['id'],
            $fields,
        );

        $this->audit?->record($context, 'post.created', 'post', $post['public_id'], [
            'post_type' => $post['post_type'],
            'visibility' => $post['visibility'],
        ]);

        return $post;
    }

    public function get(string $publicId): array
    {
        // Support both UUID and {id}-{slug} format
        if (str_contains($publicId, '-') && !str_contains($publicId, '-') === false) {
            $parts = explode('-', $publicId, 2);
            if (ctype_digit($parts[0]) && isset($parts[1]) && $parts[1] !== '') {
                $numericId = (int) $parts[0];
                $slug = $parts[1];
                $post = $this->posts->findBySlug($numericId, $slug);
                if ($post !== null) {
                    return $post;
                }
            }
        }

        $post = $this->posts->findByPublicId($publicId);
        if ($post === null) {
            throw new NotFoundException('Post not found.');
        }

        return $post;
    }

    public function getOwn(string $publicId, array $context): array
    {
        // Support both UUID and {id}-{slug} format
        if (str_contains($publicId, '-')) {
            $parts = explode('-', $publicId, 2);
            if (ctype_digit($parts[0]) && isset($parts[1]) && $parts[1] !== '') {
                $numericId = (int) $parts[0];
                $slug = $parts[1];
                $post = $this->posts->findBySlug($numericId, $slug);
                if ($post !== null) {
                    if ((int) $post['user_id'] !== (int) $context['user']['id']) {
                        throw new NotFoundException('Post not found.');
                    }
                    return $post;
                }
            }
        }

        $post = $this->posts->findByPublicId($publicId, true);
        if ($post === null) {
            throw new NotFoundException('Post not found.');
        }
        if ((int) $post['user_id'] !== (int) $context['user']['id']) {
            throw new NotFoundException('Post not found.');
        }

        return $post;
    }

    public function list(array $query): array
    {
        $sourceType = (string) ($query['source_type'] ?? 'LOCAL');
        if (!in_array($sourceType, ['LOCAL', 'EXTERNAL', 'FEDERATED'], true)) {
            throw new ValidationException([['field' => 'source_type', 'reason' => 'invalid_value']]);
        }

        $limit = filter_var($query['limit'] ?? 20, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        if ($limit === false) {
            throw new ValidationException([['field' => 'limit', 'reason' => 'invalid_value']]);
        }

        $beforeId = null;
        if (isset($query['cursor']) && $query['cursor'] !== '') {
            $decoded = base64_decode(strtr((string) $query['cursor'], '-_', '+/'), true);
            if ($decoded === false || !ctype_digit($decoded)) {
                throw new ValidationException([['field' => 'cursor', 'reason' => 'invalid_value']]);
            }
            $beforeId = (int) $decoded;
        }

        $rows = match ($sourceType) {
            'LOCAL' => $this->posts->listPublic(
                (int) $limit,
                $beforeId,
                isset($query['author_handle']) ? (string) $query['author_handle'] : null,
            ),
            'EXTERNAL' => $this->getExternal()->listPublic((int) $limit, $beforeId),
            default => [],
        };
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'items' => $rows,
            'next_cursor' => $hasMore && $last !== null ? rtrim(strtr(base64_encode((string) $last['id']), '+/', '-_'), '=') : null,
            'has_more' => $hasMore,
        ];
    }

    public function update(array $context, string $publicId, array $input): array
    {
        $post = $this->owned($context, $publicId);
        $fields = $this->validate($input, true);

        return $this->posts->update((string) $post['public_id'], $fields);
    }

    public function delete(array $context, string $publicId): void
    {
        $post = $this->owned($context, $publicId);
        $this->posts->softDelete($publicId);

        $this->audit?->record($context, 'post.deleted', 'post', $publicId, [
            'previous_visibility' => $post['visibility'],
        ]);
    }

    private function owned(array $context, string $publicId): array
    {
        $post = $this->posts->findByPublicId($publicId, true);
        if ($post === null) {
            throw new NotFoundException('Post not found.');
        }
        if ((int) $post['user_id'] !== (int) $context['user']['id']) {
            throw new ForbiddenException();
        }

        return $post;
    }

    public function listOwn(array $context, array $query): array
    {
        $limit = filter_var($query['limit'] ?? 20, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        if ($limit === false) {
            throw new ValidationException([['field' => 'limit', 'reason' => 'invalid_value']]);
        }

        $beforeId = null;
        if (isset($query['cursor']) && $query['cursor'] !== '') {
            $decoded = base64_decode(strtr((string) $query['cursor'], '-_', '+/'), true);
            if ($decoded === false || !ctype_digit($decoded)) {
                throw new ValidationException([['field' => 'cursor', 'reason' => 'invalid_value']]);
            }
            $beforeId = (int) $decoded;
        }

        $rows = $this->posts->listByUserId((int) $context['user']['id'], $limit, $beforeId);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $last = $rows !== [] ? $rows[count($rows) - 1] : null;

        return [
            'items' => $rows,
            'next_cursor' => $last !== null ? rtrim(strtr(base64_encode((string) $last['id']), '+/', '-_'), '=') : null,
            'has_more' => $hasMore,
        ];
    }

    private function validate(array $input, bool $partial): array
    {
        $errors = [];
        foreach (array_diff(array_keys($input), self::FIELDS) as $field) {
            $errors[] = ['field' => $field, 'reason' => 'unknown_field'];
        }
        if ($partial && $input === []) {
            $errors[] = ['field' => '_', 'reason' => 'empty_update'];
        }
        if (!$partial) {
            foreach (['content', 'post_type', 'visibility'] as $field) {
                if (!array_key_exists($field, $input)) {
                    $errors[] = ['field' => $field, 'reason' => 'required'];
                }
            }
        }

        // Sanitize HTML content — strip dangerous tags/attributes
        if (array_key_exists('content', $input) && is_string($input['content'])) {
            $input['content'] = self::sanitizeHtml($input['content']);
        }

        if (array_key_exists('title', $input) && $input['title'] !== null && mb_strlen((string) $input['title']) > 255) {
            $errors[] = ['field' => 'title', 'reason' => 'invalid_length'];
        }
        if (array_key_exists('content', $input) && (trim(strip_tags((string) $input['content'])) === '' || mb_strlen((string) $input['content']) > 500000)) {
            $errors[] = ['field' => 'content', 'reason' => 'invalid_length'];
        }
        if (array_key_exists('post_type', $input) && !in_array($input['post_type'], self::TYPES, true)) {
            $errors[] = ['field' => 'post_type', 'reason' => 'invalid_value'];
        }
        if (array_key_exists('visibility', $input) && !in_array($input['visibility'], self::VISIBILITIES, true)) {
            $errors[] = ['field' => 'visibility', 'reason' => 'invalid_value'];
        }
        if (array_key_exists('published_at', $input) && $input['published_at'] !== null) {
            try {
                new DateTimeImmutable((string) $input['published_at']);
            } catch (\Exception) {
                $errors[] = ['field' => 'published_at', 'reason' => 'invalid_format'];
            }
        }
        if (array_key_exists('media', $input)) {
            if (!is_array($input['media']) || !array_is_list($input['media']) || count($input['media']) > 10) {
                $errors[] = ['field' => 'media', 'reason' => 'invalid_value'];
            } else {
                foreach ($input['media'] as $index => $media) {
                    $path = 'media.' . $index;
                    if (!is_array($media)) {
                        $errors[] = ['field' => $path, 'reason' => 'invalid_value'];
                        continue;
                    }
                    foreach (array_diff(array_keys($media), ['type', 'url', 'alt_text']) as $field) {
                        $errors[] = ['field' => $path . '.' . $field, 'reason' => 'unknown_field'];
                    }
                    if (!in_array($media['type'] ?? null, ['IMAGE', 'VIDEO', 'AUDIO', 'FILE'], true)) {
                        $errors[] = ['field' => $path . '.type', 'reason' => 'invalid_value'];
                    }
                    $url = (string) ($media['url'] ?? '');
                    $parts = parse_url($url);
                    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
                        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                        || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
                        $errors[] = ['field' => $path . '.url', 'reason' => 'invalid_format'];
                    }
                    if (array_key_exists('alt_text', $media) && $media['alt_text'] !== null
                        && mb_strlen((string) $media['alt_text']) > 500) {
                        $errors[] = ['field' => $path . '.alt_text', 'reason' => 'invalid_length'];
                    }
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $fields = $input;
        if (!$partial) {
            $fields['title'] = $input['title'] ?? null;
            $fields['published_at'] = $input['published_at'] ?? null;
            $fields['media'] = $input['media'] ?? [];
        }
        if (array_key_exists('published_at', $fields) && $fields['published_at'] !== null) {
            $fields['published_at'] = (new DateTimeImmutable((string) $fields['published_at']))->format('Y-m-d H:i:s');
        }

        return $fields;
    }

    /** Strip dangerous HTML tags/attributes; allow only Trix-safe content. */
    private static function sanitizeHtml(string $html): string
    {
        // Strip tags not in the allowlist
        $cleaned = strip_tags($html, self::ALLOWED_HTML_TAGS);

        // Remove event handlers (onclick, onerror, onload, etc.)
        $cleaned = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $cleaned);

        // Remove javascript: and data: URIs (except data:image)
        $cleaned = preg_replace('/\s+(?:href|src|action)\s*=\s*(?:"javascript:[^"]*"|\'javascript:[^\']*\')/i', '', $cleaned);
        $cleaned = preg_replace('/\s+srcset\s*=\s*"[^"]*"/i', '', $cleaned);

        return $cleaned;
    }
}
