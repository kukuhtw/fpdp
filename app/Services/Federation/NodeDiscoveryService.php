<?php

declare(strict_types=1);

namespace App\Services\Federation;

use App\Core\Http\HttpClient;
use App\Core\Uuid;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeRepository;
use Throwable;

/**
 * Real ActivityPub discovery: resolving a `user@domain` handle or a raw
 * actor URI into a cached remote_actors row with its actual inbox URL and
 * RSA public key, fetched over HTTP per the ActivityPub/WebFinger specs —
 * this is what makes following a real Mastodon account possible at all.
 * (The pre-AP version of this class only cached a domain-level key via
 * FPDP's own proprietary, non-standard capability document; that's gone —
 * a Mastodon server doesn't have one, and keys are per-actor in real AP,
 * not per-domain.)
 *
 * Outbound fetches (actor document, WebFinger) are signed with this node's
 * own key when a signing identity is available: many real instances —
 * mastodon.social among them — run "Authorized Fetch" (secure mode) and
 * reject even public, read-only requests that aren't HTTP-Signature-signed
 * ({"error":"Request not signed"}), so an unsigned GET simply cannot read
 * their actor documents at all.
 */
final class NodeDiscoveryService
{
    private const CACHE_TTL_SECONDS = 3600;
    private const FETCH_TIMEOUT_SECONDS = 8;
    private const ACCEPT_HEADER = 'application/activity+json, application/ld+json';

    public function __construct(
        private readonly RemoteNodeRepository $nodes,
        private readonly RemoteActorRepository $actors,
        private readonly HttpClient $http,
        private readonly ?NodeKeyService $keyService = null,
        private readonly ?NodeRepository $localNodes = null,
        private readonly ?ProfileRepository $localProfiles = null,
    ) {
    }

    /**
     * Bare bookkeeping row for a domain (trust state, blocking, last-seen)
     * — no HTTP fetch. Real per-actor data (key, inbox) lives on
     * remote_actors via resolveActorByUri()/resolveActorByAccount().
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
        if ($node === null) {
            $nodeId = $this->nodes->create(Uuid::v4(), $domain);
            $node = $this->nodes->findById($nodeId);
        }

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
     * WebFinger resolution: "user@domain" (with or without a leading '@')
     * -> the actor's ActivityPub document, cached. This is what lets an
     * owner type `@alice@mastodon.social` into the follow form instead of
     * having to know Mastodon's internal actor URI shape.
     *
     * @return array<string, mixed>|null
     */
    public function resolveActorByAccount(string $account): ?array
    {
        $account = ltrim(trim($account), '@');
        if (!str_contains($account, '@')) {
            return null;
        }
        [, $domain] = explode('@', $account, 2);
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $webfingerUrl = "https://{$domain}/.well-known/webfinger?resource=" . rawurlencode('acct:' . $account);
        try {
            $response = $this->http->get(
                $webfingerUrl,
                array_merge(['Accept' => 'application/jrd+json, application/json'], $this->signedGetHeaders($webfingerUrl)),
                self::FETCH_TIMEOUT_SECONDS,
            );
        } catch (Throwable $e) {
            error_log("[NodeDiscoveryService] WebFinger request threw for {$webfingerUrl}: " . $e->getMessage());
            return null;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            error_log("[NodeDiscoveryService] WebFinger fetch for {$webfingerUrl} returned HTTP {$response['status']}: " . substr($response['body'], 0, 500));
            return null;
        }

        $jrd = json_decode($response['body'], true);
        if (!is_array($jrd) || !is_array($jrd['links'] ?? null)) {
            return null;
        }

        $actorUri = null;
        foreach ($jrd['links'] as $link) {
            if (!is_array($link)) {
                continue;
            }
            $type = (string) ($link['type'] ?? '');
            if (($link['rel'] ?? null) === 'self' && (str_contains($type, 'activity+json') || str_contains($type, 'ld+json'))) {
                $actorUri = (string) ($link['href'] ?? '');
                break;
            }
        }

        return $actorUri !== null && $actorUri !== '' ? $this->resolveActorByUri($actorUri) : null;
    }

    /**
     * Fetches (or returns the cached copy of, if fresh) a remote actor's
     * ActivityPub document by its actor URI, caching inbox URL, public
     * key, and display info into remote_actors.
     *
     * @return array<string, mixed>|null
     */
    public function resolveActorByUri(string $requestedUri, bool $forceRefresh = false): ?array
    {
        $existing = $this->actors->findByActorUri($requestedUri);
        if ($existing !== null && !$forceRefresh && !$this->isStale($existing['fetched_at'] ?? null)) {
            return $existing;
        }

        $document = $this->fetchActorDocument($requestedUri);
        if ($document === null) {
            // A stale-but-previously-known actor is still usable (e.g. the
            // remote server is briefly unreachable) — only a genuinely
            // never-seen actor fails outright.
            return $existing;
        }

        // The document's own `id` is the actor's real, canonical URI, which
        // is very often NOT the URL used to reach it — Mastodon's /@handle
        // vanity URLs canonicalize to /users/handle, for example. Use the
        // canonical id as the stored identity from here on, since that's
        // what the actor itself will use in the `actor` field of any
        // activity it later sends us.
        $canonicalUri = is_string($document['id'] ?? null) && $document['id'] !== '' ? $document['id'] : $requestedUri;
        if ($existing === null && $canonicalUri !== $requestedUri) {
            $existing = $this->actors->findByActorUri($canonicalUri);
        }

        $domain = (string) parse_url($canonicalUri, PHP_URL_HOST);
        $node = $this->ensureRemoteNode($domain);
        if ($node === null) {
            return $existing;
        }

        $handle = (string) ($document['preferredUsername'] ?? '');
        $displayName = is_string($document['name'] ?? null) ? $document['name'] : $handle;
        $avatarUrl = is_array($document['icon'] ?? null) ? ($document['icon']['url'] ?? null) : null;
        $inboxUrl = is_string($document['inbox'] ?? null) ? $document['inbox'] : null;
        $sharedInboxUrl = is_array($document['endpoints'] ?? null) && is_string($document['endpoints']['sharedInbox'] ?? null)
            ? $document['endpoints']['sharedInbox']
            : null;
        $publicKey = is_array($document['publicKey'] ?? null) ? $document['publicKey'] : [];
        $publicKeyId = is_string($publicKey['id'] ?? null) ? $publicKey['id'] : null;
        $publicKeyPem = is_string($publicKey['publicKeyPem'] ?? null) ? $publicKey['publicKeyPem'] : null;
        $federatedAddress = $handle !== '' ? "@{$handle}@{$domain}" : $canonicalUri;

        if ($existing === null) {
            $actorId = $this->actors->create(
                Uuid::v4(),
                (int) $node['id'],
                $canonicalUri,
                $federatedAddress,
                $displayName,
                $avatarUrl,
                $canonicalUri,
                $inboxUrl,
                $sharedInboxUrl,
                $publicKeyId,
                $publicKeyPem,
            );
        } else {
            $actorId = (int) $existing['id'];
            $this->actors->updateActivityPubFields($actorId, $displayName, $avatarUrl, $inboxUrl, $sharedInboxUrl, $publicKeyId, $publicKeyPem);
        }

        return $this->actors->findById($actorId);
    }

    /**
     * Resolves whatever an owner typed to find an account: `@user@domain`,
     * `user@domain`, an actor/profile URL, or a Mastodon "viewing" URL
     * copied from another instance's address bar
     * (https://<instance-you-were-on>/@user@theiractualdomain), which is
     * not itself a dereferenceable actor document, so it is resolved
     * through WebFinger at the account's real home instead.
     *
     * @return array<string, mixed>|null
     */
    public function resolveActorByAccountOrUrl(string $input): ?array
    {
        $input = trim($input);
        if (preg_match('#^https?://[^/]+/@([^@/\s]+)@([^/\s]+)/?$#', $input, $matches) === 1) {
            return $this->resolveActorByAccount($matches[1] . '@' . $matches[2]);
        }
        if (str_starts_with($input, 'http://') || str_starts_with($input, 'https://')) {
            return $this->resolveActorByUri($input);
        }

        return $this->resolveActorByAccount($input);
    }

    /**
     * Signed ActivityPub GET of any document (actor, collection, collection
     * page) for read-only previews. With $expectedHost set, a document —
     * or a redirect target — on another host is refused, so a remote actor
     * cannot point this node at arbitrary third-party URLs.
     *
     * @return array<string, mixed>|null
     */
    public function fetchActivityJson(string $url, ?string $expectedHost = null, int $timeoutSeconds = self::FETCH_TIMEOUT_SECONDS): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || ($expectedHost !== null && $host !== strtolower($expectedHost))) {
            return null;
        }

        try {
            $response = $this->http->get(
                $url,
                array_merge(['Accept' => self::ACCEPT_HEADER], $this->signedGetHeaders($url)),
                $timeoutSeconds,
            );
        } catch (Throwable $e) {
            error_log("[NodeDiscoveryService] ActivityPub fetch threw for {$url}: " . $e->getMessage());
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        $document = json_decode($response['body'], true);

        return is_array($document) ? $document : null;
    }

    /**
     * Registers/refreshes a remote actor the first time an inbound
     * activity references one we haven't seen — thin wrapper so
     * FederationService doesn't need to know discovery is a real HTTP
     * fetch now (it used to just parse the actor URI's path locally).
     *
     * @return array<string, mixed>|null
     */
    public function ensureRemoteActor(string $actorUri): ?array
    {
        return $this->resolveActorByUri($actorUri);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchActorDocument(string $actorUri): ?array
    {
        try {
            $response = $this->http->get(
                $actorUri,
                array_merge(['Accept' => self::ACCEPT_HEADER], $this->signedGetHeaders($actorUri)),
                self::FETCH_TIMEOUT_SECONDS,
            );
        } catch (Throwable $e) {
            error_log("[NodeDiscoveryService] Actor document request threw for {$actorUri}: " . $e->getMessage());
            return null;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            error_log("[NodeDiscoveryService] Actor document fetch for {$actorUri} returned HTTP {$response['status']}: " . substr($response['body'], 0, 500));
            return null;
        }

        $document = json_decode($response['body'], true);
        if (!is_array($document) || !is_string($document['id'] ?? null) || $document['id'] === '') {
            error_log("[NodeDiscoveryService] Actor document fetch for {$actorUri} returned an unparseable/id-less body: " . substr($response['body'], 0, 500));
            return null;
        }

        // The returned actor's own id must be on the SAME HOST we fetched
        // from — not an exact URL match, since a real actor's canonical id
        // legitimately differs in path from the URL used to reach it
        // (Mastodon's /@handle vanity URL canonicalizes to /users/handle).
        // A different HOST, though, would mean this server is vouching for
        // an identity it doesn't own — that's the actual spoofing case this
        // guards against.
        $requestedHost = strtolower((string) parse_url($actorUri, PHP_URL_HOST));
        $documentHost = strtolower((string) parse_url($document['id'], PHP_URL_HOST));

        if ($requestedHost === '' || $requestedHost !== $documentHost) {
            error_log("[NodeDiscoveryService] Actor document host mismatch: requested {$actorUri} (host {$requestedHost}) but document id was {$document['id']} (host {$documentHost})");
            return null;
        }

        return $document;
    }

    /**
     * Host/Date/Signature headers for an outbound GET, signed as this
     * node's own local actor — empty if no signing identity is wired up
     * (falls back to the unsigned request, which still works against
     * instances that don't require Authorized Fetch).
     *
     * @return array<string, string>
     */
    private function signedGetHeaders(string $url): array
    {
        if ($this->keyService === null || $this->localNodes === null || $this->localProfiles === null) {
            error_log("[NodeDiscoveryService] signedGetHeaders({$url}): no signing identity wired up, sending unsigned");
            return [];
        }

        $localNode = $this->localNodes->findFirst();
        if ($localNode === null) {
            error_log("[NodeDiscoveryService] signedGetHeaders({$url}): no local node found (findFirst), sending unsigned");
            return [];
        }
        $nodeId = (int) $localNode['id'];

        $localProfile = $this->localProfiles->findByNodeId($nodeId);
        if ($localProfile === null) {
            error_log("[NodeDiscoveryService] signedGetHeaders({$url}): no local profile for node {$nodeId}, sending unsigned");
            return [];
        }

        if (!$this->keyService->hasKey($nodeId)) {
            $this->keyService->generateKeypair($nodeId);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }

        $headers = ['host' => $host, 'date' => HttpSignature::httpDate()];
        $signedHeaderNames = ['(request-target)', 'host', 'date'];
        $signingString = HttpSignature::buildSigningString('GET', $path, $headers, $signedHeaderNames);
        $signature = $this->keyService->sign($nodeId, $signingString);
        $keyId = 'https://' . $localProfile['node_domain'] . '/@' . $localProfile['handle'] . '#main-key';

        return [
            'Host' => $host,
            'Date' => $headers['date'],
            'Signature' => HttpSignature::buildSignatureHeader($keyId, $signedHeaderNames, $signature),
        ];
    }

    private function isStale(?string $fetchedAt): bool
    {
        if ($fetchedAt === null) {
            return true;
        }

        // fetched_at is stored as a naive "Y-m-d H:i:s" UTC value (MySQL/
        // SQLite CURRENT_TIMESTAMP). strtotime() on a naive string uses
        // PHP's ambient default timezone (NODE_TIMEZONE, e.g. Asia/Jakarta,
        // UTC+7) instead of UTC, which would make every cache entry look
        // hours older than it really is — enough to always exceed
        // CACHE_TTL_SECONDS and defeat the cache entirely. Parse it
        // explicitly as UTC to match how it was written.
        try {
            $timestamp = (new \DateTimeImmutable($fetchedAt, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return true;
        }

        return (time() - $timestamp) > self::CACHE_TTL_SECONDS;
    }
}
