<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
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
        $gateway = $this->factory::create($gatewayCode, $configuration);
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

        return PaymentGatewayFactory::create($gatewayCode, $resolved);
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
        $gateway = PaymentGatewayFactory::create($gatewayCode, $configuration);
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
     * Owner-facing list of every supported gateway, whether it has SANDBOX/
     * LIVE credentials configured, and which environment is active. Never
     * includes a decrypted secret.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listGatewaySettings(): array
    {
        $configs = $this->getGatewayConfigRepository();
        $gateways = [];

        foreach ($configs->findAllGateways() as $gateway) {
            $gatewayId = (int) $gateway['id'];
            $meta = $configs->listConfigMeta($gatewayId);
            $environments = [];
            foreach ($meta as $row) {
                $env = (string) $row['environment'];
                $environments[$env]['environment'] = $env;
                $environments[$env]['is_active'] = (bool) $row['is_active'];
                $environments[$env]['configured_keys'][] = (string) $row['config_key'];
            }

            $gateways[] = [
                'code' => $gateway['code'],
                'name' => $gateway['name'],
                'allowed_config_keys' => self::ALLOWED_CONFIG_KEYS[strtoupper((string) $gateway['code'])] ?? [],
                'environments' => array_values($environments),
            ];
        }

        return $gateways;
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
        $allowedKeys = self::ALLOWED_CONFIG_KEYS[$normalizedCode] ?? null;
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
        if ($values === []) {
            throw new ValidationException([['field' => 'config', 'reason' => 'empty_config']]);
        }

        $configs = $this->getGatewayConfigRepository();
        $gateway = $configs->findGatewayByCode($normalizedCode);
        if ($gateway === null) {
            throw new NotFoundException('Gateway not found.');
        }

        $configs->setConfig((int) $gateway['id'], $environment, $values);

        return ['code' => $normalizedCode, 'environment' => $environment, 'configured_keys' => array_keys($values)];
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
