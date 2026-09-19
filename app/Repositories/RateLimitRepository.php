<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

final class RateLimitRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Records one hit for the given (already window-scoped) rate key and
     * returns the total number of hits recorded for that key so far.
     */
    public function increment(string $rateKey, string $windowStartedAt): int
    {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO rate_limits (rate_key, attempts, window_started_at) VALUES (:rate_key, 1, :window_started_at)',
            );
            $statement->execute(['rate_key' => $rateKey, 'window_started_at' => $windowStartedAt]);

            return 1;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $update = $this->connection->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE rate_key = :rate_key');
        $update->execute(['rate_key' => $rateKey]);

        $select = $this->connection->prepare('SELECT attempts FROM rate_limits WHERE rate_key = :rate_key');
        $select->execute(['rate_key' => $rateKey]);

        return (int) $select->fetchColumn();
    }
}
