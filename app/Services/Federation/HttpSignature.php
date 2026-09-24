<?php

declare(strict_types=1);

namespace App\Services\Federation;

/**
 * Stateless HTTP Signature (draft-cavage) helpers — the wire format every
 * real ActivityPub implementation (Mastodon included) uses to sign/verify
 * federated requests. Actual RSA signing/verification is NodeKeyService's
 * job; this class only builds/parses the strings and headers around it.
 */
final class HttpSignature
{
    public const DEFAULT_SIGNED_HEADERS = ['(request-target)', 'host', 'date', 'digest'];

    public static function digestHeader(string $body): string
    {
        return 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    }

    /**
     * RFC 7231 IMF-fixdate, the format every ActivityPub implementation
     * expects in the signed `Date` header (e.g. "Wed, 24 Sep 2025 12:00:00 GMT").
     */
    public static function httpDate(?int $timestamp = null): string
    {
        return gmdate('D, d M Y H:i:s \G\M\T', $timestamp ?? time());
    }

    /**
     * @param array<string, string> $headers Lowercase header name => value.
     *        Must contain every name in $signedHeaderNames other than
     *        '(request-target)'.
     * @param array<int, string> $signedHeaderNames
     */
    public static function buildSigningString(string $method, string $path, array $headers, array $signedHeaderNames): string
    {
        $lines = [];
        foreach ($signedHeaderNames as $name) {
            $lines[] = $name === '(request-target)'
                ? '(request-target): ' . strtolower($method) . ' ' . $path
                : $name . ': ' . ($headers[$name] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $signedHeaderNames
     */
    public static function buildSignatureHeader(string $keyId, array $signedHeaderNames, string $signatureBase64): string
    {
        return sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $keyId,
            implode(' ', $signedHeaderNames),
            $signatureBase64,
        );
    }

    /**
     * @return array{keyId: string, algorithm: string, headers: array<int, string>, signature: string}|null
     */
    public static function parseSignatureHeader(string $header): ?array
    {
        if (preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER) !== false) {
            $parts = [];
            foreach ($matches as $match) {
                $parts[$match[1]] = $match[2];
            }

            if (isset($parts['keyId'], $parts['signature'])) {
                return [
                    'keyId' => $parts['keyId'],
                    'algorithm' => $parts['algorithm'] ?? 'rsa-sha256',
                    'headers' => isset($parts['headers']) && $parts['headers'] !== ''
                        ? explode(' ', $parts['headers'])
                        : ['date'],
                    'signature' => $parts['signature'],
                ];
            }
        }

        return null;
    }
}
