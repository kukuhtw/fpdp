<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Contracts\ExternalContentProviderInterface;

final class RSSConnector implements ExternalContentProviderInterface
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(private readonly array $configuration = [])
    {
    }

    public function getProviderCode(): string
    {
        return 'RSS';
    }

    public function authenticate(array $configuration): array
    {
        return ['status' => 'connected', 'provider' => 'RSS', 'config' => $configuration];
    }

    public function refreshAuthentication(array $account): array
    {
        return $account;
    }

    public function getProfile(array $account): array
    {
        return [
            'provider' => 'RSS',
            'username' => $account['source_url'] ?? 'feed',
            'display_name' => 'RSS Feed',
        ];
    }

    public function fetchPosts(array $account, ?string $cursor = null): array
    {
        $sourceUrl = $account['source_url'] ?? ($this->configuration['source_url'] ?? '');

        if ($sourceUrl === '') {
            return ['items' => [], 'next_cursor' => null];
        }

        $xml = @simplexml_load_file($sourceUrl);
        if ($xml === false || $xml === null) {
            return ['items' => [], 'next_cursor' => null];
        }

        $items = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $items[] = [
                'source' => 'RSS',
                'external_id' => (string) ($item->guid ?? $item->link ?? uniqid('rss-', true)),
                'author' => ['username' => 'rss', 'display_name' => (string) ($xml->channel->title ?? 'RSS Feed')],
                'type' => 'ARTICLE',
                'text' => (string) ($item->description ?? $item->title ?? ''),
                'media' => [],
                'canonical_url' => (string) ($item->link ?? ''),
                'published_at' => (string) ($item->pubDate ?? gmdate('c')),
            ];
        }

        return ['items' => $items, 'next_cursor' => null];
    }

    public function fetchSinglePost(array $account, string $externalPostId): array
    {
        return ['external_id' => $externalPostId, 'source' => 'RSS'];
    }

    public function disconnect(array $account): bool
    {
        return true;
    }
}
