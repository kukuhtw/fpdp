<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Contracts\ExternalContentProviderInterface;
use App\Core\Exceptions\UnsupportedProviderException;

final class ExternalConnectorFactory
{
    private const SUPPORTED_CODES = ['RSS', 'ATOM', 'CUSTOM_API'];

    /**
     * @param array<string, mixed> $configuration
     */
    public static function create(string $providerCode, array $configuration = []): ExternalContentProviderInterface
    {
        $normalized = strtoupper(trim($providerCode));

        return match ($normalized) {
            'RSS' => new RSSConnector($configuration),
            'ATOM' => new AtomConnector($configuration),
            'CUSTOM_API' => new CustomApiConnector($configuration),
            default => throw UnsupportedProviderException::forCode(
                'external content provider',
                $normalized,
                self::SUPPORTED_CODES,
            ),
        };
    }
}
