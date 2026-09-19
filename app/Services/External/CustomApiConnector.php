<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Contracts\ExternalContentProviderInterface;
use App\Core\Http\HttpClient;
use RuntimeException;

final class CustomApiConnector implements ExternalContentProviderInterface
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        private readonly array $configuration = [],
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    public function getProviderCode(): string
    {
        return 'CUSTOM_API';
    }

    public function authenticate(array $configuration): array
    {
        return ['status' => 'connected', 'provider' => 'CUSTOM_API', 'config' => $configuration];
    }

    public function refreshAuthentication(array $account): array
    {
        return $account;
    }

    public function getProfile(array $account): array
    {
        return [
            'provider' => 'CUSTOM_API',
            'username' => $account['source_url'] ?? 'custom-api',
            'display_name' => 'Custom API',
        ];
    }

    public function fetchPosts(array $account, ?string $cursor = null): array
    {
        $sourceUrl = $account['source_url'] ?? ($this->configuration['source_url'] ?? '');
        if ($sourceUrl === '') {
            return ['items' => [], 'next_cursor' => null];
        }

        try {
            $response = $this->http->get($sourceUrl, ['Accept' => 'application/json'], 15, 2 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return ['items' => [], 'next_cursor' => null, 'error' => $e->getMessage()];
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return ['items' => [], 'next_cursor' => null, 'error' => "HTTP {$response['status']}"];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return ['items' => [], 'next_cursor' => null, 'error' => 'Invalid JSON'];
        }

        $items = [];
        $records = $data['items'] ?? $data['posts'] ?? $data['data'] ?? $data;

        foreach ((array) $records as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = [
                'source' => 'CUSTOM_API',
                'external_id' => (string) ($row['id'] ?? $row['external_id'] ?? uniqid('custom-api-', true)),
                'author' => ['username' => $row['author']['username'] ?? 'custom-api', 'display_name' => $row['author']['display_name'] ?? 'Custom API'],
                'type' => 'ARTICLE',
                'text' => (string) ($row['content'] ?? $row['body'] ?? $row['title'] ?? ''),
                'media' => [],
                'canonical_url' => (string) ($row['canonical_url'] ?? $row['url'] ?? ''),
                'published_at' => (string) ($row['published_at'] ?? $row['created_at'] ?? gmdate('c')),
            ];
        }

        return ['items' => $items, 'next_cursor' => null];
    }

    public function fetchSinglePost(array $account, string $externalPostId): array
    {
        return ['external_id' => $externalPostId, 'source' => 'CUSTOM_API'];
    }

    public function disconnect(array $account): bool
    {
        return true;
    }
}
