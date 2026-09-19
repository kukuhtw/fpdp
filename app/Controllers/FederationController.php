<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
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

    // ---- Follow / Accept / Reject / Block ----

    public function sendFollow(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        return JsonEnvelope::success($this->federation->sendFollow(
            (int) $context['node']['id'],
            (int) $context['profile']['id'],
            (string) ($input['target_actor_uri'] ?? ''),
            (string) ($input['target_domain'] ?? ''),
            $input['target_federated_address'] ?? null,
        ), 201);
    }

    public function sendUndo(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        return JsonEnvelope::success($this->federation->sendUndo(
            (int) $context['node']['id'],
            (int) $context['profile']['id'],
            (string) ($input['follow_id'] ?? ''),
        ));
    }

    public function sendBlock(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        return JsonEnvelope::success($this->federation->sendBlock(
            (int) $context['node']['id'],
            (int) $context['profile']['id'],
            (string) ($input['target_actor_uri'] ?? ''),
            (string) ($input['target_domain'] ?? ''),
        ));
    }

    public function processFollow(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        return JsonEnvelope::success($this->federation->processFollow(
            (int) $context['node']['id'],
            $input,
            (int) $context['profile']['id'],
        ));
    }

    public function processUndo(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        return JsonEnvelope::success($this->federation->processUndo(
            (int) $context['node']['id'],
            $input,
            (int) $context['profile']['id'],
        ));
    }

    // ---- Federation Node Identity ----

    /**
     * GET /api/v1/federation/capability
     *
     * Publicly fetchable discovery document (no auth) so remote nodes can
     * learn our inbox/outbox/actor endpoints and public key. When called
     * with a bearer token it describes that caller's own node instead,
     * preserving the previous owner-diagnostic behaviour.
     */
    public function capability(Request $request): Response
    {
        $token = $request->bearerToken();
        if ($token !== null) {
            $context = $this->auth->authenticate($token);
            $nodeId = (int) $context['node']['id'];
            $domain = (string) ($context['node']['domain'] ?? 'localhost');
        } else {
            $node = $this->federation->getLocalNode();
            if ($node === null) {
                throw new NotFoundException('No local node registered.');
            }
            $nodeId = (int) $node['id'];
            $domain = (string) $node['domain'];
        }

        return JsonEnvelope::success($this->federation->getCapabilityDocument($nodeId, $domain));
    }

    public function ensureKey(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        return JsonEnvelope::success($this->federation->ensureNodeKey((int) $context['node']['id']));
    }

    /**
     * POST /api/v1/federation/inbox
     *
     * Public federation delivery endpoint — remote servers deliver signed
     * Follow/Undo/Accept/Reject/Block activities here without any local
     * credentials, matching the inbox URL we advertise in our own
     * capability document.
     */
    public function inbox(Request $request): Response
    {
        return JsonEnvelope::success($this->federation->receiveActivity($request->json() ?? []), 202);
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