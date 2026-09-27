<?php

declare(strict_types=1);

namespace App\Services\Federation;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthorizedException;

/**
 * Verifies an inbound HTTP Signature (draft-cavage — the header-based scheme
 * Mastodon and the rest of the Fediverse use) and refuses anything that is
 * not provably from the claimed actor:
 *
 * - no Signature header, or one that doesn't parse → 401;
 * - the signature must cover (request-target), host, and date — and digest
 *   for a request with a body, whose Digest must match it; the Date must be
 *   within MAX_SKEW_SECONDS, so a captured request can't be replayed later
 *   or have its body swapped;
 * - the key must belong to the claimed actor — a valid signature from some
 *   other account is not permission to act as this one;
 * - a key that can't be resolved, or a signature that fails, is retried once
 *   against a freshly fetched actor document (key rotation) before the
 *   request is refused.
 *
 * Used by the ActivityPub inbox and by the node-to-node order endpoints.
 */
final class SignedRequestVerifier
{
    public const MAX_SKEW_SECONDS = 300;

    public function __construct(
        private readonly NodeDiscoveryService $discovery,
        private readonly NodeKeyService $keys,
    ) {
    }

    /**
     * @param array<string, string> $headers Lowercase header names, as Request exposes them.
     * @param string|null $claimedActorUri The actor the request says it is from; null to accept
     *        whichever actor signed it (the caller then checks the returned actor).
     * @return array{actor_uri: string, signature: string} the verified signer and the raw Signature header
     */
    public function verify(string $method, string $requestTarget, array $headers, string $rawBody, ?string $claimedActorUri): array
    {
        $method = strtoupper($method);
        $signatureHeader = $headers['signature'] ?? null;
        if (!is_string($signatureHeader) || $signatureHeader === '') {
            throw new UnauthorizedException('Requests must carry an HTTP Signature.');
        }

        $parsed = HttpSignature::parseSignatureHeader($signatureHeader);
        if ($parsed === null) {
            throw new UnauthorizedException('The Signature header could not be parsed.');
        }

        $hasBody = !in_array($method, ['GET', 'HEAD'], true);
        $required = $hasBody ? ['(request-target)', 'host', 'date', 'digest'] : ['(request-target)', 'host', 'date'];
        $signedHeaders = array_map('strtolower', $parsed['headers']);
        foreach ($required as $name) {
            if (!in_array($name, $signedHeaders, true)) {
                throw new UnauthorizedException("The signature must cover the {$name} header.");
            }
        }

        if ($hasBody && (!isset($headers['digest']) || !hash_equals(HttpSignature::digestHeader($rawBody), $headers['digest']))) {
            throw new ForbiddenException('Digest header does not match the request body.');
        }

        $date = strtotime((string) ($headers['date'] ?? ''));
        if ($date === false || abs(time() - $date) > self::MAX_SKEW_SECONDS) {
            throw new UnauthorizedException('The signed Date header is missing or outside the acceptable window.');
        }

        $signerActorUri = explode('#', $parsed['keyId'], 2)[0];
        $signingString = HttpSignature::buildSigningString($method, $requestTarget, $headers, $parsed['headers']);

        foreach ([false, true] as $forceRefresh) {
            $signerActor = $this->discovery->resolveActorByUri($signerActorUri, $forceRefresh);
            $publicKeyPem = (string) ($signerActor['public_key_pem'] ?? '');
            if ($signerActor === null || $publicKeyPem === '') {
                continue;
            }

            // Compare canonical identities: the keyId may use a vanity URL
            // that the actor document canonicalizes (Mastodon /@x -> /users/x).
            $signerIdentities = array_values(array_filter([$signerActorUri, (string) ($signerActor['actor_uri'] ?? '')]));
            if ($claimedActorUri !== null && !in_array($claimedActorUri, $signerIdentities, true)) {
                throw new UnauthorizedException('The signing key does not belong to the activity actor.');
            }

            if ($this->keys->verify($signingString, $parsed['signature'], $publicKeyPem)) {
                return ['actor_uri' => $claimedActorUri ?? $signerIdentities[0], 'signature' => $signatureHeader];
            }
        }

        throw new UnauthorizedException('Invalid request signature.');
    }
}
