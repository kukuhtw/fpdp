<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\ResourcePresenter;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Auth\TwoFactorService;
use App\Services\Security\RateLimiter;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly RateLimiter $rateLimiter,
        private readonly ?TwoFactorService $twoFactor = null,
    ) {
    }

    public function register(Request $request): Response
    {
        $this->rateLimiter->hit(
            'auth_register',
            $request->ipAddress,
            (int) Config::get('RATE_LIMIT_REGISTER_MAX', '5'),
            (int) Config::get('RATE_LIMIT_REGISTER_WINDOW', '3600'),
        );

        $result = $this->auth->register($request->json() ?? [], self::client($request));

        return JsonEnvelope::success(self::authPayload($result), 201);
    }

    public function login(Request $request): Response
    {
        $this->rateLimiter->hit(
            'auth_login',
            $request->ipAddress,
            (int) Config::get('RATE_LIMIT_LOGIN_MAX', '5'),
            (int) Config::get('RATE_LIMIT_LOGIN_WINDOW', '900'),
        );

        $result = $this->auth->login($request->json() ?? [], self::client($request));
        if (($result['mfa_required'] ?? false) === true) {
            // Password correct, 2FA on: no session yet, only the challenge.
            return JsonEnvelope::success($result);
        }

        return JsonEnvelope::success(self::authPayload($result));
    }

    /**
     * POST /api/v1/auth/login/verify — {"mfa_token": "...", "code": "123456"}
     * (or a recovery code). Same response as a login without 2FA.
     */
    public function verifyLogin(Request $request): Response
    {
        $this->rateLimiter->hit(
            'auth_login_verify',
            $request->ipAddress,
            (int) Config::get('RATE_LIMIT_LOGIN_MAX', '5') * 2,
            (int) Config::get('RATE_LIMIT_LOGIN_WINDOW', '900'),
        );

        return JsonEnvelope::success(self::authPayload($this->auth->verifyLoginCode($request->json() ?? [], self::client($request))));
    }

    /** GET /api/v1/me/2fa */
    public function twoFactorStatus(Request $request): Response
    {
        [$context] = $this->twoFactorContext($request, false);

        return JsonEnvelope::success($this->twoFactorService()->status($context));
    }

    /** POST /api/v1/me/2fa/setup — a new secret and otpauth:// URI to scan. */
    public function twoFactorSetup(Request $request): Response
    {
        [$context] = $this->twoFactorContext($request, true);

        return JsonEnvelope::success($this->twoFactorService()->start($context));
    }

    /** POST /api/v1/me/2fa/confirm — {"code"}; returns the recovery codes once. */
    public function twoFactorConfirm(Request $request): Response
    {
        [$context, $token] = $this->twoFactorContext($request, true);
        $input = $request->json() ?? [];

        return JsonEnvelope::success($this->twoFactorService()->confirm($context, $token, (string) ($input['code'] ?? '')));
    }

    /** POST /api/v1/me/2fa/disable — {"password", "code"}. */
    public function twoFactorDisable(Request $request): Response
    {
        [$context] = $this->twoFactorContext($request, true);
        $input = $request->json() ?? [];
        $this->twoFactorService()->disable($context, (string) ($input['password'] ?? ''), (string) ($input['code'] ?? ''));

        return JsonEnvelope::success($this->twoFactorService()->status($context));
    }

    /** POST /api/v1/me/2fa/recovery-codes — {"code"}; a new set, the old ones stop working. */
    public function twoFactorRecoveryCodes(Request $request): Response
    {
        [$context] = $this->twoFactorContext($request, true);
        $input = $request->json() ?? [];

        return JsonEnvelope::success($this->twoFactorService()->regenerateRecoveryCodes($context, (string) ($input['code'] ?? '')));
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function twoFactorContext(Request $request, bool $limit): array
    {
        $token = (string) $request->bearerToken();
        $context = $this->auth->authenticate($token);
        if ($limit) {
            $this->rateLimiter->hit(
                'auth_2fa',
                (string) $context['user']['id'],
                (int) Config::get('RATE_LIMIT_LOGIN_MAX', '5') * 2,
                (int) Config::get('RATE_LIMIT_LOGIN_WINDOW', '900'),
            );
        }

        return [$context, $token];
    }

    private function twoFactorService(): TwoFactorService
    {
        return $this->twoFactor ?? throw new NotFoundException('Two-factor authentication is not available.');
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
     * GET /api/v1/me/sessions — devices where the owner is logged in.
     */
    public function sessions(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success(['sessions' => $this->auth->listSessions($context)]);
    }

    /**
     * DELETE /api/v1/me/sessions/{sessionId} — log one other device out.
     *
     * @param array<string, string> $params
     */
    public function revokeSession(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $this->auth->revokeSession($context, (string) ($params['sessionId'] ?? ''));

        return Response::noContent();
    }

    /**
     * POST /api/v1/me/sessions/revoke-others — log every other device out.
     */
    public function revokeOtherSessions(Request $request): Response
    {
        $token = (string) $request->bearerToken();
        $context = $this->auth->authenticate($token);

        return JsonEnvelope::success(['revoked' => $this->auth->revokeOtherSessions($context, $token)]);
    }

    /**
     * POST /api/v1/me/password — {"current_password": "...", "new_password": "..."}.
     * Logs every other device out. Rate-limited per account, like login.
     */
    public function changePassword(Request $request): Response
    {
        $token = (string) $request->bearerToken();
        $context = $this->auth->authenticate($token);
        $this->rateLimiter->hit(
            'auth_password_change',
            (string) $context['user']['id'],
            (int) Config::get('RATE_LIMIT_LOGIN_MAX', '5'),
            (int) Config::get('RATE_LIMIT_LOGIN_WINDOW', '900'),
        );

        $input = $request->json() ?? [];

        return JsonEnvelope::success($this->auth->changePassword(
            $context,
            $token,
            (string) ($input['current_password'] ?? ''),
            (string) ($input['new_password'] ?? ''),
        ));
    }

    /**
     * @return array{user_agent: string|null, ip: string}
     */
    private static function client(Request $request): array
    {
        return ['user_agent' => $request->headers['user-agent'] ?? null, 'ip' => $request->ipAddress];
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
