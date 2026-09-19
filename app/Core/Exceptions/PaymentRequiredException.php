<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class PaymentRequiredException extends HttpException
{
    public function __construct(string $message = 'Payment is required to access this resource.')
    {
        parent::__construct(402, 'PAYMENT_REQUIRED', $message);
    }
}
