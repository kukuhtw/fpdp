<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Exceptions\TooManyRequestsException;
use App\Repositories\RateLimitRepository;

final class RateLimiter
{
    public function __construct(private readonly RateLimitRepository $repository)
    {
    }

    /**
     * Counts one hit for ($action, $identifier) within a fixed window of
     * $windowSeconds and throws once $maxAttempts is exceeded within it.
     */
    public function hit(string $action, string $identifier, int $maxAttempts, int $windowSeconds): void
    {
        $windowIndex = intdiv(time(), $windowSeconds);
        $rateKey = sprintf('%s:%s:%d', $action, $identifier, $windowIndex);
        $windowStartedAt = date('Y-m-d H:i:s', $windowIndex * $windowSeconds);

        $attempts = $this->repository->increment($rateKey, $windowStartedAt);

        if ($attempts > $maxAttempts) {
            throw new TooManyRequestsException();
        }
    }
}
