<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RemoteActorRepository
{
    private const SELECT = '
        SELECT ra.id, ra.public_id, ra.remote_node_id, ra.actor_uri,
               ra.federated_address, ra.display_name, ra.avatar_url,
               ra.canonical_url, ra.inbox_url, ra.shared_inbox_url,
               ra.public_key_id, ra.public_key_pem,
               ra.fetched_at, ra.created_at, ra.updated_at,
               rn.domain AS node_domain, rn.name AS node_name,
               rn.trust_state AS node_trust_state, rn.status AS node_status
        FROM remote_actors ra
        INNER JOIN remote_nodes rn ON rn.id = ra.remote_node_id
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        string $publicId,
        int $remoteNodeId,
        string $actorUri,
        string $federatedAddress,
        ?string $displayName = null,
        ?string $avatarUrl = null,
        ?string $canonicalUrl = null,
        ?string $inboxUrl = null,
        ?string $sharedInboxUrl = null,
        ?string $publicKeyId = null,
        ?string $publicKeyPem = null,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address,
                                        display_name, avatar_url, canonical_url,
                                        inbox_url, shared_inbox_url, public_key_id, public_key_pem)
             VALUES (:public_id, :remote_node_id, :actor_uri, :federated_address,
                     :display_name, :avatar_url, :canonical_url,
                     :inbox_url, :shared_inbox_url, :public_key_id, :public_key_pem)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'remote_node_id' => $remoteNodeId,
            'actor_uri' => $actorUri,
            'federated_address' => $federatedAddress,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'canonical_url' => $canonicalUrl,
            'inbox_url' => $inboxUrl,
            'shared_inbox_url' => $sharedInboxUrl,
            'public_key_id' => $publicKeyId,
            'public_key_pem' => $publicKeyPem,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Refreshes an already-known actor's ActivityPub metadata (re-fetched
     * from its actor document) without touching its local public_id/relationships.
     */
    public function updateActivityPubFields(
        int $id,
        ?string $displayName,
        ?string $avatarUrl,
        ?string $inboxUrl,
        ?string $sharedInboxUrl,
        ?string $publicKeyId,
        ?string $publicKeyPem,
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE remote_actors SET display_name = :display_name, avatar_url = :avatar_url,
                 inbox_url = :inbox_url, shared_inbox_url = :shared_inbox_url,
                 public_key_id = :public_key_id, public_key_pem = :public_key_pem,
                 fetched_at = CURRENT_TIMESTAMP
             WHERE id = :id',
        );
        $statement->execute([
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'inbox_url' => $inboxUrl,
            'shared_inbox_url' => $sharedInboxUrl,
            'public_key_id' => $publicKeyId,
            'public_key_pem' => $publicKeyPem,
            'id' => $id,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicKeyId(string $publicKeyId): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE ra.public_key_id = :public_key_id');
        $statement->execute(['public_key_id' => $publicKeyId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByActorUri(string $actorUri): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE ra.actor_uri = :actor_uri');
        $statement->execute(['actor_uri' => $actorUri]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByFederatedAddress(string $address): ?array
    {
        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ra.federated_address = :address',
        );
        $statement->execute(['address' => $address]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE ra.id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE ra.public_id = :public_id',
        );
        $statement->execute(['public_id' => $publicId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function updateProfile(int $id, ?string $displayName, ?string $avatarUrl, ?string $canonicalUrl): void
    {
        $statement = $this->connection->prepare(
            'UPDATE remote_actors SET display_name = :display_name, avatar_url = :avatar_url,
             canonical_url = :canonical_url, fetched_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $statement->execute([
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'canonical_url' => $canonicalUrl,
            'id' => $id,
        ]);
    }

    /**
     * Count how many remote actors are registered under a given remote node.
     */
    /**
     * Remote actors whose posts have reached this node but whom the profile
     * does not currently follow (e.g. after an unfollow), most active first
     * — the "you've seen them before" suggestions. Excludes blocked nodes
     * and actors the profile blocked.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findKnownUnfollowedAuthors(int $profileId, int $limit): array
    {
        $statement = $this->connection->prepare(
            "SELECT ra.actor_uri, ra.display_name, ra.avatar_url, ra.canonical_url, ra.federated_address,
                    rn.domain AS node_domain, COUNT(fp.id) AS post_count, MAX(fp.published_at) AS last_post_at
             FROM remote_actors ra
             INNER JOIN remote_nodes rn ON rn.id = ra.remote_node_id
             INNER JOIN federated_posts fp ON fp.remote_actor_id = ra.id AND fp.deleted_at IS NULL
             WHERE rn.trust_state <> 'BLOCKED'
               AND NOT EXISTS (
                   SELECT 1 FROM follows o
                   WHERE o.profile_id = :profile_id AND o.direction = 'OUTGOING'
                     AND o.target_actor_uri = ra.actor_uri
                     AND o.status IN ('PENDING', 'ACCEPTED', 'BLOCKED')
               )
             GROUP BY ra.id, ra.actor_uri, ra.display_name, ra.avatar_url, ra.canonical_url, ra.federated_address, rn.domain
             ORDER BY post_count DESC, last_post_at DESC
             LIMIT :limit",
        );
        $statement->bindValue('profile_id', $profileId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function countByRemoteNodeId(int $remoteNodeId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM remote_actors WHERE remote_node_id = :remote_node_id',
        );
        $statement->execute(['remote_node_id' => $remoteNodeId]);

        return (int) $statement->fetchColumn();
    }
}