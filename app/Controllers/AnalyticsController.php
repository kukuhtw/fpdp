<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Analytics\AnalyticsService;
use App\Services\Auth\AuthService;
use App\Repositories\NodeRepository;
use App\Services\Profile\ProfileService;
use App\Services\Security\RateLimiter;

final class AnalyticsController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ProfileService $profiles,
        private readonly AnalyticsService $analytics,
        private readonly ?RateLimiter $rateLimiter = null,
        private readonly ?NodeRepository $nodes = null,
    ) {
    }

    /**
     * GET /api/v1/me/analytics?days=7|30|90
     *
     * Owner-only: the Analytics page — totals with the previous period,
     * daily series, top pages, referrers, outbound links.
     */
    public function report(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->analytics->getReport((int) $context['node']['id'], (int) ($request->query['days'] ?? 30)));
    }

    /**
     * POST /api/v1/track/outbound-click — {"target_url": "https://…"}
     *
     * Public: sent by the site's pages (navigator.sendBeacon) when a visitor
     * follows a link to another site. Rate-limited per IP so it can't be
     * used to flood the event log.
     */
    public function trackOutboundClickForNode(Request $request): Response
    {
        $this->throttleClicks($request);
        $node = $this->nodes?->findFirst();
        if ($node === null) {
            throw new \App\Core\Exceptions\NotFoundException('No node configured.');
        }
        $input = $request->json() ?? [];
        $this->analytics->trackOutboundClick((int) $node['id'], (string) ($input['target_url'] ?? ''), $request->ipAddress, $request->header('user-agent'));

        return JsonEnvelope::success(['recorded' => true], 202);
    }

    private function throttleClicks(Request $request): void
    {
        $this->rateLimiter?->hit('outbound_click', $request->ipAddress, 60, 600);
    }

    /**
     * GET /api/v1/me/dashboard/analytics
     *
     * Owner-only: 7-day unique visitors/content views, outbound clicks,
     * shop conversions, a day-by-day traffic chart, and top content.
     */
    public function summary(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->analytics->getSummary((int) $context['node']['id']));
    }

    /**
     * POST /api/v1/profiles/{handle}/track/outbound-click
     *
     * Public, unauthenticated: outbound-link clicks happen client-side (the
     * visitor leaves the page), so the frontend reports them explicitly —
     * unlike profile/post views, which the server observes on its own.
     *
     * @param array<string, string> $params
     */
    public function trackOutboundClick(Request $request, array $params): Response
    {
        $this->throttleClicks($request);
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $input = $request->json() ?? [];

        $this->analytics->trackOutboundClick(
            (int) $profile['node_id'],
            (string) ($input['target_url'] ?? ''),
            $request->ipAddress,
            $request->header('user-agent'),
        );

        return JsonEnvelope::success(['recorded' => true], 202);
    }
}
