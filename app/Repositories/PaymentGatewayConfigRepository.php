<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Crypto;
use PDO;
use Throwable;

/**
 * Reads/writes `payment_gateways` (the static list of supported gateways)
 * and `payment_gateway_configs` (per-gateway, per-environment credentials,
 * encrypted at rest with Crypto). Lets the owner configure a gateway via
 * the API instead of only through the server's .env file.
 */
final class PaymentGatewayConfigRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findGatewayByCode(string $code): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM payment_gateways WHERE code = :code');
        $statement->execute(['code' => strtoupper($code)]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllGateways(): array
    {
        $statement = $this->connection->query('SELECT * FROM payment_gateways ORDER BY code ASC');

        return $statement->fetchAll();
    }

    /**
     * Config rows for a gateway, across both environments, with the
     * encrypted value replaced by a boolean `is_set` flag — the decrypted
     * secret itself is never returned by a listing endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listConfigMeta(int $gatewayId): array
    {
        $statement = $this->connection->prepare(
            'SELECT config_key, encrypted_value, environment, is_active, updated_at FROM payment_gateway_configs
             WHERE gateway_id = :gateway_id ORDER BY environment ASC, config_key ASC',
        );
        $statement->execute(['gateway_id' => $gatewayId]);

        $metadata = [];
        foreach ($statement->fetchAll() as $row) {
            try {
                Crypto::decrypt((string) $row['encrypted_value']);
                $row['is_decryptable'] = true;
            } catch (Throwable) {
                $row['is_decryptable'] = false;
            }
            unset($row['encrypted_value']);
            $metadata[] = $row;
        }

        return $metadata;
    }

    /**
     * Decrypted config for whichever environment is currently active for
     * this gateway (at most one environment is active at a time), or []
     * if nothing has been configured yet.
     *
     * @return array<string, string>
     */
    public function getActiveConfig(int $gatewayId): array
    {
        $statement = $this->connection->prepare(
            'SELECT config_key, encrypted_value FROM payment_gateway_configs
             WHERE gateway_id = :gateway_id AND is_active = 1',
        );
        $statement->execute(['gateway_id' => $gatewayId]);

        $values = [];
        foreach ($statement->fetchAll() as $row) {
            try {
                $values[(string) $row['config_key']] = Crypto::decrypt((string) $row['encrypted_value']);
            } catch (Throwable) {
                // A value that fails to decrypt (e.g. APP_KEY rotated without
                // re-entering credentials) is skipped rather than fatal, so
                // one bad row cannot break payment creation entirely.
                continue;
            }
        }

        return $values;
    }

    /**
     * The environment (SANDBOX/LIVE) currently marked active for a gateway,
     * or null if nothing has been configured yet.
     */
    public function getActiveEnvironment(int $gatewayId): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT DISTINCT environment FROM payment_gateway_configs WHERE gateway_id = :gateway_id AND is_active = 1 LIMIT 1',
        );
        $statement->execute(['gateway_id' => $gatewayId]);
        $environment = $statement->fetchColumn();

        return $environment === false ? null : (string) $environment;
    }

    /**
     * Replaces the config for one (gateway, environment) pair: encrypts and
     * upserts each provided key, and marks that environment active while
     * deactivating the gateway's other environment — only one environment
     * is ever "live" for a gateway at a time.
     *
     * @param array<string, string> $values
     */
    public function setConfig(int $gatewayId, string $environment, array $values): void
    {
        $this->connection->beginTransaction();
        try {
            $deactivate = $this->connection->prepare(
                'UPDATE payment_gateway_configs SET is_active = 0 WHERE gateway_id = :gateway_id AND environment != :environment',
            );
            $deactivate->execute(['gateway_id' => $gatewayId, 'environment' => $environment]);

            foreach ($values as $key => $value) {
                $encrypted = Crypto::encrypt($value);

                $existing = $this->connection->prepare(
                    'SELECT id FROM payment_gateway_configs
                     WHERE gateway_id = :gateway_id AND config_key = :config_key AND environment = :environment',
                );
                $existing->execute(['gateway_id' => $gatewayId, 'config_key' => $key, 'environment' => $environment]);
                $id = $existing->fetchColumn();

                if ($id !== false) {
                    $update = $this->connection->prepare(
                        'UPDATE payment_gateway_configs
                         SET encrypted_value = :value, is_active = 1, updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id',
                    );
                    $update->execute(['value' => $encrypted, 'id' => $id]);
                } else {
                    $insert = $this->connection->prepare(
                        'INSERT INTO payment_gateway_configs (gateway_id, config_key, encrypted_value, environment, is_active)
                         VALUES (:gateway_id, :config_key, :value, :environment, 1)',
                    );
                    $insert->execute(['gateway_id' => $gatewayId, 'config_key' => $key, 'value' => $encrypted, 'environment' => $environment]);
                }
            }

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Upserts a plugin-discovered gateway's metadata (see
     * PaymentGatewayPluginService::syncInstalled()), marking it is_plugin=1
     * so PaymentService can tell it apart from a built-in gateway.
     *
     * @param array<int, string> $configKeys
     * @param array<string, bool> $capabilities
     */
    public function upsertPluginGateway(
        string $code,
        string $name,
        ?string $description,
        string $adapterClass,
        array $configKeys,
        array $capabilities,
    ): void {
        $normalizedCode = strtoupper($code);
        $params = [
            'code' => $normalizedCode,
            'name' => $name,
            'adapter_class' => $adapterClass,
            'description' => $description,
            'config_keys_json' => json_encode(array_values($configKeys)),
            'supports_refund' => (int) ($capabilities['supports_refund'] ?? false),
            'supports_recurring' => (int) ($capabilities['supports_recurring'] ?? false),
            'supports_qris' => (int) ($capabilities['supports_qris'] ?? false),
            'supports_va' => (int) ($capabilities['supports_va'] ?? false),
            'supports_credit_card' => (int) ($capabilities['supports_credit_card'] ?? false),
            'supports_ewallet' => (int) ($capabilities['supports_ewallet'] ?? false),
        ];

        if ($this->findGatewayByCode($normalizedCode) !== null) {
            $statement = $this->connection->prepare(
                'UPDATE payment_gateways
                 SET name = :name, adapter_class = :adapter_class, description = :description, is_plugin = 1,
                     config_keys_json = :config_keys_json, supports_refund = :supports_refund,
                     supports_recurring = :supports_recurring, supports_qris = :supports_qris,
                     supports_va = :supports_va, supports_credit_card = :supports_credit_card,
                     supports_ewallet = :supports_ewallet
                 WHERE code = :code',
            );
        } else {
            $statement = $this->connection->prepare(
                'INSERT INTO payment_gateways
                     (code, name, adapter_class, description, is_plugin, config_keys_json,
                      supports_refund, supports_recurring, supports_qris, supports_va, supports_credit_card, supports_ewallet)
                 VALUES
                     (:code, :name, :adapter_class, :description, 1, :config_keys_json,
                      :supports_refund, :supports_recurring, :supports_qris, :supports_va, :supports_credit_card, :supports_ewallet)',
            );
        }

        $statement->execute($params);
    }

    /**
     * Resolves the array a PaymentGatewayInterface adapter's constructor
     * expects, from whatever environment is currently active in the DB.
     * Returns [] when nothing is configured, so callers fall back to the
     * gateway's own Config::get()/.env defaults unchanged.
     *
     * @return array<string, mixed>
     */
    public function resolveConfiguration(string $gatewayCode): array
    {
        $gateway = $this->findGatewayByCode($gatewayCode);
        if ($gateway === null) {
            return [];
        }

        $gatewayId = (int) $gateway['id'];
        $values = $this->getActiveConfig($gatewayId);
        if ($values === []) {
            return [];
        }

        $environment = $this->getActiveEnvironment($gatewayId);
        if (in_array(strtoupper($gatewayCode), ['MIDTRANS', 'PAYPAL', 'IPAYMU'], true) && $environment !== null) {
            $values['environment'] = $environment === 'LIVE' ? 'PRODUCTION' : 'SANDBOX';
        }

        return $values;
    }
}
