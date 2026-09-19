<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Contracts\ExternalContentProviderInterface;
use App\Core\Http\HttpClient;
use RuntimeException;

final class AtomConnector implements ExternalContentProviderInterface
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
        return 'ATOM';
    }

    public function authenticate(array $configuration): array
    {
        return ['status' => 'connected', 'provider' => 'ATOM', 'config' => $configuration];
    }

    public function refreshAuthentication(array $account): array
    {
        return $account;
    }

    public function getProfile(array $account): array
    {
        return [
            'provider' => 'ATOM',
            'username' => $account['source_url'] ?? 'atom',
            'display_name' => 'Atom Feed',
        ];
    }

    public function fetchPosts(array $account, ?string $cursor = null): array
    {
        $sourceUrl = $account['source_url'] ?? ($this->configuration['source_url'] ?? '');
        if ($sourceUrl === '') {
            return ['items' => [], 'next_cursor' => null];
        }

        // Support local file paths (test fixtures) — bypass HttpClient
        $xml = null;
        if (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://')) {
            try {
                $response = $this->http->get($sourceUrl, ['Accept' => 'application/atom+xml, application/xml'], 10);
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
        foreach ($xml->entry ?? [] as $entry) {
            $canonicalUrl = (string) ($entry->link['href'] ?? '');
            $media = $this->describeVideoEmbed($entry, $canonicalUrl);

            $items[] = [
                'source' => 'ATOM',
                'external_id' => (string) ($entry->id ?? uniqid('atom-', true)),
                'author' => ['username' => 'atom', 'display_name' => (string) ($entry->author->name ?? 'Atom Feed')],
                'type' => $media === [] ? 'ARTICLE' : 'MEDIA',
                'text' => (string) ($entry->summary ?? $entry->title ?? ''),
                'media' => $media,
                'canonical_url' => $canonicalUrl,
                'published_at' => (string) ($entry->published ?? gmdate('c')),
            ];
        }

        return ['items' => $items, 'next_cursor' => null];
    }

    /**
     * YouTube channel/playlist feeds (`youtube.com/feeds/videos.xml`) are Atom
     * feeds carrying a `yt:videoId` element and a `media:group` thumbnail.
     * Other Atom feeds fall back to detecting a YouTube link in the entry URL.
     *
     * @return list<array{type: string, provider: string, video_id: string, embed_url: string, thumbnail_url: string}>
     */
    private function describeVideoEmbed(\SimpleXMLElement $entry, string $canonicalUrl): array
    {
        $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');
        $videoId = isset($yt->videoId) ? (string) $yt->videoId : YouTubeEmbedResolver::extractVideoId($canonicalUrl);

        if ($videoId === null) {
            return [];
        }

        $thumbnailUrl = null;
        $mediaGroup = $entry->children('http://search.yahoo.com/mrss/')->group;
        if (isset($mediaGroup->thumbnail)) {
            $attributes = $mediaGroup->thumbnail->attributes();
            $thumbnailUrl = isset($attributes['url']) ? (string) $attributes['url'] : null;
        }

        return [[
            'type' => 'VIDEO',
            'provider' => 'YOUTUBE',
            'video_id' => $videoId,
            'embed_url' => YouTubeEmbedResolver::embedUrl($videoId),
            'thumbnail_url' => $thumbnailUrl ?? YouTubeEmbedResolver::thumbnailUrl($videoId),
        ]];
    }

    public function fetchSinglePost(array $account, string $externalPostId): array
    {
        return ['external_id' => $externalPostId, 'source' => 'ATOM'];
    }

    public function disconnect(array $account): bool
    {
        return true;
    }
}
