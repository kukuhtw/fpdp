<?php

declare(strict_types=1);

namespace App\Services\Visitor;

use App\Contracts\GoogleOAuthClientInterface;
use RuntimeException;

/**
 * Talks to Google's real OAuth 2.0 endpoints. Verification uses Google's
 * tokeninfo endpoint (Google verifies the ID token signature for us) rather
 * than a local JWKS/RS256 implementation, matching this project's
 * no-external-dependency approach to outbound HTTP (see RSSConnector).
 */
final class GoogleOAuthClient implements GoogleOAuthClientInterface
{
    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const TOKENINFO_ENDPOINT = 'https://oauth2.googleapis.com/tokeninfo';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        if ($this->clientId === '') {
            throw new RuntimeException('GOOGLE_CLIENT_ID is not configured.');
        }

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query($params);
    }

    public function resolveVisitorProfile(string $code, string $redirectUri): array
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('Google OAuth is not configured.');
        }

        $tokenResponse = $this->postForm(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $idToken = $tokenResponse['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new RuntimeException('Google did not return an ID token.');
        }

        $claims = $this->getJson(self::TOKENINFO_ENDPOINT . '?' . http_build_query(['id_token' => $idToken]));

        if (($claims['aud'] ?? null) !== $this->clientId) {
            throw new RuntimeException('Google ID token audience mismatch.');
        }

        $issuer = (string) ($claims['iss'] ?? '');
        if ($issuer !== 'https://accounts.google.com' && $issuer !== 'accounts.google.com') {
            throw new RuntimeException('Google ID token issuer mismatch.');
        }

        if ((int) ($claims['exp'] ?? 0) <= time()) {
            throw new RuntimeException('Google ID token has expired.');
        }

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw new RuntimeException('Google ID token is missing a subject.');
        }

        return [
            'sub' => $sub,
            'email' => (string) ($claims['email'] ?? ''),
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
            'picture' => isset($claims['picture']) ? (string) $claims['picture'] : null,
        ];
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function postForm(string $url, array $fields): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($fields),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Unable to reach Google OAuth token endpoint.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            $reason = is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'unknown error') : 'unknown error';

            throw new RuntimeException('Google OAuth token exchange failed: ' . $reason);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Unable to reach Google tokeninfo endpoint.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            throw new RuntimeException('Google ID token verification failed.');
        }

        return $decoded;
    }
}
