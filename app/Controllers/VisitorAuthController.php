<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\UnauthorizedException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\OAuthStateSigner;
use App\Services\Visitor\VisitorAuthService;

final class VisitorAuthController
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly VisitorAuthService $visitorAuth,
        private readonly OAuthStateSigner $stateSigner,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function redirect(Request $request, array $params): Response
    {
        $handle = $params['handle'];
        $this->profiles->getPublicProfile($handle);

        $redirectUri = self::callbackUrl($request, $handle);
        $state = $this->stateSigner->sign($handle, $redirectUri);

        return Response::redirect($this->visitorAuth->getAuthorizationUrl($state, $redirectUri));
    }

    /**
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params): Response
    {
        $handle = $params['handle'];
        $code = (string) ($request->query['code'] ?? '');
        $state = (string) ($request->query['state'] ?? '');

        if ($code === '' || $state === '') {
            throw new UnauthorizedException('Missing OAuth code or state.');
        }

        $verified = $this->stateSigner->verify($state);
        if ($verified['handle'] !== $handle) {
            throw new UnauthorizedException('OAuth state does not match this profile.');
        }

        $profile = $this->profiles->getPublicProfile($handle);
        $result = $this->visitorAuth->handleCallback((int) $profile['node_id'], $code, $verified['redirect_uri']);

        $payload = [
            'visitor' => [
                'id' => $result['visitor']['public_id'],
                'email' => $result['visitor']['email'],
                'display_name' => $result['visitor']['display_name'],
            ],
            'token' => $result['token'],
        ];

        if (str_contains(strtolower((string) ($request->header('accept') ?? '')), 'text/html')) {
            $token = json_encode((string) $result['token']['access_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $target = json_encode('/@' . rawurlencode($handle) . '/cv', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

            return Response::html('<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Login berhasil</title></head><body><p>Login berhasil. Mengalihkan…</p><script>sessionStorage.setItem("fpdp_visitor_token", ' . $token . ');location.replace(' . $target . ');</script></body></html>');
        }

        return JsonEnvelope::success($payload);
    }

    private static function callbackUrl(Request $request, string $handle): string
    {
        $scheme = $request->header('x-forwarded-proto') ?? 'http';
        $host = $request->header('host') ?? 'localhost';

        return sprintf('%s://%s/api/v1/profiles/%s/visitor-auth/google/callback', $scheme, $host, $handle);
    }
}
