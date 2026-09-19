<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Thrown when a factory is asked for an adapter code it does not implement,
 * instead of silently falling back to an unrelated adapter.
 */
final class UnsupportedProviderException extends \InvalidArgumentException
{
    /**
     * @param array<int, string> $supportedCodes
     */
    public static function forCode(string $category, string $code, array $supportedCodes): self
    {
        return new self(sprintf(
            'Unsupported %s code "%s". Supported codes: %s.',
            $category,
            $code,
            implode(', ', $supportedCodes),
        ));
    }
}
