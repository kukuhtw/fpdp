<?php

declare(strict_types=1);

namespace App\Services\Federation;

use App\Core\Uuid;
use App\Repositories\NodeKeyRepository;
use RuntimeException;
use SodiumException;

/**
 * Manages node keypair generation, signing, and signature verification
 * for federation activities.
 */
final class NodeKeyService
{
    public function __construct(private readonly NodeKeyRepository $keys)
    {
    }

    /**
     * Generate an Ed25519 keypair and store it.
     *
     * @return array{public_key: string, fingerprint: string}
     */
    public function generateKeypair(int $nodeId): array
    {
        if ($this->keys->keyExists($nodeId)) {
            throw new RuntimeException('Node already has a current keypair. Rotate instead.');
        }

        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new RuntimeException('Libsodium extension required for Ed25519 key generation.');
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $publicKeyBase64 = base64_encode($publicKey);
        $secretKeyBase64 = base64_encode($secretKey);
        $fingerprint = hash('sha256', $publicKeyBase64);

        $this->keys->create($nodeId, 'ed25519', $publicKeyBase64, $secretKeyBase64, $fingerprint);

        return [
            'public_key' => $publicKeyBase64,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Sign a string payload with the node's current key.
     */
    public function sign(int $nodeId, string $payload): string
    {
        $key = $this->keys->findCurrentByNodeId($nodeId);
        if ($key === null) {
            throw new RuntimeException('No current key found for node. Generate a keypair first.');
        }

        $secretKey = base64_decode((string) $key['private_key']);

        try {
            $signature = sodium_crypto_sign_detached($payload, $secretKey);
        } catch (SodiumException $e) {
            throw new RuntimeException('Signing failed: ' . $e->getMessage());
        }

        return base64_encode($signature);
    }

    /**
     * Verify a signed payload against a public key.
     */
    public function verify(string $payload, string $signatureBase64, string $publicKeyBase64): bool
    {
        try {
            $signature = base64_decode($signatureBase64);
            $publicKey = base64_decode($publicKeyBase64);

            return sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * @return string The node's current public key in PEM-like format for capability document
     */
    public function getPublicKeyPem(int $nodeId): string
    {
        $key = $this->keys->findCurrentByNodeId($nodeId);
        if ($key === null) {
            throw new RuntimeException('No current key found for node.');
        }

        return $key['public_key'];
    }

    public function hasKey(int $nodeId): bool
    {
        return $this->keys->keyExists($nodeId);
    }
}