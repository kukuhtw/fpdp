<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Exceptions\UnsupportedProviderException;

final class PaymentGatewayFactory
{
    private const SUPPORTED_CODES = ['DUMMY', 'PAYWUZ', 'MIDTRANS'];

    /**
     * @param array<string, mixed> $configuration
     */
    public static function create(string $gatewayCode, array $configuration = []): PaymentGatewayInterface
    {
        $normalizedCode = strtoupper(trim($gatewayCode));

        return match ($normalizedCode) {
            'DUMMY' => new DummyPaymentGateway($configuration),
            'PAYWUZ' => new PaywuzGateway($configuration),
            'MIDTRANS' => new MidtransGateway($configuration),
            default => throw UnsupportedProviderException::forCode(
                'payment gateway',
                $normalizedCode,
                self::SUPPORTED_CODES,
            ),
        };
    }
}
