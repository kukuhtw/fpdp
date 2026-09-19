<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

abstract class HttpException extends \RuntimeException
{
    /**
     * @param array<int, array<string, mixed>> $details
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
