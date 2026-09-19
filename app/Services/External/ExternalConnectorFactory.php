<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Contracts\ExternalContentProviderInterface;

final class ExternalConnectorFactory
{
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
            default => new RSSConnector($configuration),
        };
    }
}
