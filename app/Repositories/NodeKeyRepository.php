<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NodeKeyRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(int $nodeId, string $keyType, string $publicKey, string $privateKey, string $fingerprint): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO node_keys (node_id, key_type, public_key, private_key, fingerprint, is_current)
             VALUES (:node_id, :key_type, :public_key, :private_key, :fingerprint, 1)',
        );
        $statement->execute([
            'node_id' => $nodeId,
            'key_type' => $keyType,
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'fingerprint' => $fingerprint,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Scoped to key_type so an old key of a different type (e.g. a
     * pre-ActivityPub ed25519 row from before RSA became the only type
     * this app generates/signs with) is never picked up by mistake and
     * doesn't block generating the type actually needed.
     *
     * @return array<string, mixed>|null
     */
    public function findCurrentByNodeId(int $nodeId, string $keyType = 'rsa'): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM node_keys WHERE node_id = :node_id AND key_type = :key_type AND is_current = 1 ORDER BY id DESC LIMIT 1',
        );
        $statement->execute(['node_id' => $nodeId, 'key_type' => $keyType]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function keyExists(int $nodeId, string $keyType = 'rsa'): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM node_keys WHERE node_id = :node_id AND key_type = :key_type AND is_current = 1',
        );
        $statement->execute(['node_id' => $nodeId, 'key_type' => $keyType]);

        return $statement->fetchColumn() !== false;
    }
}