<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Caches the public signing key discovered from a remote node's
 * federation capability document, used to verify inbound activity signatures.
 */
final class RemoteNodeKeyRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function upsert(int $remoteNodeId, string $keyType, string $publicKey): void
    {
        $fingerprint = hash('sha256', $publicKey);

        if ($this->findByRemoteNodeId($remoteNodeId) !== null) {
            $statement = $this->connection->prepare(
                'UPDATE remote_node_keys SET key_type = :key_type, public_key = :public_key,
                 fingerprint = :fingerprint, fetched_at = CURRENT_TIMESTAMP WHERE remote_node_id = :remote_node_id',
            );
        } else {
            $statement = $this->connection->prepare(
                'INSERT INTO remote_node_keys (remote_node_id, key_type, public_key, fingerprint)
                 VALUES (:remote_node_id, :key_type, :public_key, :fingerprint)',
            );
        }

        $statement->execute([
            'remote_node_id' => $remoteNodeId,
            'key_type' => $keyType,
            'public_key' => $publicKey,
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRemoteNodeId(int $remoteNodeId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM remote_node_keys WHERE remote_node_id = :remote_node_id',
        );
        $statement->execute(['remote_node_id' => $remoteNodeId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
