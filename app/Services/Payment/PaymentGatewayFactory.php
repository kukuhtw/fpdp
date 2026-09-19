<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;

final class PaymentGatewayFactory
{
    /**
     * @param array<string, mixed> $configuration
     */
    public static function create(string $gatewayCode, array $configuration = []): PaymentGatewayInterface
    {
        $normalizedCode = strtoupper(trim($gatewayCode));

        return match ($normalizedCode) {
            'MIDTRANS' => new MidtransPaymentGateway($configuration),
            'XENDIT' => new XenditPaymentGateway($configuration),
            'DOKU' => new DokuPaymentGateway($configuration),
            'IPAYMU' => new IPaymuPaymentGateway($configuration),
            'NICEPAY' => new NicepayPaymentGateway($configuration),
            'PAYWUZ' => new PaywuzPaymentGateway($configuration),
            'STRIPE' => new StripePaymentGateway($configuration),
            'PAYPAL' => new PaypalPaymentGateway($configuration),
            'CUSTOM' => new CustomPaymentGateway($configuration),
            'DUMMY' => new DummyPaymentGateway($configuration),
            default => new DummyPaymentGateway($configuration),
        };
    }
}
