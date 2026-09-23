<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\WallCommentRepository;
use App\Services\Security\AuditService;

final class WallCommentService
{
    private const MAX_CONTENT_LENGTH = 1000;

    public function __construct(
        private readonly WallCommentRepository $comments,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $visitor
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $nodeId, array $visitor, array $input): array
    {
        $content = $this->validateContent($input, 'content');

        return $this->comments->create(Uuid::v4(), $nodeId, (int) $visitor['id'], $content);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{items: array<int, array<string, mixed>>, next_cursor: ?string, has_more: bool}
     */
    public function listPublic(int $nodeId, array $query): array
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

        $rows = $this->comments->listPublic($nodeId, (int) $limit, $beforeId);
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

    /**
     * @param array<string, mixed> $context Owner auth context (must contain 'node').
     */
    public function delete(array $context, string $publicId): void
    {
        $comment = $this->owned($context, $publicId);
        $this->comments->softDelete((int) $comment['id']);

        $this->audit?->record($context, 'comment.deleted', 'wall_comment', $publicId, [
            'visitor_id' => $comment['visitor_id'],
        ]);
    }

    /**
     * @param array<string, mixed> $context Owner auth context (must contain 'node').
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function reply(array $context, string $publicId, array $input): array
    {
        $comment = $this->owned($context, $publicId);
        $reply = $this->validateContent($input, 'reply');

        $this->comments->setReply((int) $comment['id'], $reply);

        $this->audit?->record($context, 'comment.replied', 'wall_comment', $publicId);

        return $this->comments->findByPublicId((int) $context['node']['id'], $publicId);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function owned(array $context, string $publicId): array
    {
        $comment = $this->comments->findByPublicId((int) $context['node']['id'], $publicId);
        if ($comment === null) {
            throw new NotFoundException('Comment not found.');
        }

        return $comment;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function validateContent(array $input, string $field): string
    {
        foreach (array_diff(array_keys($input), [$field]) as $unknown) {
            throw new ValidationException([['field' => $unknown, 'reason' => 'unknown_field']]);
        }

        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw new ValidationException([['field' => $field, 'reason' => 'required']]);
        }
        if (mb_strlen($value) > self::MAX_CONTENT_LENGTH) {
            throw new ValidationException([['field' => $field, 'reason' => 'invalid_length']]);
        }

        return $value;
    }
}
