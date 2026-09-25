<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\PostRepository;
use App\Services\Federation\ActivityPubPresenter;
use App\Services\Federation\FederationService;
use App\Services\Federation\NodeKeyService;
use App\Services\Profile\ProfileService;

/**
 * The actual ActivityPub-facing surface: Actor document, WebFinger, inbox,
 * and the followers/following/outbox collections real Fediverse software
 * (Mastodon included) fetches. Everything here is either public (no local
 * bearer auth — a remote server has no FPDP account) or authenticated by
 * the request's own HTTP Signature, verified inside FederationService.
 */
final class ActivityPubController
{
    private const OUTBOX_LIMIT = 20;

    public function __construct(
        private readonly ProfileService $profiles,
        private readonly NodeKeyService $keys,
        private readonly FederationService $federation,
        private readonly ?PostRepository $posts = null,
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

    /**
     * The actor document advertises this URL, and real Fediverse servers
     * (Mastodon included) fetch it to preview an account's post history —
     * both when a user looks up a not-yet-followed remote profile, and to
     * backfill after a follow completes (Create activities pushed to an
     * inbox only cover NEW posts going forward, never history).
     *
     * @param array<string, string> $params
     */
    public function outbox(array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $domain = (string) $profile['node_domain'];
        $actorUri = ActivityPubPresenter::actorUri($domain, (string) $profile['handle']);

        $posts = $this->posts?->listPublic(self::OUTBOX_LIMIT, null, (string) $profile['handle']) ?? [];
        $activities = array_map(static function (array $post) use ($actorUri, $domain): array {
            $objectUri = "https://{$domain}/posts/{$post['public_id']}";
            $object = FederationService::buildFederatedPostObject($actorUri, $objectUri, $post);
            return [
                'id' => $objectUri . '/activity',
                'type' => 'Create',
                'actor' => $actorUri,
                'published' => $object['published'],
                'to' => $object['to'] ?? [],
                'cc' => $object['cc'] ?? [],
                'object' => $object,
            ];
        }, $posts);

        return Response::activityJson(ActivityPubPresenter::orderedCollection($actorUri . '/outbox', $activities));
    }
}
