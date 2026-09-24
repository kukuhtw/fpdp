<?php

declare(strict_types=1);

namespace App\Services\Federation;

use App\Repositories\NodeKeyRepository;
use RuntimeException;

/**
 * Manages the node's RSA keypair, used for real ActivityPub HTTP Signatures
 * (RFC draft-cavage, as Mastodon and the rest of the Fediverse implement
 * it) — both signing this node's outgoing activities and (via getPublicKeyPem())
 * publishing the public half on the node's Actor document so remote
 * servers can verify them. RSA-2048/SHA-256 specifically because that's
 * what every major ActivityPub implementation expects; nothing else
 * verifies correctly against a Mastodon inbox.
 */
final class NodeKeyService
{
    private const KEY_TYPE = 'rsa';
    private const KEY_BITS = 2048;

    public function __construct(private readonly NodeKeyRepository $keys)
    {
    }

    /**
     * @return array{public_key: string, fingerprint: string}
     */
    public function generateKeypair(int $nodeId): array
    {
        if ($this->keys->keyExists($nodeId, self::KEY_TYPE)) {
            throw new RuntimeException('Node already has a current keypair. Rotate instead.');
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => self::KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new RuntimeException('Unable to generate an RSA keypair: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        if (!openssl_pkey_export($resource, $privateKeyPem)) {
            throw new RuntimeException('Unable to export the generated RSA private key.');
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new RuntimeException('Unable to read the generated RSA public key.');
        }
        $publicKeyPem = $details['key'];

        $fingerprint = hash('sha256', $publicKeyPem);

        $this->keys->create($nodeId, self::KEY_TYPE, $publicKeyPem, $privateKeyPem, $fingerprint);

        return ['public_key' => $publicKeyPem, 'fingerprint' => $fingerprint];
    }

    /**
     * Signs an arbitrary string (the HTTP Signature "signing string" built
     * by HttpSignature::buildSigningString(), or any other opaque payload)
     * with the node's current RSA private key. RSA-SHA256, base64-encoded.
     */
    public function sign(int $nodeId, string $payload): string
    {
        $key = $this->keys->findCurrentByNodeId($nodeId, self::KEY_TYPE);
        if ($key === null) {
            throw new RuntimeException('No current key found for node. Generate a keypair first.');
        }

        $signature = '';
        $signed = openssl_sign($payload, $signature, (string) $key['private_key'], OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new RuntimeException('Signing failed: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return base64_encode($signature);
    }

    public function verify(string $payload, string $signatureBase64, string $publicKeyPem): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false) {
            return false;
        }

        $result = openssl_verify($payload, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * The node's current RSA public key in real PEM format, for the
     * `publicKeyPem` field of its ActivityPub Actor document.
     */
    public function getPublicKeyPem(int $nodeId): string
    {
        $key = $this->keys->findCurrentByNodeId($nodeId, self::KEY_TYPE);
        if ($key === null) {
            throw new RuntimeException('No current key found for node.');
        }

        return (string) $key['public_key'];
    }

    public function hasKey(int $nodeId): bool
    {
        return $this->keys->keyExists($nodeId, self::KEY_TYPE);
    }
}
