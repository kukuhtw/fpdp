<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FollowRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param string $direction 'OUTGOING' (a follow we sent — someone we
     *        follow) or 'INCOMING' (a follow we received — one of our
     *        followers). Required so followers and following can be told
     *        apart; both directions share this one table.
     */
    public function create(string $publicId, int $profileId, string $targetActorUri, ?string $targetFedAddress = null, ?int $remoteActorId = null, ?string $activityPublicId = null, string $direction = 'OUTGOING'): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO follows (public_id, profile_id, remote_actor_id, target_actor_uri, target_federated_address, activity_public_id, status, direction)
             VALUES (:public_id, :profile_id, :remote_actor_id, :target_actor_uri, :target_federated_address, :activity_public_id, :status, :direction)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'profile_id' => $profileId,
            'remote_actor_id' => $remoteActorId,
            'target_actor_uri' => $targetActorUri,
            'target_federated_address' => $targetFedAddress,
            'activity_public_id' => $activityPublicId,
            'status' => 'PENDING',
            'direction' => $direction,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * $direction disambiguates a mutual-follow pair: without it, "we
     * follow X" (OUTGOING, target_actor_uri = X) and "X follows us"
     * (INCOMING, target_actor_uri = X) are indistinguishable, since both
     * store the other party's actor URI in the same column. A caller
     * checking for an existing OUTGOING relationship that instead matches
     * an unrelated INCOMING one (or vice versa) silently misbehaves —
     * e.g. an inbound Follow from someone we already follow gets treated
     * as "already following", and no incoming request is ever recorded.
     */
    public function findByProfileAndTarget(int $profileId, string $targetActorUri, ?string $direction = null): ?array
    {
        $where = 'profile_id = :profile_id AND target_actor_uri = :target_actor_uri';
        $parameters = ['profile_id' => $profileId, 'target_actor_uri' => $targetActorUri];
        if ($direction !== null) {
            $where .= ' AND direction = :direction';
            $parameters['direction'] = $direction;
        }

        $statement = $this->connection->prepare("SELECT * FROM follows WHERE {$where}");
        $statement->execute($parameters);

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Followers: remote actors who sent us a Follow (direction = INCOMING).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFollowersByProfileId(int $profileId): array
    {
        $statement = $this->connection->prepare(
            "SELECT f.*, ra.display_name AS actor_display_name, ra.avatar_url AS actor_avatar_url, ra.federated_address, rn.domain AS node_domain
             FROM follows f
             LEFT JOIN remote_actors ra ON ra.id = f.remote_actor_id
             LEFT JOIN remote_nodes rn ON rn.id = ra.remote_node_id
             WHERE f.profile_id = :profile_id AND f.status = 'ACCEPTED' AND f.direction = 'INCOMING'
             ORDER BY f.created_at DESC",
        );
        $statement->execute(['profile_id' => $profileId]);

        return $statement->fetchAll();
    }

    /**
     * Following: remote actors we sent a Follow to (direction = OUTGOING).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFollowingByProfileId(int $profileId): array
    {
        $statement = $this->connection->prepare(
            "SELECT f.*, ra.display_name AS target_display_name, ra.avatar_url AS target_avatar_url, ra.federated_address, rn.domain AS node_domain
             FROM follows f
             LEFT JOIN remote_actors ra ON ra.id = f.remote_actor_id
             LEFT JOIN remote_nodes rn ON rn.id = ra.remote_node_id
             WHERE f.profile_id = :profile_id AND f.status = 'ACCEPTED' AND f.direction = 'OUTGOING'
             ORDER BY f.created_at DESC",
        );
        $statement->execute(['profile_id' => $profileId]);

        return $statement->fetchAll();
    }

    /**
     * Accepted followers this profile has no outgoing follow (pending,
     * accepted, or blocked) to — the "follow back" suggestions. Actors on
     * blocked remote nodes are left out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFollowBackCandidates(int $profileId, int $limit): array
    {
        $statement = $this->connection->prepare(
            "SELECT f.target_actor_uri AS actor_uri, f.created_at AS followed_at,
                    ra.display_name, ra.avatar_url, ra.canonical_url, ra.federated_address, rn.domain AS node_domain
             FROM follows f
             LEFT JOIN remote_actors ra ON ra.id = f.remote_actor_id
             LEFT JOIN remote_nodes rn ON rn.id = ra.remote_node_id
             WHERE f.profile_id = :profile_id AND f.direction = 'INCOMING' AND f.status = 'ACCEPTED'
               AND COALESCE(rn.trust_state, 'UNKNOWN') <> 'BLOCKED'
               AND NOT EXISTS (
                   SELECT 1 FROM follows o
                   WHERE o.profile_id = f.profile_id AND o.direction = 'OUTGOING'
                     AND o.target_actor_uri = f.target_actor_uri
                     AND o.status IN ('PENDING', 'ACCEPTED', 'BLOCKED')
               )
             ORDER BY f.created_at DESC
             LIMIT :limit",
        );
        $statement->bindValue('profile_id', $profileId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function countByDirection(int $profileId, string $direction): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM follows WHERE profile_id = :profile_id AND status = 'ACCEPTED' AND direction = :direction",
        );
        $statement->execute(['profile_id' => $profileId, 'direction' => $direction]);

        return (int) $statement->fetchColumn();
    }

    public function countPendingByProfileId(int $profileId): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM follows WHERE profile_id = :profile_id AND status = 'PENDING' AND direction = 'INCOMING'",
        );
        $statement->execute(['profile_id' => $profileId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Incoming follow requests awaiting the owner's manual approve/reject.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPendingByProfileId(int $profileId): array
    {
        $statement = $this->connection->prepare(
            "SELECT f.*, ra.display_name AS actor_display_name, ra.avatar_url AS actor_avatar_url, ra.federated_address, rn.domain AS node_domain
             FROM follows f
             LEFT JOIN remote_actors ra ON ra.id = f.remote_actor_id
             LEFT JOIN remote_nodes rn ON rn.id = ra.remote_node_id
             WHERE f.profile_id = :profile_id AND f.status = 'PENDING' AND f.direction = 'INCOMING'
             ORDER BY f.created_at ASC",
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

    /**
     * Finds the follow row that a Follow activity we sent created, by that
     * activity's id. Used to resolve inbound Accept/Reject responses back
     * to the local follow request they answer.
     *
     * @return array<string, mixed>|null
     */
    public function findByActivityPublicId(string $activityPublicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM follows WHERE activity_public_id = :activity_public_id',
        );
        $statement->execute(['activity_public_id' => $activityPublicId]);

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fallback for matching an inbound Accept/Reject when its object id
     * doesn't match activity_public_id — real-world resends (see
     * FederationService::sendFollow()) generate a fresh activity id each
     * time, so a response to a stale attempt won't exact-match the latest
     * one. (profile_id, target_actor_uri) is unique, so a still-PENDING
     * outgoing follow to the actor who's responding is an unambiguous match
     * regardless of which specific attempt they're actually answering.
     *
     * @return array<string, mixed>|null
     */
    public function findPendingOutgoingByTarget(string $targetActorUri): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT * FROM follows WHERE target_actor_uri = :target_actor_uri AND direction = 'OUTGOING' AND status = 'PENDING'",
        );
        $statement->execute(['target_actor_uri' => $targetActorUri]);

        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}