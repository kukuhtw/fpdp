<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\ResourcePresenter;
use App\Core\Http\Response;
use App\Services\Analytics\AnalyticsService;
use App\Services\Auth\AuthService;
use App\Services\Content\PostService;
use App\Services\Federation\FederationService;

final class PostController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PostService $posts,
        private readonly ?AnalyticsService $analytics = null,
        private readonly ?FederationService $federation = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $result = $this->posts->list($request->query);

        return JsonEnvelope::collection(
            array_map([ResourcePresenter::class, 'post'], $result['items']),
            $result['next_cursor'],
            $result['has_more'],
        );
    }

public function myPosts(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        $result = $this->posts->listOwn($context, $request->query);

        return JsonEnvelope::collection(
            array_map([ResourcePresenter::class, 'post'], $result['items']),
            $result['next_cursor'],
            $result['has_more'],
        );
    }
    public function create(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $post = $this->posts->create($context, $request->json() ?? []);
        $this->federate($context, $post, 'Create');
        return JsonEnvelope::success(ResourcePresenter::post($post), 201);
    }

    public function show(Request $request, array $params): Response
    {
        $token = $request->bearerToken();
        $context = null;
        if ($token !== null) {
            try {
                $context = $this->auth->authenticate($token);
            } catch (\Throwable) {
                // not authenticated — proceed with public view
            }
        }

        $post = $context !== null
            ? $this->posts->getOwn($params['postId'], $context)
            : $this->posts->get($params['postId']);

        if (isset($post['node_id'])) {
            $this->analytics?->recordPostView((int) $post['node_id'], (string) $post['public_id'], $request->ipAddress, $request->header('user-agent'));
        }

        return JsonEnvelope::success(ResourcePresenter::post($post));
    }

    public function update(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $post = $this->posts->update($context, $params['postId'], $request->json() ?? []);
        $this->federate($context, $post, 'Update');
        return JsonEnvelope::success(ResourcePresenter::post($post));
    }

    public function delete(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $post = $this->posts->delete($context, $params['postId']);
        $this->federate($context, $post, 'Delete');
        return Response::noContent();
    }

    /**
     * Delivers the post to the owner's accepted followers as a signed AP
     * activity. Federation is best-effort: a delivery-layer failure here
     * must never block the post itself from saving successfully.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $post
     */
    private function federate(array $context, array $post, string $activityType): void
    {
        if ($this->federation === null) {
            return;
        }
        try {
            $this->federation->publishLocalPost((int) $context['node']['id'], (int) $context['profile']['id'], $post, $activityType);
        } catch (\Throwable) {
            // Best-effort — the post itself already saved successfully.
        }
    }
}
