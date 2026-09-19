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
    private const RECORDABLE_TYPES = ['PROFILE_VIEW', 'POST_VIEW', 'OUTBOUND_CLICK', 'SHOP_CONVERSION'];
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

    private function safeRecord(int $nodeId, string $eventType, ?string $subjectType, ?string $subjectPublicId, ?string $ipAddress, ?string $userAgent): void
    {
        if (!in_array($eventType, self::RECORDABLE_TYPES, true)) {
            return;
        }

        try {
            $this->events->record($nodeId, $eventType, $subjectType, $subjectPublicId, self::visitorHash($ipAddress, $userAgent));
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
