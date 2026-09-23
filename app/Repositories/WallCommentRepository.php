<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class WallCommentRepository
{
    private const SELECT = '
        SELECT wall_comments.*,
               visitor_accounts.display_name AS visitor_display_name,
               visitor_accounts.avatar_url AS visitor_avatar_url
        FROM wall_comments
        INNER JOIN visitor_accounts ON visitor_accounts.id = wall_comments.visitor_id
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $nodeId, int $visitorId, string $content): array
    {
        $statement = $this->connection->prepare(
            'INSERT INTO wall_comments (public_id, node_id, visitor_id, content)
             VALUES (:public_id, :node_id, :visitor_id, :content)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'node_id' => $nodeId,
            'visitor_id' => $visitorId,
            'content' => $content,
        ]);

        return $this->findByPublicId($nodeId, $publicId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublic(int $nodeId, int $limit, ?int $beforeId = null): array
    {
        $where = ['wall_comments.node_id = :node_id', 'wall_comments.deleted_at IS NULL'];
        $parameters = ['node_id' => $nodeId];
        if ($beforeId !== null) {
            $where[] = 'wall_comments.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY wall_comments.id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function findByPublicId(int $nodeId, string $publicId, bool $includeDeleted = true): ?array
    {
        $deletedClause = $includeDeleted ? '' : ' AND wall_comments.deleted_at IS NULL';
        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE wall_comments.node_id = :node_id AND wall_comments.public_id = :public_id' . $deletedClause,
        );
        $statement->execute(['node_id' => $nodeId, 'public_id' => $publicId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function softDelete(int $id): void
    {
        $statement = $this->connection->prepare(
            'UPDATE wall_comments SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id AND deleted_at IS NULL',
        );
        $statement->execute(['id' => $id]);
    }

    public function setReply(int $id, string $reply): void
    {
        $statement = $this->connection->prepare(
            'UPDATE wall_comments SET admin_reply = :reply, admin_reply_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $statement->execute(['id' => $id, 'reply' => $reply]);
    }
}
