<?php

declare(strict_types=1);

namespace App\Services\Visitor;

use App\Core\Exceptions\UnauthorizedException;

/**
 * Signs and verifies the OAuth "state" parameter without server-side session
 * storage: the state carries its own payload (which profile handle started
 * the flow, the redirect URI, and an expiry) plus an HMAC so it cannot be
 * forged or replayed outside its short validity window.
 */
final class OAuthStateSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(string $handle, string $redirectUri, int $ttlSeconds = 600): string
    {
        $payload = json_encode([
            'handle' => $handle,
            'redirect_uri' => $redirectUri,
            'expires_at' => time() + $ttlSeconds,
        ]);

        $encodedPayload = self::base64UrlEncode((string) $payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->secret);

        return $encodedPayload . '.' . $signature;
    }

    /**
     * @return array{handle: string, redirect_uri: string}
     */
    public function verify(string $state): array
    {
        [$encodedPayload, $signature] = array_pad(explode('.', $state, 2), 2, '');
        if ($encodedPayload === '' || $signature === '') {
            throw new UnauthorizedException('Invalid OAuth state.');
        }

        $expected = hash_hmac('sha256', $encodedPayload, $this->secret);
        if (!hash_equals($expected, $signature)) {
            throw new UnauthorizedException('OAuth state signature mismatch.');
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);
        if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) <= time()) {
            throw new UnauthorizedException('OAuth state has expired.');
        }

        return [
            'handle' => (string) ($payload['handle'] ?? ''),
            'redirect_uri' => (string) ($payload['redirect_uri'] ?? ''),
        ];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
