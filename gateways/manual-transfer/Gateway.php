<?php

declare(strict_types=1);

namespace FpdpGatewayPlugins\ManualTransfer;

use App\Contracts\PaymentGatewayInterface;

/**
 * Example payment gateway plugin: manual bank transfer.
 *
 * createPayment() never charges anything automatically — it returns a
 * PENDING payment plus the configured bank details as instructions. There
 * is no bank-signed webhook for a manual transfer, so confirmation is a
 * deliberate action: the owner (or a small script they run) POSTs
 * {"order_id": "...", "status": "PAID"} to
 * /api/v1/payments/webhook/MANUAL_TRANSFER with an X-Webhook-Secret header
 * matching the `webhook_secret` configured for this gateway.
 *
 * This file doubles as a template for a real plugin: implement
 * App\Contracts\PaymentGatewayInterface, read whatever config_keys your
 * gateway.json declares from $this->configuration, and call your
 * provider's real API from createPayment()/verifyWebhook()/handleWebhook().
 * See documentation/PAYMENT-GATEWAY-PLUGIN-GUIDE.id.md.
 */
final class Gateway implements PaymentGatewayInterface
{
    private const CODE = 'MANUAL_TRANSFER';
    private const TERMINAL_STATUSES = ['PAID', 'FAILED', 'CANCELLED'];

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(private readonly array $configuration = [])
    {
    }

    public function getName(): string
    {
        return 'Manual Bank Transfer';
    }

    public function createPayment(array $paymentData): array
    {
        $amount = (float) ($paymentData['amount'] ?? 0);
        $currency = strtoupper((string) ($paymentData['currency'] ?? 'IDR'));
        $orderId = (string) ($paymentData['order_id'] ?? 'ORD-' . bin2hex(random_bytes(4)));

        $instructions = sprintf(
            'Transfer %s %s to %s account %s (a.n. %s), then wait for the owner to confirm your payment.',
            $currency,
            number_format($amount, 2, '.', ''),
            (string) ($this->configuration['bank_name'] ?? 'the configured bank'),
            (string) ($this->configuration['account_number'] ?? ''),
            (string) ($this->configuration['account_holder'] ?? ''),
        );

        return [
            'gateway' => self::CODE,
            'status' => 'PENDING',
            'payment_id' => 'MTR-' . strtoupper(bin2hex(random_bytes(6))),
            'external_transaction_id' => null,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => null,
            'payment_method' => 'BANK_TRANSFER',
            'instructions' => $instructions,
            'expired_at' => gmdate('c', time() + 86400),
        ];
    }

    public function getPaymentStatus(string $externalTransactionId): array
    {
        return [
            'gateway' => self::CODE,
            'external_transaction_id' => $externalTransactionId,
            'status' => 'PENDING',
            'amount' => 0,
            'currency' => 'IDR',
        ];
    }

    public function cancelPayment(string $externalTransactionId): array
    {
        return [
            'gateway' => self::CODE,
            'external_transaction_id' => $externalTransactionId,
            'status' => 'CANCELLED',
        ];
    }

    public function refundPayment(string $externalTransactionId, float $amount): array
    {
        return [
            'gateway' => self::CODE,
            'external_transaction_id' => $externalTransactionId,
            'status' => 'REFUNDED',
            'amount' => $amount,
        ];
    }

    /**
     * There is no bank-signed webhook to verify — confirmation is a manual
     * action by the owner, authenticated by the shared `webhook_secret`
     * they configured, sent back as the X-Webhook-Secret header.
     */
    public function verifyWebhook(array $headers, string $payload): bool
    {
        $expected = (string) ($this->configuration['webhook_secret'] ?? '');
        if ($expected === '') {
            return false;
        }
        $provided = (string) ($headers['x-webhook-secret'] ?? '');

        return $provided !== '' && hash_equals($expected, $provided);
    }

    public function handleWebhook(array $headers, string $payload): array
    {
        $data = json_decode($payload, true);
        $status = is_array($data) ? strtoupper((string) ($data['status'] ?? '')) : '';

        return [
            'event_id' => 'evt-' . bin2hex(random_bytes(6)),
            'gateway' => self::CODE,
            'order_id' => is_array($data) ? (string) ($data['order_id'] ?? '') : '',
            'status' => in_array($status, self::TERMINAL_STATUSES, true) ? $status : 'PENDING',
            'event_type' => 'PAYMENT_CONFIRMED_MANUALLY',
            'amount' => is_array($data) ? (float) ($data['amount'] ?? 0) : 0,
            'currency' => 'IDR',
            'occurred_at' => gmdate('c'),
        ];
    }
}
