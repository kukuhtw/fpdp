<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\ResourcePresenter;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Content\PostService;

final class PostController
{
    public function __construct(private readonly AuthService $auth, private readonly PostService $posts)
    {
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

    public function create(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        return JsonEnvelope::success(ResourcePresenter::post($this->posts->create($context, $request->json() ?? [])), 201);
    }

    public function show(Request $request, array $params): Response
    {
        return JsonEnvelope::success(ResourcePresenter::post($this->posts->get($params['postId'])));
    }

    public function update(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        return JsonEnvelope::success(ResourcePresenter::post($this->posts->update($context, $params['postId'], $request->json() ?? [])));
    }

    public function delete(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $this->posts->delete($context, $params['postId']);
        return Response::noContent();
    }
}
