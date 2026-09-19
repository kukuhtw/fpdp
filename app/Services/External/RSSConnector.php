<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Contracts\ExternalContentProviderInterface;
use App\Core\Http\HttpClient;
use RuntimeException;

final class RSSConnector implements ExternalContentProviderInterface
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

        $xml = null;
        if (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://')) {
            try {
                $response = $this->http->get($sourceUrl, ['Accept' => 'application/rss+xml, application/xml, text/xml'], 10);
            } catch (RuntimeException $e) {
                return ['items' => [], 'next_cursor' => null, 'error' => $e->getMessage()];
            }

            if ($response['status'] < 200 || $response['status'] >= 300) {
                return ['items' => [], 'next_cursor' => null, 'error' => "HTTP {$response['status']}"];
            }

            $xml = simplexml_load_string($response['body']);
        } else {
            $xml = @simplexml_load_file($sourceUrl);
        }

        if ($xml === false || $xml === null) {
            return ['items' => [], 'next_cursor' => null, 'error' => 'Invalid XML'];
        }

        $items = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $canonicalUrl = (string) ($item->link ?? '');
            $embed = YouTubeEmbedResolver::describe($canonicalUrl);
            $media = $embed !== null ? [$embed] : [];

            $items[] = [
                'source' => 'RSS',
                'external_id' => (string) ($item->guid ?? $item->link ?? uniqid('rss-', true)),
                'author' => ['username' => 'rss', 'display_name' => (string) ($xml->channel->title ?? 'RSS Feed')],
                'type' => $media === [] ? 'ARTICLE' : 'MEDIA',
                'text' => (string) ($item->description ?? $item->title ?? ''),
                'media' => $media,
                'canonical_url' => $canonicalUrl,
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
