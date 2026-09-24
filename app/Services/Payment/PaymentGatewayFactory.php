<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Exceptions\UnsupportedProviderException;

final class PaymentGatewayFactory
{
    /** Codes this factory instantiates directly. Anything else is resolved via a discovered plugin — see PaymentService::resolveGateway(). */
    public const SUPPORTED_CODES = ['DUMMY', 'PAYWUZ', 'MIDTRANS', 'PAYPAL'];

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
            'PAYPAL' => new PayPalGateway($configuration),
            default => throw UnsupportedProviderException::forCode(
                'payment gateway',
                $normalizedCode,
                self::SUPPORTED_CODES,
            ),
        };
    }
}
