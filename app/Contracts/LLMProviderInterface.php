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
}
