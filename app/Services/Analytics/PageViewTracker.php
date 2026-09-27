<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Records a visit when a person — not a bot, not the dashboard, not an API
 * call — is served one of the public HTML pages. Called by the front
 * controller after the response has been sent, so tracking can never slow
 * down or break a page.
 *
 * Kept: which page (home, profile, a post, the shop, a product, CV, …), a
 * daily-rotating visitor hash (AnalyticsService), and the referring site's
 * host. Not kept: the IP address, the full referrer URL, or anything that
 * follows a visitor from one day to the next.
 */
final class PageViewTracker
{
    /**
     * Link-preview fetchers, crawlers, monitors, and HTTP libraries — not
     * people. Deliberately not app names like LinkedIn or Telegram: their
     * in-app browsers are people; their preview bots already say "bot".
     */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|scrap|preview|fetch|monitor|uptime|curl|wget|python|http[-._ ]?client|go-http|java\/|okhttp|axios|node-fetch|undici|headless|lighthouse|pagespeed|mastodon|pleroma|akkoma|misskey|friendica|lemmy|peertube|gotosocial|fediverse|activitypub|facebookexternalhit|whatsapp|embedly|vkshare/i';

    /** Public pages with no id in the path. */
    private const STATIC_PAGES = [
        '/' => 'home', '/about-me' => 'about_me', '/coretan' => 'coretan', '/youtube' => 'youtube',
        '/timeline' => 'timeline', '/about' => 'about_fpdp', '/shop' => 'shop', '/cv' => 'cv',
    ];

    /**
     * @param \Closure(): ?int $nodeId the node the visit belongs to (looked up only when a visit is recorded)
     */
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly \Closure $nodeId,
    ) {
    }

    public function track(Request $request, Response $response): void
    {
        if ($request->method !== 'GET' || $response->status !== 200) {
            return;
        }
        if (!str_starts_with(strtolower((string) ($response->headers['Content-Type'] ?? '')), 'text/html')) {
            return; // e.g. /@handle served as ActivityPub JSON to another server
        }
        if (self::isPrefetch($request) || self::isBot($request->header('user-agent'))) {
            return;
        }
        $page = self::classify($request->path);
        if ($page === null) {
            return;
        }
        $nodeId = ($this->nodeId)();
        if ($nodeId === null) {
            return;
        }

        [$eventType, $subjectType, $subjectId] = $page;
        $this->analytics->recordPageView(
            $nodeId,
            $eventType,
            $subjectType,
            $subjectId,
            $request->ipAddress,
            $request->header('user-agent'),
            self::referrerHost($request),
        );
    }

    /**
     * Which public page a path is, or null for anything that isn't one
     * (dashboard, API, assets, documentation, installer, …).
     *
     * @return array{0: string, 1: string, 2: string|null}|null [event type, subject type, subject id]
     */
    public static function classify(string $path): ?array
    {
        $path = '/' . trim($path, '/');
        if (isset(self::STATIC_PAGES[$path])) {
            return ['PAGE_VIEW', self::STATIC_PAGES[$path], null];
        }
        if (preg_match('#^/posts/(\d+)(?:-[^/]*)?$#', $path, $m) === 1) {
            return ['POST_VIEW', 'post', $m[1]];
        }
        if (preg_match('#^/shop/([A-Za-z0-9-]{1,64})$#', $path, $m) === 1) {
            return ['PAGE_VIEW', 'product', $m[1]];
        }
        if (preg_match('#^/@[a-z0-9][a-z0-9-]{0,62}/cv$#i', $path) === 1) {
            return ['PAGE_VIEW', 'cv', null];
        }
        if (preg_match('#^/@[a-z0-9][a-z0-9-]{0,62}$#i', $path) === 1) {
            return ['PROFILE_VIEW', 'profile', null];
        }

        return null;
    }

    public static function isBot(?string $userAgent): bool
    {
        return $userAgent === null || trim($userAgent) === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    /**
     * The referring site's host, or null for a direct visit, a click within
     * this node, or anything that isn't an http(s) page.
     */
    public static function referrerHost(Request $request): ?string
    {
        $referer = (string) ($request->header('referer') ?? '');
        if (preg_match('#^https?://#i', $referer) !== 1) {
            return null;
        }
        $host = self::bareHost((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '' || strlen($host) > 255) {
            return null;
        }
        $ownHosts = array_filter([self::bareHost((string) ($request->header('host') ?? '')), self::bareHost((string) Config::get('NODE_DOMAIN', ''))]);

        return in_array($host, $ownHosts, true) ? null : $host;
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower(explode(':', trim($host), 2)[0]);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function isPrefetch(Request $request): bool
    {
        $purpose = strtolower((string) ($request->header('sec-purpose') ?? $request->header('purpose') ?? ''));

        return str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender');
    }
}
