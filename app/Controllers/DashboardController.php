<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Dashboard\DashboardService;

final class DashboardController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly DashboardService $dashboard,
    ) {
    }

    /**
     * GET /api/v1/me/dashboard/overview
     *
     * Owner-only landing page after login: content/commerce/federation
     * counts, recent activity, and node status.
     */
    public function overview(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->dashboard->getOverview(
            (int) $context['user']['id'],
            (int) $context['profile']['id'],
            (int) $context['node']['id'],
        ));
    }
}
