<?php

declare(strict_types=1);

namespace App\Contracts;

interface LLMProviderInterface
{
    public function getName(): string;

    /**
     * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
     *        `content` is either plain text, or a list of parts for multimodal
     *        input: {type: 'text', text: string} or
     *        {type: 'image', mime_type: string, data_base64: string}.
     * @param array<string, mixed> $options
     * @return array{content: string, tokens_used: int}
     */
    public function complete(array $messages, array $options = []): array;

    /**
     * Used for RAG (embedding a document's generated FAQs for later
     * similarity search). Not every provider has a native embeddings API —
     * a provider that doesn't must throw RuntimeException rather than
     * silently proxy through a chat completion, the same convention
     * PaywuzGateway uses for operations it doesn't support.
     *
     * @return array{vector: array<int, float>, tokens_used: int}
     */
    public function embed(string $text): array;
}
