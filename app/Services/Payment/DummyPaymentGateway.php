<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;

final class DummyPaymentGateway implements PaymentGatewayInterface
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(private readonly array $configuration = [])
    {
    }

    public function getName(): string
    {
        return 'Dummy';
    }

    public function createPayment(array $paymentData): array
    {
        $amount = (float) ($paymentData['amount'] ?? 0);
        $currency = strtoupper((string) ($paymentData['currency'] ?? 'IDR'));
        $orderId = (string) ($paymentData['order_id'] ?? 'ORD-' . bin2hex(random_bytes(4)));

        return [
            'gateway' => 'DUMMY',
            'status' => 'PENDING',
            'payment_id' => 'PAY-' . strtoupper(bin2hex(random_bytes(6))),
            'external_transaction_id' => 'DUM-' . strtoupper(bin2hex(random_bytes(8))),
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => 'https://example.test/payments/' . $orderId,
            'payment_method' => $paymentData['payment_method'] ?? 'BANK_TRANSFER',
            'expired_at' => gmdate('c', time() + 3600),
        ];
    }

    public function getPaymentStatus(string $externalTransactionId): array
    {
        return [
            'gateway' => 'DUMMY',
            'external_transaction_id' => $externalTransactionId,
            'status' => 'PENDING',
            'amount' => 0,
            'currency' => 'IDR',
        ];
    }

    public function cancelPayment(string $externalTransactionId): array
    {
        return [
            'gateway' => 'DUMMY',
            'external_transaction_id' => $externalTransactionId,
            'status' => 'CANCELLED',
        ];
    }

    public function refundPayment(string $externalTransactionId, float $amount): array
    {
        return [
            'gateway' => 'DUMMY',
            'external_transaction_id' => $externalTransactionId,
            'status' => 'REFUNDED',
            'amount' => $amount,
        ];
    }

    public function verifyWebhook(array $headers, string $payload): bool
    {
        return true;
    }

    public function handleWebhook(array $headers, string $payload): array
    {
        return [
            'event_id' => 'evt-' . bin2hex(random_bytes(6)),
            'gateway' => 'DUMMY',
            'status' => 'PAID',
            'event_type' => 'PAYMENT_PAID',
            'amount' => 0,
            'currency' => 'IDR',
            'occurred_at' => gmdate('c'),
        ];
    }
}
