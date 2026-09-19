<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\PostRepository;
use DateTimeImmutable;

final class PostService
{
    private const FIELDS = ['title', 'content', 'post_type', 'visibility', 'published_at'];
    private const TYPES = ['NOTE', 'ARTICLE', 'MEDIA'];
    private const VISIBILITIES = ['PUBLIC', 'UNLISTED', 'PRIVATE'];

    public function __construct(private readonly PostRepository $posts)
    {
    }

    public function create(array $context, array $input): array
    {
        $fields = $this->validate($input, false);

        return $this->posts->create(
            Uuid::v4(),
            (int) $context['user']['id'],
            (int) $context['profile']['id'],
            $fields,
        );
    }

    public function get(string $publicId): array
    {
        $post = $this->posts->findByPublicId($publicId);
        if ($post === null) {
            throw new NotFoundException('Post not found.');
        }

        return $post;
    }

    public function list(array $query): array
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

        $rows = $this->posts->listPublic((int) $limit, $beforeId, isset($query['author_handle']) ? (string) $query['author_handle'] : null);
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
        $this->owned($context, $publicId);
        $this->posts->softDelete($publicId);
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

        if (array_key_exists('title', $input) && $input['title'] !== null && mb_strlen((string) $input['title']) > 255) {
            $errors[] = ['field' => 'title', 'reason' => 'invalid_length'];
        }
        if (array_key_exists('content', $input) && (trim((string) $input['content']) === '' || mb_strlen((string) $input['content']) > 100000)) {
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
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $fields = $input;
        if (!$partial) {
            $fields['title'] = $input['title'] ?? null;
            $fields['published_at'] = $input['published_at'] ?? null;
        }
        if (array_key_exists('published_at', $fields) && $fields['published_at'] !== null) {
            $fields['published_at'] = (new DateTimeImmutable((string) $fields['published_at']))->format('Y-m-d H:i:s');
        }

        return $fields;
    }
}
