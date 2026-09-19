<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Uuid;
use App\Repositories\PaymentRepository;

final class PaymentService
{
    private const TERMINAL_STATUSES = ['PAID', 'FAILED', 'CANCELLED'];

    public function __construct(
        private readonly PaymentGatewayFactory $factory = new PaymentGatewayFactory(),
        private ?PaymentRepository $payments = null,
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
        $gateway = $this->factory::create($gatewayCode, $paymentData['configuration'] ?? []);
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
        return PaymentGatewayFactory::create($gatewayCode, $configuration);
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
        $gateway = PaymentGatewayFactory::create($gatewayCode);
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

    private function getRepository(): PaymentRepository
    {
        if ($this->payments === null) {
            $this->payments = new PaymentRepository(\App\Core\Database::connection());
        }

        return $this->payments;
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
