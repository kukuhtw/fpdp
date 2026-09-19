<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FollowRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $profileId, string $targetActorUri, ?string $targetFedAddress = null, ?int $remoteActorId = null, ?string $activityPublicId = null): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO follows (public_id, profile_id, remote_actor_id, target_actor_uri, target_federated_address, activity_public_id, status)
             VALUES (:public_id, :profile_id, :remote_actor_id, :target_actor_uri, :target_federated_address, :activity_public_id, :status)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'profile_id' => $profileId,
            'remote_actor_id' => $remoteActorId,
            'target_actor_uri' => $targetActorUri,
            'target_federated_address' => $targetFedAddress,
            'activity_public_id' => $activityPublicId,
            'status' => 'PENDING',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProfileAndTarget(int $profileId, string $targetActorUri): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM follows WHERE profile_id = :profile_id AND target_actor_uri = :target_actor_uri',
        );
        $statement->execute(['profile_id' => $profileId, 'target_actor_uri' => $targetActorUri]);

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findFollowersByProfileId(int $profileId): array
    {
        $statement = $this->connection->prepare(
            "SELECT f.*, ra.display_name AS actor_display_name, ra.avatar_url AS actor_avatar_url, ra.federated_address, rn.domain AS node_domain
             FROM follows f
             LEFT JOIN remote_actors ra ON ra.id = f.remote_actor_id
             LEFT JOIN remote_nodes rn ON rn.id = ra.remote_node_id
             WHERE f.profile_id = :profile_id AND f.status = 'ACCEPTED'
             ORDER BY f.created_at DESC",
        );
        $statement->execute(['profile_id' => $profileId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findFollowingByProfileId(int $profileId): array
    {
        $statement = $this->connection->prepare(
            "SELECT f.*, ra.display_name AS target_display_name, ra.avatar_url AS target_avatar_url, ra.federated_address, rn.domain AS node_domain
             FROM follows f
             LEFT JOIN remote_actors ra ON ra.id = f.remote_actor_id
             LEFT JOIN remote_nodes rn ON rn.id = ra.remote_node_id
             WHERE f.profile_id = :profile_id AND f.status = 'ACCEPTED'
             ORDER BY f.created_at DESC",
        );
        $statement->execute(['profile_id' => $profileId]);

        return $statement->fetchAll();
    }

    public function updateStatus(string $publicId, string $status, ?string $activityPublicId = null): void
    {
        $statement = $this->connection->prepare(
            "UPDATE follows SET status = :status, activity_public_id = COALESCE(:activity_pid, activity_public_id), accepted_at = CASE WHEN :status = 'ACCEPTED' THEN CURRENT_TIMESTAMP ELSE accepted_at END, updated_at = CURRENT_TIMESTAMP WHERE public_id = :public_id",
        );
        $statement->execute([
            'public_id' => $publicId,
            'status' => $status,
            'activity_pid' => $activityPublicId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM follows WHERE public_id = :public_id');
        $statement->execute(['public_id' => $publicId]);

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}