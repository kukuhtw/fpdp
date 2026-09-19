<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ValidationException extends HttpException
{
    /**
     * @param array<int, array<string, mixed>> $details
     */
    public function __construct(array $details, string $message = 'The request is invalid.')
    {
        parent::__construct(422, 'VALIDATION_ERROR', $message, $details);
    }
}
