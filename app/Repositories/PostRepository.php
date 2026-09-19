<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PostRepository
{
    private const SELECT = '
        SELECT posts.*, profiles.handle, profiles.display_name, profiles.avatar_url,
               nodes.domain AS node_domain
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

        return $row === false ? null : $row;
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

        return $statement->fetchAll();
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
        $statement = $this->connection->prepare(
            'UPDATE posts SET ' . implode(', ', $assignments) . ' WHERE public_id = :public_id AND deleted_at IS NULL',
        );
        $statement->execute($parameters);

        return $this->findByPublicId($publicId, true);
    }

    public function softDelete(string $publicId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE posts SET deleted_at = CURRENT_TIMESTAMP WHERE public_id = :public_id AND deleted_at IS NULL',
        );
        $statement->execute(['public_id' => $publicId]);
    }
}
