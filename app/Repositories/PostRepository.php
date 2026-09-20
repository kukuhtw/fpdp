<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PostRepository
{
    private const SELECT = '
        SELECT posts.*, profiles.handle, profiles.display_name, profiles.avatar_url,
               nodes.id AS node_id, nodes.domain AS node_domain
        FROM posts
        INNER JOIN profiles ON profiles.id = posts.profile_id
        INNER JOIN users ON users.id = posts.user_id
        INNER JOIN nodes ON nodes.id = users.node_id
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $userId, int $profileId, array $fields): array
    {
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO posts (public_id, user_id, profile_id, title, content, post_type, visibility, published_at)
                 VALUES (:public_id, :user_id, :profile_id, :title, :content, :post_type, :visibility, :published_at)',
            );
            $statement->execute([
                'public_id' => $publicId,
                'user_id' => $userId,
                'profile_id' => $profileId,
                'title' => $fields['title'],
                'content' => $fields['content'],
                'post_type' => $fields['post_type'],
                'visibility' => $fields['visibility'],
                'published_at' => $fields['published_at'],
            ]);
            $this->replaceMedia((int) $this->connection->lastInsertId(), $fields['media']);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->findByPublicId($publicId, true);
    }

    public function findByPublicId(string $publicId, bool $includeUnpublished = false): ?array
    {
        $visibility = $includeUnpublished
            ? ''
            : " AND posts.visibility IN ('PUBLIC', 'UNLISTED') AND posts.published_at IS NOT NULL";
        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE posts.public_id = :public_id AND posts.deleted_at IS NULL' . $visibility,
        );
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $row['media'] = $this->mediaForPostIds([(int) $row['id']])[(int) $row['id']] ?? [];
        return $row;
    }

    public function listPublic(int $limit, ?int $beforeId = null, ?string $authorHandle = null): array
    {
        $where = ["posts.visibility = 'PUBLIC'", 'posts.published_at IS NOT NULL', 'posts.deleted_at IS NULL'];
        $parameters = [];
        if ($beforeId !== null) {
            $where[] = 'posts.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }
        if ($authorHandle !== null && $authorHandle !== '') {
            $where[] = 'profiles.handle = :author_handle';
            $parameters['author_handle'] = $authorHandle;
        }

        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY posts.id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        $rows = $statement->fetchAll();
        $media = $this->mediaForPostIds(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        foreach ($rows as &$row) {
            $row['media'] = $media[(int) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }
/**
     * @return array<int, array<string, mixed>>
     */
    public function listByUserId(int $userId, int $limit, ?int $beforeId = null): array
    {
        $where = ['posts.user_id = :user_id', 'posts.deleted_at IS NULL'];
        $parameters = ['user_id' => $userId];
        if ($beforeId !== null) {
            $where[] = 'posts.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY posts.id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        $rows = $statement->fetchAll();
        $media = $this->mediaForPostIds(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        foreach ($rows as &$row) {
            $row['media'] = $media[(int) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array{total: int, published: int, draft: int}
     */

    /**
     * @return array{total: int, published: int, draft: int}
     */
    public function countByProfileId(int $profileId): array
    {
        $statement = $this->connection->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN visibility IN ('PUBLIC', 'UNLISTED') AND published_at IS NOT NULL THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN published_at IS NULL THEN 1 ELSE 0 END) AS draft
             FROM posts WHERE profile_id = :profile_id AND deleted_at IS NULL",
        );
        $statement->execute(['profile_id' => $profileId]);
        $row = $statement->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'published' => (int) ($row['published'] ?? 0),
            'draft' => (int) ($row['draft'] ?? 0),
        ];
    }

    public function update(string $publicId, array $fields): array
    {
        $assignments = [];
        $parameters = ['public_id' => $publicId];
        foreach (['title', 'content', 'post_type', 'visibility', 'published_at'] as $column) {
            if (array_key_exists($column, $fields)) {
                $assignments[] = $column . ' = :' . $column;
                $parameters[$column] = $fields[$column];
            }
        }
        $this->connection->beginTransaction();
        try {
            if ($assignments !== []) {
                $statement = $this->connection->prepare(
                    'UPDATE posts SET ' . implode(', ', $assignments) . ' WHERE public_id = :public_id AND deleted_at IS NULL',
                );
                $statement->execute($parameters);
            }
            if (array_key_exists('media', $fields)) {
                $post = $this->findByPublicId($publicId, true);
                $this->replaceMedia((int) $post['id'], $fields['media']);
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->findByPublicId($publicId, true);
    }

    public function softDelete(string $publicId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE posts SET deleted_at = CURRENT_TIMESTAMP WHERE public_id = :public_id AND deleted_at IS NULL',
        );
        $statement->execute(['public_id' => $publicId]);
    }

    private function replaceMedia(int $postId, array $media): void
    {
        $delete = $this->connection->prepare('DELETE FROM post_media WHERE post_id = :post_id');
        $delete->execute(['post_id' => $postId]);

        $insert = $this->connection->prepare(
            'INSERT INTO post_media (post_id, media_type, url, alt_text, sort_order)
             VALUES (:post_id, :media_type, :url, :alt_text, :sort_order)',
        );
        foreach ($media as $sortOrder => $item) {
            $insert->execute([
                'post_id' => $postId,
                'media_type' => $item['type'],
                'url' => $item['url'],
                'alt_text' => $item['alt_text'] ?? null,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    private function mediaForPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $statement = $this->connection->prepare(
            "SELECT post_id, media_type, url, alt_text, sort_order FROM post_media
             WHERE post_id IN ({$placeholders}) ORDER BY post_id, sort_order, id",
        );
        $statement->execute($postIds);
        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $grouped[(int) $row['post_id']][] = $row;
        }

        return $grouped;
    }
}
