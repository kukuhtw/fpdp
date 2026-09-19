<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;

final class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayFactory $factory = new PaymentGatewayFactory()
    ) {
    }

    /**
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>
     */
    public function createPayment(string $gatewayCode, array $paymentData): array
    {
        $gateway = $this->factory::create($gatewayCode, $paymentData['configuration'] ?? []);

        return $gateway->createPayment($paymentData);
    }

    public function getGateway(string $gatewayCode, array $configuration = []): PaymentGatewayInterface
    {
        return PaymentGatewayFactory::create($gatewayCode, $configuration);
    }
}
