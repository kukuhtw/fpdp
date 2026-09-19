<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class ConflictException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(409, 'CONFLICT', $message);
    }
}
