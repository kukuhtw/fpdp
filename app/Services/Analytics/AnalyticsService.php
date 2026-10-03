<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Core\Config;
use App\Core\Exceptions\ValidationException;
use App\Repositories\AnalyticsEventRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Lightweight, privacy-conscious visitor analytics: profile views, content
 * views, outbound-link clicks, and shop conversions, aggregated into a 7-day
 * traffic chart and a top-content list for the owner dashboard.
 *
 * No raw IP address is ever persisted. "Unique visitors" is approximated by
 * hashing (IP + user agent) together with the current UTC date using
 * APP_KEY as an HMAC secret — a bare, unsalted hash of an IP is reversible
 * by brute force given how small the IPv4/IPv6-in-practice address space
 * is, so a keyed hash is used instead. Because the date is part of the
 * input, the same visitor hashes differently every day: this lets same-day
 * events be deduplicated into a "unique visitor" count without making it
 * possible to track one visitor across days or correlate them with any
 * other system.
 */
final class AnalyticsService
{
    private const RECORDABLE_TYPES = ['PAGE_VIEW', 'PROFILE_VIEW', 'POST_VIEW', 'OUTBOUND_CLICK', 'SHOP_CONVERSION'];
    /** Date ranges the Analytics page offers. */
    public const REPORT_WINDOWS = [7, 30, 90];
    private const REPORT_LIST_LIMIT = 10;
    /** Human names for PAGE_VIEW subject types, and where each page lives. */
    private const PAGES = [
        'home' => '/', 'about_me' => '/about-me', 'coretan' => '/coretan', 'youtube' => '/youtube',
        'timeline' => '/timeline', 'about_fpdp' => '/about', 'shop' => '/shop',
    ];
    private const MAX_TARGET_URL_LENGTH = 2048;
    private const MAX_USER_AGENT_LENGTH = 200;
    private const SUMMARY_WINDOW_DAYS = 7;
    private const TOP_CONTENT_LIMIT = 5;

    public function __construct(private readonly AnalyticsEventRepository $events)
    {
    }

    /**
     * Best-effort: never lets a tracking failure break the page/response it
     * was attached to. Call from a public read path right after building
     * the response, not before — a broken INSERT must not turn into a 500
     * for a visitor just reading a profile or post.
     */
    /**
     * A visit to a public HTML page (PageViewTracker decides which ones).
     * Best-effort like the other record*() methods.
     */
    public function recordPageView(int $nodeId, string $eventType, string $subjectType, ?string $subjectId, ?string $ipAddress, ?string $userAgent, ?string $referrerHost): void
    {
        $this->safeRecord($nodeId, $eventType, $subjectType, $subjectId, $ipAddress, $userAgent, $referrerHost);
    }

    public function recordProfileView(int $nodeId, ?string $ipAddress, ?string $userAgent): void
    {
        $this->safeRecord($nodeId, 'PROFILE_VIEW', 'profile', null, $ipAddress, $userAgent);
    }

    public function recordPostView(int $nodeId, string $postPublicId, ?string $ipAddress, ?string $userAgent): void
    {
        $this->safeRecord($nodeId, 'POST_VIEW', 'post', $postPublicId, $ipAddress, $userAgent);
    }

    public function recordShopConversion(int $nodeId, string $orderPublicId): void
    {
        $this->safeRecord($nodeId, 'SHOP_CONVERSION', 'order', $orderPublicId, null, null);
    }

    /**
     * Outbound-link clicks happen entirely client-side (the visitor leaves
     * the page), so unlike views this cannot be observed server-side — the
     * frontend must report it explicitly. Validated and allowed to throw
     * (unlike the record*() methods above) since this IS the endpoint's
     * entire purpose, not a side-effect of one.
     */
    public function trackOutboundClick(int $nodeId, string $targetUrl, ?string $ipAddress, ?string $userAgent): void
    {
        $scheme = strtolower((string) parse_url($targetUrl, PHP_URL_SCHEME));
        if (
            $targetUrl === ''
            || strlen($targetUrl) > self::MAX_TARGET_URL_LENGTH
            || filter_var($targetUrl, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)
        ) {
            // Restricted to http(s) so a javascript:/data: URI can never be
            // stored here — FILTER_VALIDATE_URL alone accepts those too.
            throw new ValidationException([['field' => 'target_url', 'reason' => 'invalid_value']]);
        }

        $this->events->record($nodeId, 'OUTBOUND_CLICK', 'link', substr($targetUrl, 0, 500), self::visitorHash($ipAddress, $userAgent));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(int $nodeId): array
    {
        // DateTimeImmutable with an explicit UTC zone throughout — never
        // strtotime() for these date-only calculations, since strtotime()
        // resolves a bare "Y-m-d" string in PHP's default timezone (e.g.
        // Asia/Jakarta), which silently shifts the day relative to the
        // gmdate('Y-m-d') UTC value that occurred_on is actually stored in.
        $sinceDate = (new DateTimeImmutable('today', new DateTimeZone('UTC')))
            ->modify('-' . (self::SUMMARY_WINDOW_DAYS - 1) . ' days')
            ->format('Y-m-d');
        $daily = $this->fillDailyTraffic($this->events->dailyTraffic($nodeId, $sinceDate), $sinceDate);

        return [
            'window_days' => self::SUMMARY_WINDOW_DAYS,
            'unique_visitors' => array_sum(array_column($daily, 'unique_visitors')),
            'profile_views' => $this->events->countByType($nodeId, 'PROFILE_VIEW', $sinceDate),
            'content_views' => $this->events->countByType($nodeId, 'POST_VIEW', $sinceDate),
            'outbound_clicks' => $this->events->countByType($nodeId, 'OUTBOUND_CLICK', $sinceDate),
            'shop_conversions' => $this->events->countByType($nodeId, 'SHOP_CONVERSION', $sinceDate),
            'daily_traffic' => $daily,
            'top_content' => $this->events->topContent($nodeId, self::TOP_CONTENT_LIMIT),
        ];
    }

    /**
     * The Analytics page: totals for the last $days days and for the $days
     * before them (for the change), a day-by-day series, top pages, where
     * visitors came from, and which outbound links they followed.
     *
     * "Visits" are unique visitors per day, added up over the range: the
     * visitor hash rotates daily by design (see the class comment), so the
     * same person on two days counts twice — it cannot be de-duplicated
     * across days without tracking people, which this deliberately doesn't.
     *
     * @return array<string, mixed>
     */
    public function getReport(int $nodeId, int $days): array
    {
        if (!in_array($days, self::REPORT_WINDOWS, true)) {
            throw new ValidationException([['field' => 'days', 'reason' => 'invalid_value']], 'days must be one of ' . implode(', ', self::REPORT_WINDOWS) . '.');
        }

        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable('today', $utc);
        $since = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $until = $today->format('Y-m-d');
        $previousSince = $today->modify('-' . (2 * $days - 1) . ' days')->format('Y-m-d');
        $previousUntil = $today->modify("-{$days} days")->format('Y-m-d');

        $daily = $this->fillDailyTraffic($this->events->dailyTraffic($nodeId, $since, $until), $since);
        $previousDaily = $this->events->dailyTraffic($nodeId, $previousSince, $previousUntil);

        return [
            'window_days' => $days,
            'since' => $since,
            'until' => $until,
            'totals' => $this->totals($nodeId, $daily, $since, $until),
            'previous' => $this->totals($nodeId, $previousDaily, $previousSince, $previousUntil),
            'daily' => array_map(static fn (array $day): array => [
                'date' => $day['date'],
                'visits' => $day['unique_visitors'],
                'page_views' => $day['views'],
            ], $daily),
            'top_pages' => array_map([self::class, 'presentPage'], self::section('top_pages', fn (): array => $this->events->topPages($nodeId, $since, $until, self::REPORT_LIST_LIMIT))),
            'referrers' => array_map(static fn (array $row): array => [
                'host' => (string) $row['referrer_host'],
                'page_views' => (int) $row['views'],
                'visits' => (int) $row['visits'],
            ], self::section('referrers', fn (): array => $this->events->topReferrers($nodeId, $since, $until, self::REPORT_LIST_LIMIT))),
            'outbound' => array_map(static fn (array $row): array => [
                'url' => (string) $row['url'],
                'host' => (string) parse_url((string) $row['url'], PHP_URL_HOST),
                'clicks' => (int) $row['clicks'],
            ], self::section('outbound', fn (): array => $this->events->topOutbound($nodeId, $since, $until, self::REPORT_LIST_LIMIT))),
        ];
    }

    /**
     * One list on the Analytics page. A failing query (e.g. a migration not
     * yet applied on the server) empties that list and is logged, instead of
     * turning the whole page into a 500.
     *
     * @param callable(): array<int, array<string, mixed>> $query
     * @return array<int, array<string, mixed>>
     */
    private static function section(string $name, callable $query): array
    {
        try {
            return $query();
        } catch (Throwable $e) {
            error_log(sprintf('[analytics] report section "%s" failed: %s', $name, $e->getMessage()));

            return [];
        }
    }

    /**
     * Retention: deletes analytics events older than $keepDays days.
     */
    public function pruneOlderThan(int $keepDays): int
    {
        $before = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->modify("-{$keepDays} days")->format('Y-m-d');

        return $this->events->deleteOlderThan($before);
    }

    /**
     * @param array<int, array<string, mixed>> $daily rows with unique_visitors and views
     * @return array{visits: int, page_views: int, outbound_clicks: int, shop_conversions: int}
     */
    private function totals(int $nodeId, array $daily, string $since, string $until): array
    {
        return [
            'visits' => array_sum(array_map(static fn (array $d): int => (int) $d['unique_visitors'], $daily)),
            'page_views' => array_sum(array_map(static fn (array $d): int => (int) $d['views'], $daily)),
            'outbound_clicks' => $this->events->countByType($nodeId, 'OUTBOUND_CLICK', $since, $until),
            'shop_conversions' => $this->events->countByType($nodeId, 'SHOP_CONVERSION', $since, $until),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{kind: string, id: string|null, title: string|null, path: string|null, page_views: int, visits: int}
     */
    private static function presentPage(array $row): array
    {
        $kind = match ((string) $row['event_type']) {
            'PROFILE_VIEW' => 'profile',
            'POST_VIEW' => 'post',
            default => (string) ($row['subject_type'] ?? 'page'),
        };
        $id = $row['subject_public_id'] !== null ? (string) $row['subject_public_id'] : null;
        [$title, $path] = match ($kind) {
            'post' => [$row['post_title'] ?? null, $row['post_id'] !== null ? '/posts/' . (int) $row['post_id'] : null],
            'product' => [$row['product_title'] ?? null, $id !== null ? '/shop/' . rawurlencode($id) : null],
            default => [null, self::PAGES[$kind] ?? null],
        };

        return [
            'kind' => $kind,
            'id' => $id,
            'title' => $title !== null ? (string) $title : null,
            'path' => $path,
            'page_views' => (int) $row['views'],
            'visits' => (int) $row['visits'],
        ];
    }

    private function safeRecord(int $nodeId, string $eventType, ?string $subjectType, ?string $subjectPublicId, ?string $ipAddress, ?string $userAgent, ?string $referrerHost = null): void
    {
        if (!in_array($eventType, self::RECORDABLE_TYPES, true)) {
            return;
        }

        try {
            $this->events->record($nodeId, $eventType, $subjectType, $subjectPublicId, self::visitorHash($ipAddress, $userAgent), $referrerHost);
        } catch (Throwable) {
            // Tracking is a side-effect of a read; a DB hiccup here must
            // never turn a visitor's page view into a 500.
        }
    }

    /**
     * @param array<int, array{occurred_on: string, unique_visitors: int, views: int}> $rows
     * @return array<int, array{date: string, unique_visitors: int, views: int}>
     */
    private function fillDailyTraffic(array $rows, string $sinceDate): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['occurred_on']] = ['unique_visitors' => (int) $row['unique_visitors'], 'views' => (int) $row['views']];
        }

        $filled = [];
        $utc = new DateTimeZone('UTC');
        $cursor = new DateTimeImmutable($sinceDate, $utc);
        $today = new DateTimeImmutable('today', $utc);
        for (; $cursor <= $today; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $filled[] = [
                'date' => $date,
                'unique_visitors' => $byDate[$date]['unique_visitors'] ?? 0,
                'views' => $byDate[$date]['views'] ?? 0,
            ];
        }

        return $filled;
    }

    private static function visitorHash(?string $ipAddress, ?string $userAgent): ?string
    {
        if ($ipAddress === null || $ipAddress === '') {
            return null;
        }
        $secret = Config::get('APP_KEY', '');
        if ($secret === null || $secret === '') {
            // No secret to key the hash with: skip rather than store an
            // unsalted, brute-forceable hash of the visitor's IP.
            return null;
        }

        $userAgentPart = substr((string) $userAgent, 0, self::MAX_USER_AGENT_LENGTH);

        return hash_hmac('sha256', gmdate('Y-m-d') . '|' . $ipAddress . '|' . $userAgentPart, $secret);
    }
}
