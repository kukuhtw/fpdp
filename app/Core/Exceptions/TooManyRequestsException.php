<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class TooManyRequestsException extends HttpException
{
    public function __construct(string $message = 'Too many requests. Please try again later.')
    {
        parent::__construct(429, 'RATE_LIMITED', $message);
    }
}
