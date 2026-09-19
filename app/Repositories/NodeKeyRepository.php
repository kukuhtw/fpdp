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
     * @return array<string, mixed>|null
     */
    public function findCurrentByNodeId(int $nodeId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM node_keys WHERE node_id = :node_id AND is_current = 1 ORDER BY id DESC LIMIT 1',
        );
        $statement->execute(['node_id' => $nodeId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function keyExists(int $nodeId): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM node_keys WHERE node_id = :node_id AND is_current = 1');
        $statement->execute(['node_id' => $nodeId]);

        return $statement->fetchColumn() !== false;
    }
}