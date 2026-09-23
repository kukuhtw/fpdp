<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\ResourcePresenter;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Content\WallCommentService;
use App\Services\Profile\ProfileService;
use App\Services\Security\RateLimiter;
use App\Services\Visitor\VisitorAuthService;

final class WallCommentController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly VisitorAuthService $visitorAuth,
        private readonly ProfileService $profiles,
        private readonly WallCommentService $comments,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $result = $this->comments->listPublic((int) $profile['node_id'], $request->query);

        return JsonEnvelope::collection(
            array_map([ResourcePresenter::class, 'wallComment'], $result['items']),
            $result['next_cursor'],
            $result['has_more'],
        );
    }

    /**
     * Visitor must be signed in with Google to post a comment.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $visitor = $this->visitorAuth->authenticate($request->bearerToken())['visitor'];

        $this->rateLimiter->hit(
            'wall_comment_create',
            (string) $visitor['id'],
            (int) Config::get('RATE_LIMIT_CORETAN_MAX', '10'),
            (int) Config::get('RATE_LIMIT_CORETAN_WINDOW', '600'),
        );

        $comment = $this->comments->create((int) $profile['node_id'], $visitor, $request->json() ?? []);

        return JsonEnvelope::success(ResourcePresenter::wallComment($comment), 201);
    }

    /**
     * Owner-only: soft-delete any comment on their own wall.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $this->comments->delete($context, $params['commentId']);

        return Response::noContent();
    }

    /**
     * Owner-only: attach (or replace) the admin reply on a comment.
     *
     * @param array<string, string> $params
     */
    public function reply(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $comment = $this->comments->reply($context, $params['commentId'], $request->json() ?? []);

        return JsonEnvelope::success(ResourcePresenter::wallComment($comment));
    }
}
