<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Contracts\ExternalContentProviderInterface;
use App\Core\Http\HttpClient;
use Closure;

/**
 * "Litescrap" Instagram connector: no OAuth, no Meta App Review. Instead of
 * the official Graph API (which only exposes posts for Business/Creator
 * accounts you administer, gated behind app review), this fetches a public
 * profile's HTML page with a browser-like User-Agent and pulls post nodes
 * out of the JSON blobs Instagram embeds in <script> tags for its own
 * client-side rendering — the same data the page would render, just read
 * before any JS executes.
 *
 * This only ever works for PUBLIC profiles, and Instagram has no supported
 * contract for it: markup changes, rate limiting, or a login wall can break
 * it at any time without notice, and scraping is against Instagram's Terms
 * of Use. Every failure mode below is surfaced as a normal sync `error`
 * (same shape SyncWorker already expects from every other connector) rather
 * than an exception, so a broken Instagram source degrades to "ERROR status
 * with a message" instead of taking down a whole sync run.
 */
final class InstagramConnector implements ExternalContentProviderInterface
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private readonly Closure $requester;

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(private readonly array $configuration = [])
    {
        $requester = $configuration['http_requester'] ?? null;
        $this->requester = $requester instanceof Closure
            ? $requester
            : static fn (string $url, array $headers): array => (new HttpClient())->get($url, $headers, 15, 8 * 1024 * 1024);
    }

    public function getProviderCode(): string
    {
        return 'INSTAGRAM';
    }

    public function authenticate(array $configuration): array
    {
        return ['status' => 'connected', 'provider' => 'INSTAGRAM', 'config' => $configuration];
    }

    public function refreshAuthentication(array $account): array
    {
        return $account;
    }

    public function getProfile(array $account): array
    {
        $username = self::extractUsername((string) ($account['source_url'] ?? '')) ?? 'instagram';

        return ['provider' => 'INSTAGRAM', 'username' => $username, 'display_name' => '@' . $username];
    }

    public function fetchPosts(array $account, ?string $cursor = null): array
    {
        $sourceUrl = (string) ($account['source_url'] ?? ($this->configuration['source_url'] ?? ''));
        $username = self::extractUsername($sourceUrl);
        if ($username === null) {
            return ['items' => [], 'next_cursor' => null, 'error' => 'Instagram profile URL or username is missing or invalid.'];
        }

        $profileUrl = 'https://www.instagram.com/' . $username . '/';

        try {
            $response = ($this->requester)($profileUrl, [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ]);
        } catch (\Throwable $e) {
            return ['items' => [], 'next_cursor' => null, 'error' => $e->getMessage()];
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return ['items' => [], 'next_cursor' => null, 'error' => "Instagram HTTP {$response['status']} — the profile may be private, rate-limited, or blocking automated requests."];
        }

        $html = (string) $response['body'];

        if (self::looksLikeLoginWall($html)) {
            return ['items' => [], 'next_cursor' => null, 'error' => 'Instagram is requiring a login to view this profile; an anonymous light-scrape cannot retrieve posts.'];
        }

        $nodes = self::extractPostNodes($html);
        if ($nodes === []) {
            return ['items' => [], 'next_cursor' => null, 'error' => 'Could not find any posts in the Instagram page — the markup may have changed, or the profile has no public posts.'];
        }

        $items = [];
        $seen = [];
        foreach ($nodes as $node) {
            $shortcode = (string) ($node['shortcode'] ?? '');
            if ($shortcode === '' || isset($seen[$shortcode])) {
                continue;
            }
            $seen[$shortcode] = true;

            $displayUrl = (string) ($node['display_url'] ?? '');
            $isVideo = !empty($node['is_video']);
            $timestamp = $node['taken_at_timestamp'] ?? null;

            $items[] = [
                'external_id' => $shortcode,
                'type' => $displayUrl !== '' ? 'MEDIA' : 'NOTE',
                'text' => self::extractCaption($node),
                'media' => $displayUrl !== '' ? [['type' => $isVideo ? 'VIDEO' : 'IMAGE', 'provider' => 'INSTAGRAM', 'url' => $displayUrl]] : [],
                'canonical_url' => 'https://www.instagram.com/p/' . $shortcode . '/',
                'published_at' => is_numeric($timestamp) ? gmdate('c', (int) $timestamp) : null,
                'author' => ['username' => $username, 'display_name' => $account['account_display_name'] ?? ('@' . $username)],
            ];
        }

        return ['items' => $items, 'next_cursor' => null];
    }

    public function fetchSinglePost(array $account, string $externalPostId): array
    {
        return ['external_id' => $externalPostId, 'source' => 'INSTAGRAM'];
    }

    public function disconnect(array $account): bool
    {
        return true;
    }

    /**
     * Accepts a bare username, an "@handle", or a full profile URL and
     * returns the normalized lowercase username, or null if it isn't a
     * plausible Instagram username / instagram.com profile link.
     */
    public static function extractUsername(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (!in_array($host, ['instagram.com', 'www.instagram.com'], true)) {
                return null;
            }
            $path = trim((string) ($parts['path'] ?? ''), '/');
            $segments = $path === '' ? [] : explode('/', $path);
            $value = (string) ($segments[0] ?? '');
        }

        $value = ltrim($value, '@');

        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._]{0,28}[A-Za-z0-9])?$/', $value) === 1
            ? strtolower($value)
            : null;
    }

    private static function looksLikeLoginWall(string $html): bool
    {
        return stripos($html, 'Log in to see photos and videos') !== false
            || stripos($html, '"require_login":true') !== false
            || stripos($html, '<title>Login') !== false;
    }

    /**
     * Instagram embeds the data it uses to render the page client-side in
     * one or more <script type="application/json"> blobs (plus, on older
     * responses, a `window._sharedData = {...}` assignment). Rather than
     * hard-coding the exact nesting — which Instagram changes without
     * notice — this decodes every blob it can find and walks the resulting
     * structure recursively for any object carrying a `shortcode` key,
     * which is the one field that has stayed stable across every layout
     * Instagram has shipped so far.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function extractPostNodes(string $html): array
    {
        $blobs = [];

        if (preg_match_all('#<script[^>]*type="application/json"[^>]*>(.*?)</script>#is', $html, $matches) !== false) {
            $blobs = $matches[1] ?? [];
        }

        if (preg_match('#window\.__additionalDataLoaded\([^,]+,\s*(\{.*?\})\);\s*</script>#is', $html, $m) === 1) {
            $blobs[] = $m[1];
        }
        if (preg_match('#window\._sharedData\s*=\s*(\{.*?\});</script>#is', $html, $m) === 1) {
            $blobs[] = $m[1];
        }

        $nodes = [];
        foreach ($blobs as $blob) {
            $decoded = json_decode($blob, true);
            if (is_array($decoded)) {
                self::collectPostNodes($decoded, $nodes);
            }
        }

        return $nodes;
    }

    /**
     * @param array<mixed> $data
     * @param array<int, array<string, mixed>> $nodes
     */
    private static function collectPostNodes(array $data, array &$nodes): void
    {
        if (isset($data['shortcode']) && is_string($data['shortcode'])) {
            $nodes[] = $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                self::collectPostNodes($value, $nodes);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function extractCaption(array $node): string
    {
        $edges = $node['edge_media_to_caption']['edges'] ?? null;
        if (is_array($edges) && isset($edges[0]['node']['text']) && is_string($edges[0]['node']['text'])) {
            return $edges[0]['node']['text'];
        }

        return (string) ($node['caption'] ?? '');
    }
}
