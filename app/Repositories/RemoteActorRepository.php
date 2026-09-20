<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RemoteActorRepository
{
    private const SELECT = '
        SELECT ra.id, ra.public_id, ra.remote_node_id, ra.actor_uri,
               ra.federated_address, ra.display_name, ra.avatar_url,
               ra.canonical_url, ra.fetched_at, ra.created_at, ra.updated_at,
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
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address,
                                        display_name, avatar_url, canonical_url)
             VALUES (:public_id, :remote_node_id, :actor_uri, :federated_address,
                     :display_name, :avatar_url, :canonical_url)',
        );
        $statement->execute([
            'public_id' => $publicId,
            'remote_node_id' => $remoteNodeId,
            'actor_uri' => $actorUri,
            'federated_address' => $federatedAddress,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'canonical_url' => $canonicalUrl,
        ]);

        return (int) $this->connection->lastInsertId();
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
    public function countByRemoteNodeId(int $remoteNodeId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM remote_actors WHERE remote_node_id = :remote_node_id',
        );
        $statement->execute(['remote_node_id' => $remoteNodeId]);

        return (int) $statement->fetchColumn();
    }
}