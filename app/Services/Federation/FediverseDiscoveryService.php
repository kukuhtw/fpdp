<?php

declare(strict_types=1);

namespace App\Services\Federation;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\HttpClient;
use App\Repositories\FollowRepository;
use App\Repositories\RemoteActorRepository;
use App\Services\Security\RateLimiter;
use Throwable;

/**
 * Helps the owner find fediverse accounts to follow from the Federation
 * dashboard, four ways:
 *
 * - lookup(): one account by address or URL, with a profile preview
 *   (counts, recent posts, relationship) before following — any
 *   ActivityPub server.
 * - suggestions(): followers not followed back, and accounts whose posts
 *   already reached this node — local data only, no outbound request.
 * - directory(): a server's public profile directory (Mastodon API
 *   /api/v1/directory).
 * - hashtag(): authors of recent public posts with a hashtag on a server
 *   (Mastodon API /api/v1/timelines/tag).
 *
 * The fediverse has no central search, so the last two only work on
 * Mastodon-compatible servers that expose those public endpoints.
 *
 * Everything returned is safe to render: remote HTML (bios, posts) is
 * reduced to plain text here, and only http(s) URLs are passed through.
 * Following itself stays on the existing send-follow endpoint.
 */
final class FediverseDiscoveryService
{
    private const FETCH_TIMEOUT_SECONDS = 6;
    private const RECENT_POSTS = 3;
    private const PAGE_SIZE = 20;
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct(
        private readonly NodeDiscoveryService $discovery,
        private readonly FollowRepository $follows,
        private readonly RemoteActorRepository $actors,
        private readonly HttpClient $http,
        private readonly ?RateLimiter $rateLimiter = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(int $nodeId, int $profileId, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new ValidationException([['field' => 'q', 'reason' => 'required']], 'Enter an account (e.g. @user@mastodon.social) or a profile URL.');
        }
        $this->throttle($nodeId);

        $actor = $this->discovery->resolveActorByAccountOrUrl($query);
        if ($actor === null) {
            throw new ValidationException([['field' => 'q', 'reason' => 'not_found']], "No fediverse account was found for \"{$query}\".");
        }

        $actorUri = (string) $actor['actor_uri'];
        $host = (string) parse_url($actorUri, PHP_URL_HOST);
        $document = $this->discovery->fetchActivityJson($actorUri, $host, self::FETCH_TIMEOUT_SECONDS) ?? [];

        $outgoing = $this->follows->findByProfileAndTarget($profileId, $actorUri, 'OUTGOING');
        $incoming = $this->follows->findByProfileAndTarget($profileId, $actorUri, 'INCOMING');

        return [
            'actor_uri' => $actorUri,
            'address' => (string) ($actor['federated_address'] ?? $actorUri),
            'display_name' => (string) ($actor['display_name'] ?? ''),
            'avatar_url' => self::safeUrl($actor['avatar_url'] ?? null),
            'profile_url' => self::safeUrl(self::firstUrl($document['url'] ?? null) ?? ($actor['canonical_url'] ?? null)),
            'summary' => self::plainText($document['summary'] ?? null, 600),
            'type' => (string) ($document['type'] ?? 'Person'),
            'locked' => ($document['manuallyApprovesFollowers'] ?? false) === true,
            'domain' => $host,
            'domain_blocked' => (string) ($actor['node_trust_state'] ?? '') === 'BLOCKED',
            'followers_count' => $this->collectionTotal($document['followers'] ?? null, $host),
            'following_count' => $this->collectionTotal($document['following'] ?? null, $host),
            'posts_count' => $this->collectionTotal($document['outbox'] ?? null, $host),
            'recent_posts' => $this->recentPosts($document['outbox'] ?? null, $host),
            'relationship' => [
                'following' => $outgoing !== null ? (string) $outgoing['status'] : 'NONE',
                'followed_by' => $incoming !== null && (string) $incoming['status'] === 'ACCEPTED',
            ],
        ];
    }

    /**
     * @return array{follow_back: array<int, array<string, mixed>>, seen_before: array<int, array<string, mixed>>}
     */
    public function suggestions(int $profileId): array
    {
        $card = static fn (array $row, string $reason): array => [
            'actor_uri' => (string) $row['actor_uri'],
            'address' => (string) ($row['federated_address'] ?? $row['actor_uri']),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'avatar_url' => self::safeUrl($row['avatar_url'] ?? null),
            'profile_url' => self::safeUrl($row['canonical_url'] ?? null),
            'domain' => (string) ($row['node_domain'] ?? parse_url((string) $row['actor_uri'], PHP_URL_HOST)),
            'reason' => $reason,
            'post_count' => isset($row['post_count']) ? (int) $row['post_count'] : null,
        ];

        return [
            'follow_back' => array_map(fn (array $row): array => $card($row, 'follows_you'), $this->follows->findFollowBackCandidates($profileId, self::PAGE_SIZE)),
            'seen_before' => array_map(fn (array $row): array => $card($row, 'posts_seen'), $this->actors->findKnownUnfollowedAuthors($profileId, self::PAGE_SIZE)),
        ];
    }

    /**
     * @return array{domain: string, accounts: array<int, array<string, mixed>>, next_offset: int|null}
     */
    public function directory(int $nodeId, string $domain, int $offset = 0): array
    {
        $domain = self::normalizeDomain($domain);
        $offset = max(0, $offset);
        $this->throttle($nodeId);

        $rows = $this->mastodonApi($domain, '/api/v1/directory?' . http_build_query([
            'local' => 'true',
            'order' => 'active',
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
        ]), 'directory');

        $accounts = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($card = self::mastodonAccountCard($row, $domain)) !== null) {
                $accounts[] = $card;
            }
        }

        return [
            'domain' => $domain,
            'accounts' => $accounts,
            'next_offset' => count($rows) >= self::PAGE_SIZE ? $offset + self::PAGE_SIZE : null,
        ];
    }

    /**
     * @return array{domain: string, tag: string, accounts: array<int, array<string, mixed>>}
     */
    public function hashtag(int $nodeId, string $domain, string $tag): array
    {
        $domain = self::normalizeDomain($domain);
        $tag = ltrim(trim($tag), '#');
        if (preg_match('/^[\p{L}\p{N}_]{1,100}$/u', $tag) !== 1) {
            throw new ValidationException([['field' => 'tag', 'reason' => 'invalid_value']], 'A hashtag may only contain letters, numbers, and underscores.');
        }
        $this->throttle($nodeId);

        $statuses = $this->mastodonApi($domain, '/api/v1/timelines/tag/' . rawurlencode($tag) . '?limit=' . self::PAGE_SIZE, 'hashtag');

        // One card per author, carrying their most recent matching post.
        $accounts = [];
        foreach ($statuses as $status) {
            if (!is_array($status) || !is_array($status['account'] ?? null)) {
                continue;
            }
            $card = self::mastodonAccountCard($status['account'], $domain);
            if ($card === null || isset($accounts[$card['address']])) {
                continue;
            }
            $card['sample_post'] = [
                'content' => self::plainText($status['content'] ?? null, 280),
                'published_at' => is_string($status['created_at'] ?? null) ? $status['created_at'] : null,
                'url' => self::safeUrl($status['url'] ?? null),
            ];
            $accounts[$card['address']] = $card;
        }

        return ['domain' => $domain, 'tag' => $tag, 'accounts' => array_values($accounts)];
    }

    private function throttle(int $nodeId): void
    {
        $this->rateLimiter?->hit('fediverse_discovery', (string) $nodeId, self::RATE_LIMIT_PER_MINUTE, 60);
    }

    /**
     * A collection's `totalItems`, or null when the server hides it (many
     * do, for privacy) or it can't be read.
     */
    private function collectionTotal(mixed $collection, string $host): ?int
    {
        if (is_array($collection)) {
            return isset($collection['totalItems']) ? (int) $collection['totalItems'] : null;
        }
        if (!is_string($collection) || $collection === '') {
            return null;
        }
        $document = $this->discovery->fetchActivityJson($collection, $host, self::FETCH_TIMEOUT_SECONDS);

        return is_array($document) && isset($document['totalItems']) ? (int) $document['totalItems'] : null;
    }

    /**
     * Up to RECENT_POSTS public notes from the outbox's first page. Boosts
     * and activities whose object is only a URI are skipped rather than
     * fetched one by one.
     *
     * @return array<int, array{content: string, published_at: string|null, url: string|null}>
     */
    private function recentPosts(mixed $outboxUrl, string $host): array
    {
        if (!is_string($outboxUrl) || $outboxUrl === '') {
            return [];
        }
        $outbox = $this->discovery->fetchActivityJson($outboxUrl, $host, self::FETCH_TIMEOUT_SECONDS);
        $page = is_array($outbox) ? ($outbox['first'] ?? null) : null;
        if (is_string($page) && $page !== '') {
            $page = $this->discovery->fetchActivityJson($page, $host, self::FETCH_TIMEOUT_SECONDS);
        }
        $items = is_array($page) ? ($page['orderedItems'] ?? $page['items'] ?? []) : [];
        if (!is_array($items)) {
            return [];
        }

        $posts = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== 'Create' || !is_array($item['object'] ?? null)) {
                continue;
            }
            $object = $item['object'];
            if (!in_array($object['type'] ?? '', ['Note', 'Article', 'Page'], true)) {
                continue;
            }
            $posts[] = [
                'content' => self::plainText($object['content'] ?? null, 280),
                'published_at' => is_string($object['published'] ?? null) ? $object['published'] : null,
                'url' => self::safeUrl(self::firstUrl($object['url'] ?? null) ?? ($object['id'] ?? null)),
            ];
            if (count($posts) >= self::RECENT_POSTS) {
                break;
            }
        }

        return $posts;
    }

    /**
     * @return array<int, mixed>
     */
    private function mastodonApi(string $domain, string $pathAndQuery, string $feature): array
    {
        $unavailable = $feature === 'directory'
            ? "{$domain} does not offer a public profile directory (only Mastodon-compatible servers that enable it do)."
            : "{$domain} does not offer public hashtag timelines (only Mastodon-compatible servers that allow it do).";

        try {
            $response = $this->http->get("https://{$domain}{$pathAndQuery}", ['Accept' => 'application/json'], self::FETCH_TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            throw new ValidationException([['field' => 'domain', 'reason' => 'unreachable']], "Could not reach {$domain}: " . $e->getMessage());
        }

        $decoded = json_decode((string) $response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded) || !array_is_list($decoded)) {
            throw new ValidationException([['field' => 'domain', 'reason' => 'not_available']], $unavailable);
        }

        return $decoded;
    }

    /**
     * A Mastodon API account object as a card. `acct` is relative to the
     * server that answered: a bare `user` is local to $domain.
     *
     * @param array<string, mixed> $account
     * @return array<string, mixed>|null
     */
    private static function mastodonAccountCard(array $account, string $domain): ?array
    {
        $acct = (string) ($account['acct'] ?? '');
        if ($acct === '' || preg_match('/^[^@\s]+(@[^@\s]+)?$/', $acct) !== 1) {
            return null;
        }
        $address = '@' . (str_contains($acct, '@') ? $acct : "{$acct}@{$domain}");

        return [
            'actor_uri' => null,
            'address' => $address,
            'display_name' => self::plainText($account['display_name'] ?? null, 120),
            'avatar_url' => self::safeUrl($account['avatar'] ?? null),
            'profile_url' => self::safeUrl($account['url'] ?? null),
            'summary' => self::plainText($account['note'] ?? null, 280),
            'domain' => (string) (explode('@', ltrim($address, '@'), 2)[1] ?? $domain),
            'bot' => ($account['bot'] ?? false) === true,
            'followers_count' => isset($account['followers_count']) ? (int) $account['followers_count'] : null,
            'posts_count' => isset($account['statuses_count']) ? (int) $account['statuses_count'] : null,
        ];
    }

    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
            throw new ValidationException([['field' => 'domain', 'reason' => 'invalid_value']], 'Enter a server domain such as mastodon.social.');
        }

        return $domain;
    }

    /**
     * Remote HTML (bio, post body) reduced to plain text: line breaks kept,
     * tags dropped, entities decoded, whitespace collapsed, length capped.
     */
    private static function plainText(mixed $html, int $maxLength): string
    {
        if (!is_string($html) || $html === '') {
            return '';
        }
        $text = (string) preg_replace('#<br\s*/?>|</p>\s*<p[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $text));

        return mb_strlen($text) > $maxLength ? rtrim(mb_substr($text, 0, $maxLength - 1)) . '…' : $text;
    }

    private static function safeUrl(mixed $url): ?string
    {
        if (!is_string($url) || preg_match('#^https?://[^\s"\'<>]+$#i', $url) !== 1) {
            return null;
        }

        return $url;
    }

    /**
     * ActivityPub `url` may be a string, a Link object, or a list of either.
     */
    private static function firstUrl(mixed $url): ?string
    {
        if (is_string($url)) {
            return $url;
        }
        if (is_array($url)) {
            if (isset($url['href']) && is_string($url['href'])) {
                return $url['href'];
            }
            foreach ($url as $entry) {
                if (($found = self::firstUrl($entry)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
