<?php

declare(strict_types=1);

namespace App\Contracts;

interface PaymentGatewayInterface
{
    public function getName(): string;

    public function createPayment(array $paymentData): array;

    public function getPaymentStatus(string $externalTransactionId): array;

    public function cancelPayment(string $externalTransactionId): array;

    public function refundPayment(string $externalTransactionId, float $amount): array;

    public function verifyWebhook(array $headers, string $payload): bool;

    public function handleWebhook(array $headers, string $payload): array;
}
