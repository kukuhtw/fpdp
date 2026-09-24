<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Contracts\LLMProviderInterface;
use App\Core\Exceptions\UnsupportedProviderException;

final class LLMProviderFactory
{
    public const SUPPORTED_CODES = ['OPENAI', 'ANTHROPIC', 'OPENROUTER'];

    /**
     * @param array<string, mixed> $configuration
     */
    public static function create(string $providerCode, array $configuration = []): LLMProviderInterface
    {
        $normalizedCode = strtoupper(trim($providerCode));

        return match ($normalizedCode) {
            'OPENAI' => new OpenAiProvider($configuration),
            'ANTHROPIC' => new AnthropicProvider($configuration),
            'OPENROUTER' => new OpenRouterProvider($configuration),
            default => throw UnsupportedProviderException::forCode(
                'llm provider',
                $normalizedCode,
                self::SUPPORTED_CODES,
            ),
        };
    }
}
