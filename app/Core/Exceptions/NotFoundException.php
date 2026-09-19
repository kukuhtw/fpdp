<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'The requested resource was not found.')
    {
        parent::__construct(404, 'NOT_FOUND', $message);
    }
}
