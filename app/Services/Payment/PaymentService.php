<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Exceptions\UnsupportedProviderException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\NodeRepository;
use App\Repositories\PaymentGatewayConfigRepository;
use App\Repositories\PaymentRepository;

final class PaymentService
{
    private const TERMINAL_STATUSES = ['PAID', 'FAILED', 'CANCELLED'];
    private const ALLOWED_ENVIRONMENTS = ['SANDBOX', 'LIVE'];

    /**
     * Config keys each gateway's adapter constructor actually reads (see
     * PaywuzGateway/MidtransGateway's $configuration doc blocks). Anything
     * outside this list is rejected rather than silently stored.
     */
    private const ALLOWED_CONFIG_KEYS = [
        'DUMMY' => [],
        'PAYWUZ' => ['api_key', 'api_url'],
        'MIDTRANS' => ['server_key'],
        'PAYPAL' => ['client_id', 'client_secret', 'webhook_id'],
    ];

    public function __construct(
        private readonly PaymentGatewayFactory $factory = new PaymentGatewayFactory(),
        private ?PaymentRepository $payments = null,
        private ?PaymentGatewayConfigRepository $gatewayConfigs = null,
        private ?NodeRepository $nodes = null,
        private ?PaymentCredentialVerifier $credentialVerifier = null,
        private ?PaymentGatewayPluginService $pluginService = null,
    ) {
    }

    /**
     * Creates a payment with the gateway and persists a local `payments`
     * row for it (status as returned by the gateway, typically PENDING),
     * so its lifecycle can be tracked across the webhook that confirms it.
     *
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>
     */
    public function createPayment(string $gatewayCode, array $paymentData): array
    {
        $configuration = array_merge(
            $this->getGatewayConfigRepository()->resolveConfiguration($gatewayCode),
            $paymentData['configuration'] ?? [],
        );
        $gateway = $this->resolveGateway($gatewayCode, $configuration);
        $result = $gateway->createPayment($paymentData);

        $metadata = isset($paymentData['metadata']) && is_array($paymentData['metadata'])
            ? $paymentData['metadata']
            : null;

        $this->getRepository()->create(
            Uuid::v4(),
            (string) ($result['order_id'] ?? ''),
            strtoupper($gatewayCode),
            isset($result['external_transaction_id']) ? (string) $result['external_transaction_id'] : null,
            isset($result['payment_method']) ? (string) $result['payment_method'] : null,
            (string) ($result['currency'] ?? 'IDR'),
            (float) ($result['amount'] ?? 0),
            (string) ($result['status'] ?? 'PENDING'),
            isset($result['payment_url']) ? (string) $result['payment_url'] : null,
            self::normalizeTimestamp($result['expired_at'] ?? null),
            $metadata,
        );

        return $result;
    }

    public function getGateway(string $gatewayCode, array $configuration = []): PaymentGatewayInterface
    {
        $resolved = array_merge($this->getGatewayConfigRepository()->resolveConfiguration($gatewayCode), $configuration);

        return $this->resolveGateway($gatewayCode, $resolved);
    }

    /**
     * Verifies and processes an inbound gateway webhook: signature check,
     * idempotent event recording, and a PENDING -> PAID/FAILED/CANCELLED
     * transition on the matching local `payments` row.
     *
     * @param array<string, string> $headers
     * @return array{duplicate: bool, status_changed: bool, payment: array<string, mixed>|null}
     */
    public function handleWebhook(string $gatewayCode, array $headers, string $rawBody): array
    {
        $configuration = $this->getGatewayConfigRepository()->resolveConfiguration($gatewayCode);
        $gateway = $this->resolveGateway($gatewayCode, $configuration);
        if (!$gateway->verifyWebhook($headers, $rawBody)) {
            throw new UnauthorizedException('Invalid webhook signature.');
        }

        $event = $gateway->handleWebhook($headers, $rawBody);
        $repository = $this->getRepository();

        $orderId = isset($event['order_id']) ? (string) $event['order_id'] : '';
        $payment = $orderId !== '' ? $repository->findByOrderId($orderId) : null;
        if ($payment === null) {
            return ['duplicate' => false, 'status_changed' => false, 'payment' => null];
        }

        $recorded = $repository->recordTransactionEvent(
            (int) $payment['id'],
            strtoupper($gatewayCode),
            isset($event['event_id']) ? (string) $event['event_id'] : null,
            (string) ($event['event_type'] ?? 'UNKNOWN'),
            (string) ($event['status'] ?? 'UNKNOWN'),
            (string) json_encode($event),
        );

        if (!$recorded) {
            return ['duplicate' => true, 'status_changed' => false, 'payment' => $payment];
        }

        $statusChanged = false;
        $eventStatus = (string) ($event['status'] ?? '');
        if ((string) $payment['status'] === 'PENDING' && in_array($eventStatus, self::TERMINAL_STATUSES, true)) {
            $repository->markStatus((int) $payment['id'], $eventStatus);
            $payment = $repository->findByOrderId($orderId);
            $statusChanged = true;
        }

        return ['duplicate' => false, 'status_changed' => $statusChanged, 'payment' => $payment];
    }

    /**
     * Dashboard summary for the owner's Payments page: an approximate
     * balance/settlement view derived from `payments`, not a real
     * settlement ledger (no fees, payouts, or withdrawals are tracked).
     *
     * @return array{available_balance: float, pending_settlement: float, paid_count: int, failed_count: int, cancelled_count: int, success_rate: float, revenue_this_month: float, currency: string, recent_transactions: array<int, array<string, mixed>>}
     */
    public function getPaymentsSummary(int $recentLimit = 20): array
    {
        $summary = $this->getRepository()->getSummary();
        $summary['recent_transactions'] = $this->getRepository()->findRecent($recentLimit);

        return $summary;
    }

    /**
     * Every PENDING payment, with its metadata decoded into a
     * human-readable purpose/reference — this is what lets the owner
     * manually confirm a gateway that has no automatic webhook to rely on
     * (chiefly the bundled Manual Transfer plugin, but nothing here is
     * gateway-specific).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPendingPayments(int $limit = 50): array
    {
        return array_map(static function (array $payment): array {
            $metadata = is_string($payment['metadata'] ?? null) ? json_decode($payment['metadata'], true) : null;
            $payment['purpose'] = is_array($metadata) ? ($metadata['purpose'] ?? null) : null;

            return $payment;
        }, $this->getRepository()->findByStatus('PENDING', $limit));
    }

    /**
     * Owner-initiated manual confirmation: marks a PENDING payment PAID
     * without a gateway webhook. This trusts the owner's own judgement the
     * same way updateOrderStatus() already lets them override an order's
     * status by hand — appropriate for Manual Transfer (there is no
     * automatic signal at all; a human checking their bank account IS the
     * confirmation) and as a manual fallback for any gateway whose webhook
     * didn't arrive. Idempotent: confirming an already-PAID payment is a
     * harmless no-op rather than an error, so a double click can't fail —
     * `status_changed` tells the caller whether this call is what actually
     * transitioned it, so PaymentController::confirmPayment() only
     * dispatches fulfillment (grant CV access, credit the wallet, ...) once,
     * the same way handleWebhook()'s own status_changed flag already
     * prevents a retried webhook from re-crediting a wallet twice.
     *
     * @return array{status_changed: bool, payment: array<string, mixed>}
     */
    public function confirmPaymentManually(string $uuid): array
    {
        $repository = $this->getRepository();
        $payment = $repository->findByUuid($uuid);
        if ($payment === null) {
            throw new NotFoundException('Payment not found.');
        }

        $status = (string) $payment['status'];
        if ($status === 'PAID') {
            return ['status_changed' => false, 'payment' => $payment];
        }
        if ($status !== 'PENDING') {
            throw new ConflictException("This payment is already {$status} and cannot be confirmed.");
        }

        $repository->markStatus((int) $payment['id'], 'PAID');

        return ['status_changed' => true, 'payment' => $repository->findByUuid($uuid) ?? $payment];
    }

    /**
     * Owner-facing list of every supported gateway, whether it has SANDBOX/
     * LIVE credentials configured, and which environment is active. Never
     * includes a decrypted secret. Also returns the node's currently selected
     * active gateway (if any) and each gateway's webhook callback URL.
     *
     * @return array{active_gateway: string|null, gateways: array<int, array<string, mixed>>}
     */
    public function listGatewaySettings(?int $nodeId = null): array
    {
        $this->getPluginService()->syncInstalled();

        $configs = $this->getGatewayConfigRepository();
        $gateways = [];
        $domain = null;

        if ($nodeId !== null) {
            $node = $this->getNodeRepo()->findById($nodeId);
            $domain = $node !== null ? (string) ($node['domain'] ?? '') : null;
        }

        foreach ($configs->findAllGateways() as $gateway) {
            $gatewayId = (int) $gateway['id'];
            $meta = $configs->listConfigMeta($gatewayId);
            $environments = [];
            foreach ($meta as $row) {
                $env = (string) $row['environment'];
                $environments[$env]['environment'] = $env;
                $environments[$env]['is_active'] = (bool) $row['is_active'];
                $environments[$env]['configured_keys'] ??= [];
                $environments[$env]['values'] ??= [];
                if ((bool) ($row['is_decryptable'] ?? false)) {
                    $key = (string) $row['config_key'];
                    $environments[$env]['configured_keys'][] = $key;
                    // Only a non-secret field's value round-trips back to the
                    // browser (e.g. a bank account number the owner would
                    // otherwise have to retype) — a real credential (API key,
                    // webhook secret) is never re-exposed once saved.
                    if (!self::isSecretConfigKey($key)) {
                        $environments[$env]['values'][$key] = (string) $row['decrypted_value'];
                    }
                }
            }

            $code = (string) $gateway['code'];
            $webhookUrl = null;
            if ($domain !== null) {
                $webhookUrl = "https://{$domain}/api/v1/payments/webhook/{$code}";
            }
            $allowedKeys = $this->allowedConfigKeys(strtoupper($code), $gateway) ?? [];

            $gateways[] = [
                'code' => $code,
                'name' => $gateway['name'],
                'is_plugin' => (bool) ($gateway['is_plugin'] ?? false),
                'allowed_config_keys' => $allowedKeys,
                'requires_configuration' => $allowedKeys !== [],
                'webhook_url' => $webhookUrl,
                'environments' => array_values($environments),
            ];
        }

        $activeGateway = $nodeId !== null ? $this->getNodeRepo()->getActiveGateway($nodeId) : null;

        return ['active_gateway' => $activeGateway, 'gateways' => $gateways];
    }

    /**
     * A config_key counts as a real credential (never shown back to the
     * owner once saved) rather than plain reference data (e.g. a bank
     * account number, safe to redisplay) if its name suggests a secret.
     * Mirrors dashboard-settings.js's identical rule for which fields
     * render as a password input — kept in sync deliberately, since a key
     * classified as "safe" here but masked there (or vice versa) would be
     * a real inconsistency, not just a cosmetic one.
     */
    public static function isSecretConfigKey(string $key): bool
    {
        return str_contains($key, 'secret') || in_array($key, ['api_key', 'server_key'], true);
    }

    /**
     * Sets which payment gateway is the node's default for all checkout flows
     * (orders, CV access, etc.). Pass null to clear the selection.
     *
     * @return array{active_gateway: string|null}
     */
    public function setActiveGateway(int $nodeId, ?string $gatewayCode): array
    {
        if ($gatewayCode !== null) {
            $normalized = strtoupper($gatewayCode);
            $gateway = $this->getGatewayConfigRepository()->findGatewayByCode($normalized);
            if ($gateway === null) {
                throw new ValidationException([['field' => 'gateway', 'reason' => 'unknown_gateway']]);
            }
            $requiredKeys = $this->allowedConfigKeys($normalized, $gateway) ?? [];
            if ($requiredKeys !== []) {
                $configuredKeys = array_keys($this->getGatewayConfigRepository()->getActiveConfig((int) $gateway['id']));
                $missingKeys = array_values(array_diff($requiredKeys, $configuredKeys));
                if ($missingKeys !== []) {
                    throw new ValidationException([[
                        'field' => 'gateway',
                        'reason' => 'incomplete_configuration',
                        'missing_keys' => $missingKeys,
                    ]]);
                }
            }
        }

        $this->getNodeRepo()->setActiveGateway($nodeId, $gatewayCode !== null ? strtoupper($gatewayCode) : null);

        return ['active_gateway' => $gatewayCode !== null ? strtoupper($gatewayCode) : null];
    }

    /**
     * Resolves the gateway code to an adapter instance: the four built-in
     * gateways go through PaymentGatewayFactory as before; any other code
     * must belong to an installed gateway plugin (payment_gateways.is_plugin
     * = 1), whose adapter class is loaded via PaymentGatewayPluginService.
     *
     * @param array<string, mixed> $configuration
     */
    private function resolveGateway(string $gatewayCode, array $configuration): PaymentGatewayInterface
    {
        $normalized = strtoupper(trim($gatewayCode));
        if (in_array($normalized, PaymentGatewayFactory::SUPPORTED_CODES, true)) {
            return PaymentGatewayFactory::create($normalized, $configuration);
        }

        $gateway = $this->getGatewayConfigRepository()->findGatewayByCode($normalized);
        $adapterClass = (bool) ($gateway['is_plugin'] ?? false)
            ? $this->getPluginService()->resolveAdapterClass($normalized)
            : null;

        if ($adapterClass === null) {
            throw UnsupportedProviderException::forCode('payment gateway', $normalized, PaymentGatewayFactory::SUPPORTED_CODES);
        }

        return new $adapterClass($configuration);
    }

    /**
     * The config keys a gateway's adapter reads: the hardcoded map for a
     * built-in code, or the plugin's own declared config_keys for a
     * plugin-discovered one. Null means the code is not recognized at all.
     *
     * @param array<string, mixed> $gateway
     * @return array<int, string>|null
     */
    private function allowedConfigKeys(string $normalizedCode, array $gateway): ?array
    {
        if (array_key_exists($normalizedCode, self::ALLOWED_CONFIG_KEYS)) {
            return self::ALLOWED_CONFIG_KEYS[$normalizedCode];
        }
        if ((bool) ($gateway['is_plugin'] ?? false)) {
            $decoded = json_decode((string) ($gateway['config_keys_json'] ?? '[]'), true);

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        return null;
    }

    private function getPluginService(): PaymentGatewayPluginService
    {
        if ($this->pluginService === null) {
            $this->pluginService = new PaymentGatewayPluginService(
                $this->getGatewayConfigRepository(),
                dirname(__DIR__, 3) . '/gateways',
            );
        }

        return $this->pluginService;
    }

    private function getNodeRepo(): NodeRepository
    {
        if ($this->nodes === null) {
            $this->nodes = new NodeRepository(\App\Core\Database::connection());
        }
        return $this->nodes;
    }

    /**
     * Stores (encrypted) credentials for one gateway/environment and makes
     * that environment active. Rejects unknown gateways/environments and
     * any config key the gateway's adapter does not actually read.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function updateGatewaySettings(string $gatewayCode, string $environment, array $config): array
    {
        $environment = strtoupper($environment);
        if (!in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new ValidationException([['field' => 'environment', 'reason' => 'invalid_value']]);
        }

        $normalizedCode = strtoupper($gatewayCode);
        $configs = $this->getGatewayConfigRepository();
        $gateway = $configs->findGatewayByCode($normalizedCode);
        if ($gateway === null) {
            throw new ValidationException([['field' => 'gateway', 'reason' => 'unknown_gateway']]);
        }
        $allowedKeys = $this->allowedConfigKeys($normalizedCode, $gateway);
        if ($allowedKeys === null) {
            throw new ValidationException([['field' => 'gateway', 'reason' => 'unknown_gateway']]);
        }

        $values = [];
        foreach ($config as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new ValidationException([['field' => "config.{$key}", 'reason' => 'unknown_key']]);
            }
            if (!is_string($value) || $value === '') {
                throw new ValidationException([['field' => "config.{$key}", 'reason' => 'invalid_value']]);
            }
            $values[$key] = $value;
        }
        $missingKeys = array_values(array_diff($allowedKeys, array_keys($values)));
        if ($missingKeys !== []) {
            throw new ValidationException([[
                'field' => 'config',
                'reason' => 'missing_required_keys',
                'missing_keys' => $missingKeys,
            ]]);
        }
        if ($allowedKeys === []) {
            throw new ValidationException([['field' => 'config', 'reason' => 'configuration_not_required']]);
        }

        try {
            $providerCheck = (bool) ($gateway['is_plugin'] ?? false)
                ? 'NOT_VERIFIED_PLUGIN_GATEWAY'
                : ($this->credentialVerifier ??= new PaymentCredentialVerifier())->verify($normalizedCode, $environment, $values);
        } catch (\RuntimeException $exception) {
            throw new ValidationException([[
                'field' => 'config',
                'reason' => 'credential_verification_failed',
                'message' => $exception->getMessage(),
            ]], 'Payment provider rejected the configuration: ' . $exception->getMessage());
        }

        $configs->setConfig((int) $gateway['id'], $environment, $values);

        return [
            'code' => $normalizedCode,
            'environment' => $environment,
            'configured_keys' => array_keys($values),
            'provider_check' => $providerCheck,
        ];
    }

    private function getRepository(): PaymentRepository
    {
        if ($this->payments === null) {
            $this->payments = new PaymentRepository(\App\Core\Database::connection());
        }

        return $this->payments;
    }

    private function getGatewayConfigRepository(): PaymentGatewayConfigRepository
    {
        if ($this->gatewayConfigs === null) {
            $this->gatewayConfigs = new PaymentGatewayConfigRepository(\App\Core\Database::connection());
        }

        return $this->gatewayConfigs;
    }

    private static function normalizeTimestamp(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        $timestamp = strtotime($iso);

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
