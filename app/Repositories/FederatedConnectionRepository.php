<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FederatedConnectionRepository
{
    private const SELECT_PUBLIC = '
        SELECT fc.id, fc.public_id, fc.profile_id, fc.remote_actor_id,
               fc.relationship_status, fc.show_on_profile, fc.accepted_at,
               fc.created_at, fc.updated_at,
               ra.public_id AS actor_public_id, ra.actor_uri, ra.federated_address,
               ra.display_name AS actor_display_name, ra.avatar_url AS actor_avatar_url,
               ra.canonical_url AS actor_canonical_url,
               rn.domain AS node_domain, rn.name AS node_name
        FROM federated_connections fc
        INNER JOIN remote_actors ra ON ra.id = fc.remote_actor_id
        INNER JOIN remote_nodes rn ON rn.id = ra.remote_node_id
    ';

    private const RELATIONSHIPS_ALLOWED_PUBLIC = ['FOLLOWING', 'CONNECTED'];

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        string $publicId,
        int $profileId,
        int $remoteActorId,
        string $relationshipStatus = 'PENDING',
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status, accepted_at)
             VALUES (:public_id, :profile_id, :remote_actor_id, :relationship_status,
                     CASE WHEN :relationship_status IN (\'CONNECTED\', \'FOLLOWING\') THEN CURRENT_TIMESTAMP ELSE NULL END)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'profile_id' => $profileId,
            'remote_actor_id' => $remoteActorId,
            'relationship_status' => $relationshipStatus,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId, bool $includeAll = false): ?array
    {
        $where = $includeAll ? 'fc.public_id = :public_id' : 'fc.public_id = :public_id';
        $statement = $this->connection->prepare(self::SELECT_PUBLIC . ' WHERE ' . $where);
        $statement->execute(['public_id' => $publicId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Finds a connection by internal ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(self::SELECT_PUBLIC . ' WHERE fc.id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param int $profileId Local profile ID
     * @param int $limit Max results (actual query fetches limit+1 for has-more detection)
     * @param int|null $beforeId Cursor: return items with id < beforeId
     * @return array<int, array<string, mixed>>
     */
    public function listPublicByProfileId(int $profileId, int $limit, ?int $beforeId = null): array
    {
        $where = [
            'fc.profile_id = :profile_id',
            'fc.show_on_profile = 1',
            "fc.relationship_status IN ('FOLLOWING', 'CONNECTED')",
            "(rn.trust_state IS NULL OR rn.trust_state != 'BLOCKED')",
            "(rn.status IS NULL OR rn.status != 'SUSPENDED')",
        ];
        $parameters = ['profile_id' => $profileId];

        if ($beforeId !== null) {
            $where[] = 'fc.id < :before_id';
            $parameters['before_id'] = $beforeId;
        }

        $statement = $this->connection->prepare(
            self::SELECT_PUBLIC
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY fc.id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByProfileId(int $profileId): array
    {
        $statement = $this->connection->prepare(
            self::SELECT_PUBLIC . ' WHERE fc.profile_id = :profile_id ORDER BY fc.id DESC',
        );
        $statement->execute(['profile_id' => $profileId]);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $fields Allowed keys: show_on_profile, relationship_status
     */
    public function updateByPublicId(string $publicId, array $fields): void
    {
        $assignments = [];
        $parameters = ['public_id' => $publicId];

        foreach (['show_on_profile', 'relationship_status'] as $column) {
            if (array_key_exists($column, $fields)) {
                $assignments[] = $column . ' = :' . $column;
                $parameters[$column] = $fields[$column];
            }
        }

        if ($assignments === []) {
            return;
        }

        // If connecting or following, set accepted_at
        if (array_key_exists('relationship_status', $fields)
            && in_array($fields['relationship_status'], ['CONNECTED', 'FOLLOWING'], true)) {
            $assignments[] = 'accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP)';
        }

        $statement = $this->connection->prepare(
            'UPDATE federated_connections SET ' . implode(', ', $assignments)
            . ' WHERE public_id = :public_id',
        );
        $statement->execute($parameters);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProfileAndActorId(int $profileId, int $remoteActorId): ?array
    {
        $statement = $this->connection->prepare(
            self::SELECT_PUBLIC . ' WHERE fc.profile_id = :profile_id AND fc.remote_actor_id = :actor_id',
        );
        $statement->execute(['profile_id' => $profileId, 'actor_id' => $remoteActorId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findConnectionsByActorId(int $remoteActorId): array
    {
        $statement = $this->connection->prepare(
            self::SELECT_PUBLIC . ' WHERE fc.remote_actor_id = :actor_id ORDER BY fc.id DESC',
        );
        $statement->execute(['actor_id' => $remoteActorId]);

        return $statement->fetchAll();
    }
}