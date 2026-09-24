<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Crypto;
use PDO;
use Throwable;

/**
 * Reads/writes `llm_configs`: one row per node, the API key encrypted at
 * rest with Crypto (same approach as `payment_gateway_configs`).
 */
final class LlmConfigRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByNodeId(int $nodeId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM llm_configs WHERE node_id = :node_id');
        $statement->execute(['node_id' => $nodeId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Decrypted config for a node, or null if nothing is configured or the
     * stored key fails to decrypt (e.g. APP_KEY rotated) — treated the same
     * as "not configured" rather than fatal, mirroring
     * PaymentGatewayConfigRepository::getActiveConfig().
     *
     * @return array{provider_code: string, model: string, api_key: string, supports_vision: bool}|null
     */
    public function getActiveConfig(int $nodeId): ?array
    {
        $row = $this->findByNodeId($nodeId);
        if ($row === null) {
            return null;
        }

        try {
            $apiKey = Crypto::decrypt((string) $row['encrypted_api_key']);
        } catch (Throwable) {
            return null;
        }

        return [
            'provider_code' => (string) $row['provider_code'],
            'model' => (string) $row['model'],
            'api_key' => $apiKey,
            'supports_vision' => (bool) $row['supports_vision'],
        ];
    }

    public function upsert(int $nodeId, string $providerCode, string $model, string $apiKey, bool $supportsVision): void
    {
        $encrypted = Crypto::encrypt($apiKey);
        $existing = $this->findByNodeId($nodeId);

        if ($existing === null) {
            $statement = $this->connection->prepare(
                'INSERT INTO llm_configs (node_id, provider_code, model, encrypted_api_key, supports_vision)
                 VALUES (:node_id, :provider_code, :model, :encrypted_api_key, :supports_vision)',
            );
        } else {
            $statement = $this->connection->prepare(
                'UPDATE llm_configs
                 SET provider_code = :provider_code, model = :model, encrypted_api_key = :encrypted_api_key,
                     supports_vision = :supports_vision, updated_at = CURRENT_TIMESTAMP
                 WHERE node_id = :node_id',
            );
        }

        $statement->execute([
            'node_id' => $nodeId,
            'provider_code' => $providerCode,
            'model' => $model,
            'encrypted_api_key' => $encrypted,
            'supports_vision' => (int) $supportsVision,
        ]);
    }
}
