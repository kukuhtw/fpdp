<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ExternalPostRepository
{
    private const SELECT = '
        SELECT ep.*, efs.source_type AS feed_source_type, efs.provider AS feed_provider,
               efs.source_url AS feed_url
        FROM external_posts ep
        INNER JOIN external_feed_sources efs ON efs.id = ep.external_account_id
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        int $userId,
        string $provider,
        string $externalPostId,
        ?int $feedSourceId,
        string $postType,
        ?string $canonicalUrl,
        ?string $title,
        ?string $content,
        ?array $media,
        ?string $authorName,
        ?string $publishedAt,
        ?array $rawPayload = null,
    ): int {
        $driver = $this->connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $insertSyntax = $driver === 'sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

        $statement = $this->connection->prepare(
            "{$insertSyntax} external_posts
             (user_id, provider, external_post_id, external_account_id, post_type,
              canonical_url, title, content, media_json, author_name, published_at, raw_payload)
             VALUES
             (:user_id, :provider, :external_post_id, :external_account_id, :post_type,
              :canonical_url, :title, :content, :media_json, :author_name, :published_at, :raw_payload)",
        );
        $statement->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'external_post_id' => $externalPostId,
            'external_account_id' => $feedSourceId,
            'post_type' => $postType,
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'content' => $content,
            'media_json' => $media !== null ? json_encode($media) : null,
            'author_name' => $authorName,
            'published_at' => $publishedAt,
            'raw_payload' => $rawPayload !== null ? json_encode($rawPayload) : null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function exists(string $provider, string $externalPostId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM external_posts WHERE provider = :provider AND external_post_id = :external_post_id',
        );
        $statement->execute(['provider' => $provider, 'external_post_id' => $externalPostId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublic(int $limit, ?int $beforeId = null): array
    {
        $where = ["ep.status = 'ACTIVE'"];
        $parameters = [];

        if ($beforeId !== null) {
            $where[] = 'ep.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ep.id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublicByUserId(int $userId, int $limit, ?int $beforeId = null): array
    {
        $where = ["ep.status = 'ACTIVE'", 'ep.user_id = :user_id'];
        $parameters = ['user_id' => $userId];

        if ($beforeId !== null) {
            $where[] = 'ep.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ep.id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function getTotalCount(): int
    {
        $statement = $this->connection->query("SELECT COUNT(*) FROM external_posts WHERE status = 'ACTIVE'");

        return (int) $statement->fetchColumn();
    }

    public function countByUserId(int $userId): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM external_posts WHERE user_id = :user_id AND status = 'ACTIVE'",
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }
}