<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Federation\FederationService;

final class FederationController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly FederationService $federation,
    ) {
    }

    /**
     * GET /api/v1/profiles/{handle}/federated-connections
     *
     * Public list of federated connections for a profile handle.
     *
     * @param array<string, string> $params
     */
    public function listPublicByHandle(Request $request, array $params): Response
    {
        $result = $this->federation->listPublicByHandle($params['handle'], $request->query);

        return JsonEnvelope::collection($result['items'], $result['next_cursor'], $result['has_more']);
    }

    /**
     * GET /api/v1/me/federated-connections
     *
     * Owner's list of all federated connections, including visibility and health.
     */
    public function listOwnConnections(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        $items = $this->federation->listOwnConnections((int) $context['profile']['id']);

        return JsonEnvelope::success(['connections' => $items]);
    }

    /**
     * PATCH /api/v1/me/federated-connections/{connectionId}
     *
     * Update a federated connection: show_on_profile, relationship_status (mute/block).
     *
     * @param array<string, string> $params
     */
    public function updateConnection(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        $connection = $this->federation->updateConnection(
            (int) $context['profile']['id'],
            $params['connectionId'],
            $request->json() ?? [],
        );

        return JsonEnvelope::success($connection);
    }

    // ---- Federation Node Identity ----

    public function capability(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        return JsonEnvelope::success($this->federation->getCapabilityDocument((int) $context['node']['id'], (string) ($context['node']['domain'] ?? 'localhost')));
    }

    public function ensureKey(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        return JsonEnvelope::success($this->federation->ensureNodeKey((int) $context['node']['id']));
    }

    public function inbox(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        return JsonEnvelope::success($this->federation->processIncomingActivity((int) $context['node']['id'], $request->json() ?? []));
    }

    public function outbox(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        return JsonEnvelope::success($this->federation->queueOutgoingActivity(
            (int) $context['node']['id'], (string) ($input['type'] ?? 'Follow'),
            (string) ($input['actor'] ?? ''), (string) ($input['target_domain'] ?? ''),
            $input['object'] ?? null, $input,
        ), 201);
    }
}