<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ProfileRepository
{
    private const SELECT = '
        SELECT profiles.*, nodes.id AS node_id, nodes.domain AS node_domain
        FROM profiles
        INNER JOIN users ON users.id = profiles.user_id
        INNER JOIN nodes ON nodes.id = users.node_id
    ';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $publicId, int $userId, string $handle, string $displayName): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO profiles (public_id, user_id, handle, display_name, visibility, links)
             VALUES (:public_id, :user_id, :handle, :display_name, :visibility, :links)',
        );

        $statement->execute([
            'public_id' => $publicId,
            'user_id' => $userId,
            'handle' => $handle,
            'display_name' => $displayName,
            'visibility' => 'PUBLIC',
            'links' => json_encode([]),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE profiles.user_id = :user_id');
        $statement->execute(['user_id' => $userId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE profiles.id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Returns the first profile belonging to the given node, i.e. the
     * node owner's profile. FPDP deployments currently host a single
     * owner per node, so this resolves "this node's public home".
     *
     * @return array<string, mixed>|null
     */
    public function findByNodeId(int $nodeId): ?array
    {
        $statement = $this->connection->prepare(
            self::SELECT . ' WHERE nodes.id = :node_id ORDER BY profiles.id ASC LIMIT 1',
        );
        $statement->execute(['node_id' => $nodeId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHandle(string $handle): ?array
    {
        $statement = $this->connection->prepare(self::SELECT . ' WHERE profiles.handle = :handle');
        $statement->execute(['handle' => $handle]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function handleExists(string $handle): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM profiles WHERE handle = :handle');
        $statement->execute(['handle' => $handle]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function update(int $userId, array $fields): array
    {
        $columns = ['display_name', 'bio', 'avatar_url', 'visibility'];
        $assignments = [];
        $parameters = ['user_id' => $userId];

        foreach ($columns as $column) {
            if (array_key_exists($column, $fields)) {
                $assignments[] = "{$column} = :{$column}";
                $parameters[$column] = $fields[$column];
            }
        }

        if ($assignments !== []) {
            $sql = 'UPDATE profiles SET ' . implode(', ', $assignments) . ' WHERE user_id = :user_id';
            $statement = $this->connection->prepare($sql);
            $statement->execute($parameters);
        }

        return $this->findByUserId($userId);
    }
}
