<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\UnauthorizedException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\ResourcePresenter;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function register(Request $request): Response
    {
        $result = $this->auth->register($request->json() ?? []);

        return JsonEnvelope::success(self::authPayload($result), 201);
    }

    public function login(Request $request): Response
    {
        $result = $this->auth->login($request->json() ?? []);

        return JsonEnvelope::success(self::authPayload($result));
    }

    public function logout(Request $request): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new UnauthorizedException();
        }

        $this->auth->authenticate($token);
        $this->auth->logout($token);

        return Response::noContent();
    }

    public function me(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success([
            'user' => ResourcePresenter::user($context['user']),
            'node' => ResourcePresenter::node($context['node']),
            'profile' => ResourcePresenter::profile($context['profile']),
        ]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function authPayload(array $result): array
    {
        return [
            'user' => ResourcePresenter::user($result['user']),
            'node' => ResourcePresenter::node($result['node']),
            'token' => $result['token'],
        ];
    }
}
