<?php

declare(strict_types=1);

namespace App\Services\Llm;

/**
 * OpenRouter (openrouter.ai/api/v1/chat/completions) is request/response
 * compatible with the OpenAI Chat Completions API, so this only overrides
 * the name and default base URL.
 */
final class OpenRouterProvider extends OpenAiProvider
{
    private const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';
    private const DEFAULT_EMBEDDING_MODEL = 'openai/text-embedding-3-small';

    public function getName(): string
    {
        return 'OpenRouter';
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->configuration['base_url'] ?? self::DEFAULT_BASE_URL), '/');
    }

    protected function embeddingModel(): string
    {
        return (string) ($this->configuration['embedding_model'] ?? self::DEFAULT_EMBEDDING_MODEL);
    }
}
