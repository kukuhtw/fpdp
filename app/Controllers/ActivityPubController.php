<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Federation\ActivityPubPresenter;
use App\Services\Federation\FederationService;
use App\Services\Federation\NodeKeyService;
use App\Services\Profile\ProfileService;

/**
 * The actual ActivityPub-facing surface: Actor document, WebFinger, inbox,
 * and the followers/following collections real Fediverse software
 * (Mastodon included) fetches. Everything here is either public (no local
 * bearer auth — a remote server has no FPDP account) or authenticated by
 * the request's own HTTP Signature, verified inside FederationService.
 */
final class ActivityPubController
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly NodeKeyService $keys,
        private readonly FederationService $federation,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function actor(array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $nodeId = (int) $profile['node_id'];

        if (!$this->keys->hasKey($nodeId)) {
            $this->keys->generateKeypair($nodeId);
        }

        $document = ActivityPubPresenter::actor($profile, (string) $profile['node_domain'], $this->keys->getPublicKeyPem($nodeId));

        return Response::activityJson($document);
    }

    public function webfinger(Request $request): Response
    {
        $resource = (string) ($request->query['resource'] ?? '');
        if (!preg_match('/^acct:([^@]+)@(.+)$/', $resource, $matches)) {
            throw new NotFoundException('Unknown resource.');
        }
        [, $handle, $domain] = $matches;

        // Confirms the profile exists (and is public) before answering, but
        // the WebFinger response always describes our own domain regardless
        // of what the caller passed, mirroring every real implementation.
        $profile = $this->profiles->getPublicProfile($handle);

        return Response::jrdJson(ActivityPubPresenter::webfinger((string) $profile['handle'], $domain));
    }

    /**
     * @param array<string, string> $params
     */
    public function inbox(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $result = $this->federation->receiveActivity(
            $request->json() ?? [],
            $request->headers,
            $request->body ?? '',
            '/@' . $params['handle'] . '/inbox',
        );

        return JsonEnvelope::success($result, 202);
    }

    /**
     * @param array<string, string> $params
     */
    public function followers(array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $actorUri = ActivityPubPresenter::actorUri((string) $profile['node_domain'], (string) $profile['handle']);
        $uris = $this->federation->listFollowerActorUris((int) $profile['id']);

        return Response::activityJson(ActivityPubPresenter::orderedCollection($actorUri . '/followers', $uris));
    }

    /**
     * @param array<string, string> $params
     */
    public function following(array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $actorUri = ActivityPubPresenter::actorUri((string) $profile['node_domain'], (string) $profile['handle']);
        $uris = $this->federation->listFollowingActorUris((int) $profile['id']);

        return Response::activityJson(ActivityPubPresenter::orderedCollection($actorUri . '/following', $uris));
    }
}
