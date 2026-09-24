<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Contracts\LLMProviderInterface;
use App\Core\Http\HttpClient;
use Closure;
use RuntimeException;

/**
 * Anthropic Messages API adapter (api.anthropic.com/v1/messages).
 */
final class AnthropicProvider implements LLMProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 1024;

    private readonly Closure $httpRequester;

    /**
     * @param array<string, mixed> $configuration Recognized keys: api_key,
     *        model, base_url, http_requester (test seam, see
     *        OpenAiProvider's constructor doc).
     */
    public function __construct(private readonly array $configuration = [])
    {
        $requester = $configuration['http_requester'] ?? null;
        $this->httpRequester = $requester instanceof Closure
            ? $requester
            : static function (string $method, string $url, array $headers, ?string $body): array {
                return (new HttpClient())->request($method, $url, $headers, $body);
            };
    }

    public function getName(): string
    {
        return 'Anthropic';
    }

    /**
     * Anthropic has no native embeddings API (they point integrators at
     * Voyage AI instead) — fail closed rather than silently proxy through
     * something unexpected, same convention as PaywuzGateway's unsupported
     * operations.
     */
    public function embed(string $text): array
    {
        throw new RuntimeException('Anthropic has no native embeddings API. Configure OpenAI or OpenRouter as the LLM provider to use RAG features.');
    }

    public function complete(array $messages, array $options = []): array
    {
        $payload = [
            'model' => (string) ($options['model'] ?? $this->configuration['model'] ?? ''),
            'max_tokens' => (int) ($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
            'messages' => array_map([self::class, 'normalizeMessage'], $messages),
        ];

        $response = ($this->httpRequester)('POST', $this->baseUrl() . '/messages', [
            'x-api-key' => $this->apiKey(),
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($payload));

        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new RuntimeException('Anthropic completion failed: ' . ($message ?? "HTTP {$status}"));
        }

        $content = '';
        foreach ((array) ($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $content .= (string) $block['text'];
            }
        }
        $tokensUsed = (int) ($decoded['usage']['input_tokens'] ?? 0) + (int) ($decoded['usage']['output_tokens'] ?? 0);

        return ['content' => $content, 'tokens_used' => $tokensUsed];
    }

    /**
     * @param array{role: string, content: string|array<int, array<string, mixed>>} $message
     * @return array<string, mixed>
     */
    private static function normalizeMessage(array $message): array
    {
        $content = $message['content'];
        if (is_string($content)) {
            return ['role' => $message['role'], 'content' => $content];
        }

        $blocks = [];
        foreach ($content as $part) {
            $blocks[] = match ($part['type'] ?? null) {
                'text' => ['type' => 'text', 'text' => (string) $part['text']],
                'image' => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => (string) $part['mime_type'],
                        'data' => (string) $part['data_base64'],
                    ],
                ],
                default => throw new RuntimeException('Unsupported message content part type.'),
            };
        }

        return ['role' => $message['role'], 'content' => $blocks];
    }

    private function apiKey(): string
    {
        $key = (string) ($this->configuration['api_key'] ?? '');
        if ($key === '') {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        return $key;
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->configuration['base_url'] ?? self::DEFAULT_BASE_URL), '/');
    }
}
