<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Contracts\LLMProviderInterface;
use App\Core\Http\HttpClient;
use Closure;
use RuntimeException;

/**
 * OpenAI Chat Completions API adapter (api.openai.com/v1/chat/completions).
 * Also reused, unchanged, by OpenRouterProvider, whose API is
 * request/response-compatible with OpenAI's.
 */
class OpenAiProvider implements LLMProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    private const DEFAULT_EMBEDDING_MODEL = 'text-embedding-3-small';

    private readonly Closure $httpRequester;

    /**
     * @param array<string, mixed> $configuration Recognized keys: api_key,
     *        model, base_url (override, used by OpenRouterProvider),
     *        http_requester (test seam: a closure(string $method, string
     *        $url, array $headers, ?string $body): array{status:int,
     *        body:string}, defaults to a real HttpClient call).
     */
    public function __construct(protected readonly array $configuration = [])
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
        return 'OpenAI';
    }

    public function complete(array $messages, array $options = []): array
    {
        $payload = [
            'model' => (string) ($options['model'] ?? $this->configuration['model'] ?? ''),
            'messages' => array_map([self::class, 'normalizeMessage'], $messages),
        ];
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        $response = ($this->httpRequester)('POST', $this->baseUrl() . '/chat/completions', [
            'Authorization' => 'Bearer ' . $this->apiKey(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($payload));

        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new RuntimeException($this->getName() . ' completion failed: ' . ($message ?? "HTTP {$status}"));
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        $tokensUsed = (int) ($decoded['usage']['total_tokens'] ?? 0);

        return ['content' => $content, 'tokens_used' => $tokensUsed];
    }

    public function embed(string $text): array
    {
        $payload = ['model' => $this->embeddingModel(), 'input' => $text];

        $response = ($this->httpRequester)('POST', $this->baseUrl() . '/embeddings', [
            'Authorization' => 'Bearer ' . $this->apiKey(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], json_encode($payload));

        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new RuntimeException($this->getName() . ' embedding failed: ' . ($message ?? "HTTP {$status}"));
        }

        $vector = $decoded['data'][0]['embedding'] ?? null;
        if (!is_array($vector)) {
            throw new RuntimeException($this->getName() . ' embedding response did not include a vector.');
        }

        return ['vector' => array_map('floatval', $vector), 'tokens_used' => (int) ($decoded['usage']['total_tokens'] ?? 0)];
    }

    protected function embeddingModel(): string
    {
        return (string) ($this->configuration['embedding_model'] ?? self::DEFAULT_EMBEDDING_MODEL);
    }

    /**
     * @param array{role: string, content: string|array<int, array<string, mixed>>} $message
     * @return array<string, mixed>
     */
    protected static function normalizeMessage(array $message): array
    {
        $content = $message['content'];
        if (is_string($content)) {
            return ['role' => $message['role'], 'content' => $content];
        }

        $parts = [];
        foreach ($content as $part) {
            $parts[] = match ($part['type'] ?? null) {
                'text' => ['type' => 'text', 'text' => (string) $part['text']],
                'image' => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:' . $part['mime_type'] . ';base64,' . $part['data_base64']],
                ],
                default => throw new RuntimeException('Unsupported message content part type.'),
            };
        }

        return ['role' => $message['role'], 'content' => $parts];
    }

    protected function apiKey(): string
    {
        $key = (string) ($this->configuration['api_key'] ?? '');
        if ($key === '') {
            throw new RuntimeException($this->getName() . ' API key is not configured.');
        }

        return $key;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->configuration['base_url'] ?? self::DEFAULT_BASE_URL), '/');
    }
}
