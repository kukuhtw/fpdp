<?php

declare(strict_types=1);

namespace App\Services\Federation;

use App\Core\Http\HttpClient;
use App\Core\Uuid;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeKeyRepository;
use App\Repositories\RemoteNodeRepository;
use Throwable;

/**
 * Resolves a remote domain into a known remote_nodes/remote_node_keys record,
 * fetching and caching the remote node's federation capability document
 * (and its public key) over HTTP when it is unknown or stale.
 */
final class NodeDiscoveryService
{
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly RemoteNodeRepository $nodes,
        private readonly RemoteNodeKeyRepository $keys,
        private readonly RemoteActorRepository $actors,
        private readonly HttpClient $http,
    ) {
    }

    /**
     * Returns the remote node row (with a merged 'public_key' field) for a
     * domain, discovering it via its capability document if unknown or stale.
     *
     * @return array<string, mixed>|null
     */
    public function ensureRemoteNode(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $node = $this->nodes->findByDomain($domain);
        $key = $node !== null ? $this->keys->findByRemoteNodeId((int) $node['id']) : null;

        if ($node === null || $this->isStale($key['fetched_at'] ?? null)) {
            $publicKey = $this->fetchPublicKey($domain);
            if ($node === null) {
                $nodeId = $this->nodes->create(Uuid::v4(), $domain);
                $node = $this->nodes->findById($nodeId);
            }
            if ($publicKey !== null && $node !== null) {
                $this->keys->upsert((int) $node['id'], 'ed25519', $publicKey);
                $key = $this->keys->findByRemoteNodeId((int) $node['id']);
            }
        }

        if ($node === null) {
            return null;
        }

        $node['public_key'] = $key['public_key'] ?? null;

        return $node;
    }

    public function touchRemoteNode(string $domain): void
    {
        $node = $this->nodes->findByDomain(strtolower(trim($domain)));
        if ($node !== null) {
            $this->nodes->updateLastSeen((int) $node['id']);
        }
    }

    /**
     * Registers a stub remote actor from its actor URI (https://domain/@handle)
     * the first time it is seen, so inbound activities from unknown actors
     * can still be processed instead of being rejected as "unknown actor".
     *
     * @param array<string, mixed> $remoteNode
     * @return array<string, mixed>|null
     */
    public function ensureRemoteActor(string $actorUri, array $remoteNode): ?array
    {
        $existing = $this->actors->findByActorUri($actorUri);
        if ($existing !== null) {
            return $existing;
        }

        $path = parse_url($actorUri, PHP_URL_PATH) ?: '';
        if (!str_starts_with($path, '/@') || strlen($path) <= 2) {
            return null;
        }
        $handle = substr($path, 2);

        $federatedAddress = '@' . $handle . '@' . $remoteNode['domain'];
        $actorId = $this->actors->create(
            Uuid::v4(),
            (int) $remoteNode['id'],
            $actorUri,
            $federatedAddress,
            null,
            null,
            $actorUri,
        );

        return $this->actors->findById($actorId);
    }

    private function fetchPublicKey(string $domain): ?string
    {
        try {
            $response = $this->http->get("https://{$domain}/api/v1/federation/capability", [], 8);
        } catch (Throwable) {
            return null;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        $payload = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;

        if (!is_array($payload) || ($payload['domain'] ?? null) !== $domain) {
            return null;
        }

        return is_string($payload['public_key'] ?? null) ? $payload['public_key'] : null;
    }

    private function isStale(?string $fetchedAt): bool
    {
        if ($fetchedAt === null) {
            return true;
        }

        $timestamp = strtotime($fetchedAt);
        return $timestamp === false || (time() - $timestamp) > self::CACHE_TTL_SECONDS;
    }
}
