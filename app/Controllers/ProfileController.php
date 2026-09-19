<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\ResourcePresenter;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Profile\ProfileService;

final class ProfileController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ProfileService $profiles,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);

        return JsonEnvelope::success(ResourcePresenter::profile($profile));
    }

    public function update(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $profile = $this->profiles->updateOwnProfile((int) $context['user']['id'], $request->json() ?? []);

        return JsonEnvelope::success(ResourcePresenter::profile($profile));
    }
}
