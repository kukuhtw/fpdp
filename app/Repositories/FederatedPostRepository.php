<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FederatedPostRepository
{
    private const SELECT = '
        SELECT fp.id, fp.public_id, fp.remote_actor_id, fp.object_uri,
               fp.canonical_url, fp.title, fp.content, fp.visibility,
               fp.published_at, fp.fetched_at, fp.created_at, fp.deleted_at
        FROM federated_posts fp
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        string $publicId,
        int $remoteActorId,
        string $objectUri,
        ?string $canonicalUrl = null,
        ?string $title = null,
        ?string $content = null,
        ?string $publishedAt = null,
        string $visibility = 'PUBLIC',
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO federated_posts (public_id, remote_actor_id, object_uri, canonical_url,
                                          title, content, visibility, published_at)
             VALUES (:public_id, :remote_actor_id, :object_uri, :canonical_url,
                     :title, :content, :visibility, :published_at)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'remote_actor_id' => $remoteActorId,
            'object_uri' => $objectUri,
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'content' => $content,
            'visibility' => $visibility,
            'published_at' => $publishedAt,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Returns the latest public, non-deleted post for a given actor.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestByActorId(int $remoteActorId): ?array
    {
        $statement = $this->connection->prepare(
            self::SELECT
            . " WHERE fp.remote_actor_id = :actor_id AND fp.visibility IN ('PUBLIC', 'UNLISTED')"
            . ' AND fp.deleted_at IS NULL AND fp.published_at IS NOT NULL'
            . ' ORDER BY fp.published_at DESC, fp.id DESC LIMIT 1',
        );
        $statement->execute(['actor_id' => $remoteActorId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Returns latest public post for each actor.
     *
     * @param array<int, int> $actorIds
     * @return array<int, array<string, mixed>> Keyed by remote_actor_id
     */
    public function findLatestByActorIds(array $actorIds): array
    {
        if ($actorIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($actorIds), '?'));
        $statement = $this->connection->prepare(
            "SELECT fp.*
             FROM federated_posts fp
             INNER JOIN (
                 SELECT remote_actor_id, MAX(published_at) AS max_published_at
                 FROM federated_posts
                 WHERE remote_actor_id IN ({$placeholders})
                   AND visibility IN ('PUBLIC', 'UNLISTED')
                   AND deleted_at IS NULL
                   AND published_at IS NOT NULL
                 GROUP BY remote_actor_id
             ) latest ON latest.remote_actor_id = fp.remote_actor_id
                 AND latest.max_published_at = fp.published_at"
        );
        $statement->execute($actorIds);

        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $grouped[(int) $row['remote_actor_id']] = $row;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByObjectUri(string $objectUri): ?array
    {
        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE fp.object_uri = :object_uri',
        );
        $statement->execute(['object_uri' => $objectUri]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Counts visible federated posts from actors this profile is connected
     * to (any relationship_status) — used for the Overview's timeline mix.
     */
    public function countForProfile(int $profileId): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM federated_posts fp
             INNER JOIN federated_connections fc ON fc.remote_actor_id = fp.remote_actor_id
             WHERE fc.profile_id = :profile_id
               AND fp.visibility IN ('PUBLIC', 'UNLISTED')
               AND fp.deleted_at IS NULL",
        );
        $statement->execute(['profile_id' => $profileId]);

        return (int) $statement->fetchColumn();
    }

    public function softDeleteByActorId(int $remoteActorId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE federated_posts SET deleted_at = CURRENT_TIMESTAMP
             WHERE remote_actor_id = :actor_id AND deleted_at IS NULL',
        );
        $statement->execute(['actor_id' => $remoteActorId]);
    }

    public function updateByObjectUri(
        string $objectUri,
        ?string $title,
        ?string $content,
        ?string $canonicalUrl,
        string $visibility,
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE federated_posts
             SET title = :title, content = :content, canonical_url = :canonical_url,
                 visibility = :visibility, fetched_at = CURRENT_TIMESTAMP
             WHERE object_uri = :object_uri',
        );
        $statement->execute([
            'object_uri' => $objectUri,
            'title' => $title,
            'content' => $content,
            'canonical_url' => $canonicalUrl,
            'visibility' => $visibility,
        ]);
    }

    public function softDeleteByObjectUri(string $objectUri): void
    {
        $statement = $this->connection->prepare(
            'UPDATE federated_posts SET deleted_at = CURRENT_TIMESTAMP
             WHERE object_uri = :object_uri AND deleted_at IS NULL',
        );
        $statement->execute(['object_uri' => $objectUri]);
    }

    /**
     * Visible posts from actors this profile actively follows, newest
     * first — the federated half of the node's unified timeline (see
     * PostService::listWithFederated()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForProfile(int $profileId, int $limit, ?string $beforePublishedAt = null): array
    {
        $where = [
            'fc.profile_id = :profile_id',
            "fc.relationship_status IN ('FOLLOWING', 'CONNECTED')",
            "fp.visibility IN ('PUBLIC', 'UNLISTED')",
            'fp.deleted_at IS NULL',
            'fp.published_at IS NOT NULL',
        ];
        $parameters = ['profile_id' => $profileId];
        if ($beforePublishedAt !== null) {
            $where[] = 'fp.published_at < :before';
            $parameters['before'] = $beforePublishedAt;
        }

        $statement = $this->connection->prepare(
            'SELECT fp.public_id, fp.title, fp.content, fp.canonical_url, fp.published_at,
                    ra.display_name AS actor_display_name, ra.federated_address,
                    ra.canonical_url AS actor_canonical_url
             FROM federated_posts fp
             INNER JOIN federated_connections fc ON fc.remote_actor_id = fp.remote_actor_id
             INNER JOIN remote_actors ra ON ra.id = fp.remote_actor_id
             WHERE ' . implode(' AND ', $where)
            . ' ORDER BY fp.published_at DESC, fp.id DESC LIMIT ' . $limit,
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }
}