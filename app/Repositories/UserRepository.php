<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(
        string $publicId,
        int $nodeId,
        string $email,
        string $passwordHash,
        string $role = 'OWNER',
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO users (public_id, node_id, email, password_hash, role, status)
             VALUES (:public_id, :node_id, :email, :password_hash, :role, :status)',
        );

        $statement->execute([
            'public_id' => $publicId,
            'node_id' => $nodeId,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'status' => 'ACTIVE',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function emailExists(string $email): bool
    {
        $statement = $this->connection->prepare('SELECT 1 FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);

        return $statement->fetchColumn() !== false;
    }
}
