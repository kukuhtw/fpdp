<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Contracts\ExternalContentProviderInterface;

final class AtomConnector implements ExternalContentProviderInterface
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(private readonly array $configuration = [])
    {
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

        $xml = @simplexml_load_file($sourceUrl);
        if ($xml === false || $xml === null) {
            return ['items' => [], 'next_cursor' => null];
        }

        $items = [];
        foreach ($xml->entry ?? [] as $entry) {
            $items[] = [
                'source' => 'ATOM',
                'external_id' => (string) ($entry->id ?? uniqid('atom-', true)),
                'author' => ['username' => 'atom', 'display_name' => (string) ($entry->author->name ?? 'Atom Feed')],
                'type' => 'ARTICLE',
                'text' => (string) ($entry->summary ?? $entry->title ?? ''),
                'media' => [],
                'canonical_url' => (string) ($entry->link['href'] ?? ''),
                'published_at' => (string) ($entry->published ?? gmdate('c')),
            ];
        }

        return ['items' => $items, 'next_cursor' => null];
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
