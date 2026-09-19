<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Analytics\AnalyticsService;
use App\Services\Auth\AuthService;
use App\Services\Profile\ProfileService;

final class AnalyticsController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ProfileService $profiles,
        private readonly AnalyticsService $analytics,
    ) {
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
