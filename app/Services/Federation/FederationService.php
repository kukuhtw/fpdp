<?php

declare(strict_types=1);

namespace App\Services\Federation;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\FederatedConnectionRepository;
use App\Repositories\FederatedPostRepository;
use App\Repositories\FederationActivityRepository;
use App\Repositories\FollowRepository;
use App\Repositories\NodeKeyRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeKeyRepository;
use App\Repositories\RemoteNodeRepository;

final class FederationService
{
    private const ALLOWED_RELATIONSHIPS = ['PENDING', 'FOLLOWING', 'CONNECTED', 'MUTED', 'BLOCKED', 'DISCONNECTED'];
    private const UPDATABLE_FIELDS = ['show_on_profile', 'relationship_status'];
    private const DEFAULT_LIMIT = 6;
    private const MAX_LIMIT = 50;
    private const ALLOWED_TRUST_STATES = ['UNKNOWN', 'TRUSTED', 'BLOCKED'];
    private const MAX_ACTIVITY_SKEW_SECONDS = 300;
    private const ALLOWED_CAPABILITIES = ['PROFILE', 'CONTENT', 'PRODUCTS', 'PAYMENTS'];
    private const DEFAULT_CAPABILITIES = ['PROFILE', 'CONTENT'];

    public function __construct(
        private readonly FederatedConnectionRepository $connections,
        private readonly RemoteActorRepository $actors,
        private readonly RemoteNodeRepository $nodes,
        private readonly FederatedPostRepository $posts,
        private readonly ProfileRepository $profiles,
        private readonly ?NodeKeyRepository $nodeKeys = null,
        private ?FederationActivityRepository $activities = null,
        private ?NodeKeyService $keyService = null,
        private ?FollowRepository $follows = null,
        private ?NodeRepository $localNodes = null,
        private ?RemoteNodeKeyRepository $remoteNodeKeys = null,
        private ?NodeDiscoveryService $discovery = null,
    ) {
    }

    public function listPublicByHandle(string $handle, array $query = []): array
    {
        $profile = $this->profiles->findByHandle(strtolower(trim($handle)));
        if ($profile === null || $profile['visibility'] === 'PRIVATE') throw new NotFoundException('Profile not found.');
        $limit = $this->parseLimit($query['limit'] ?? self::DEFAULT_LIMIT);
        $beforeId = $this->parseCursor($query['cursor'] ?? null);
        $rows = $this->connections->listPublicByProfileId((int) $profile['id'], $limit, $beforeId);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $actorIds = array_map(static fn(array $row): int => (int) $row['remote_actor_id'], $rows);
        $latestPosts = $this->posts->findLatestByActorIds($actorIds);
        $items = array_map(function(array $row) use ($latestPosts): array {
            $lp = $latestPosts[(int) $row['remote_actor_id']] ?? null;
            return ['id' => $row['public_id'], 'actor' => ['id' => $row['actor_public_id'], 'federated_address' => $row['federated_address'], 'display_name' => $row['actor_display_name'], 'avatar_url' => $row['actor_avatar_url'], 'canonical_url' => $row['actor_canonical_url'], 'node_domain' => $row['node_domain'], 'node_name' => $row['node_name']], 'relationship_status' => $row['relationship_status'], 'latest_post' => $lp !== null ? ['id' => $lp['public_id'], 'title' => $lp['title'], 'content' => $lp['content'], 'canonical_url' => $lp['canonical_url'], 'published_at' => $lp['published_at']] : null, 'synced_at' => $row['updated_at']];
        }, $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return ['items' => $items, 'next_cursor' => $hasMore && $last !== null ? rtrim(strtr(base64_encode((string) $last['id']), '+/', '-_'), '=') : null, 'has_more' => $hasMore];
    }

    public function listOwnConnections(int $profileId): array
    {
        $rows = $this->connections->listByProfileId($profileId);
        $actorIds = array_map(static fn(array $row): int => (int) $row['remote_actor_id'], $rows);
        $latestPosts = $this->posts->findLatestByActorIds($actorIds);
        return array_map(function(array $row) use ($latestPosts): array {
            $lp = $latestPosts[(int) $row['remote_actor_id']] ?? null;
            return ['id' => $row['public_id'], 'actor' => ['id' => $row['actor_public_id'], 'federated_address' => $row['federated_address'], 'display_name' => $row['actor_display_name'], 'avatar_url' => $row['actor_avatar_url'], 'canonical_url' => $row['actor_canonical_url'], 'node_domain' => $row['node_domain'], 'node_name' => $row['node_name']], 'relationship_status' => $row['relationship_status'], 'show_on_profile' => (bool) $row['show_on_profile'], 'latest_post' => $lp !== null ? ['id' => $lp['public_id'], 'title' => $lp['title'], 'content' => $lp['content'], 'canonical_url' => $lp['canonical_url'], 'published_at' => $lp['published_at']] : null, 'accepted_at' => $row['accepted_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
        }, $rows);
    }

    public function updateConnection(int $profileId, string $connectionPublicId, array $input): array
    {
        $connection = $this->connections->findByPublicId($connectionPublicId, true);
        if ($connection === null) {
            throw new NotFoundException('Connection not found.');
        }
        if ((int) $connection['profile_id'] !== $profileId) {
            throw new ForbiddenException('You do not own this connection.');
        }

        $errors = [];
        $unknown = array_diff(array_keys($input), self::UPDATABLE_FIELDS);
        foreach ($unknown as $field) {
            $errors[] = ['field' => $field, 'reason' => 'unknown_field'];
        }

        if (array_key_exists('show_on_profile', $input) && !is_bool($input['show_on_profile'])) {
            $errors[] = ['field' => 'show_on_profile', 'reason' => 'invalid_value'];
        }

        if (array_key_exists('relationship_status', $input)
            && !in_array($input['relationship_status'], self::ALLOWED_RELATIONSHIPS, true)) {
            $errors[] = ['field' => 'relationship_status', 'reason' => 'invalid_value'];
        }

        if ($input === []) {
            $errors[] = ['field' => '_', 'reason' => 'empty_update'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $fields = [];
        if (array_key_exists('show_on_profile', $input)) {
            $fields['show_on_profile'] = $input['show_on_profile'] ? 1 : 0;
        }
        if (array_key_exists('relationship_status', $input)) {
            $fields['relationship_status'] = $input['relationship_status'];
        }

        $this->connections->updateByPublicId($connectionPublicId, $fields);
        return $this->connections->findByPublicId($connectionPublicId, true);
    }

    private function parseLimit(int|string $raw): int
    {
        $limit = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT],
        ]);
        return $limit === false ? self::DEFAULT_LIMIT : $limit;
    }

    private function parseCursor(?string $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded === false || !ctype_digit($decoded)) {
            throw new ValidationException([['field' => 'cursor', 'reason' => 'invalid_value']]);
        }
        return (int) $decoded;
    }

    // ---- Federation Node Identity & Discovery ----

    public function getCapabilityDocument(int $nodeId, string $domain): array
    {
        $ks = $this->getKeyService();
        return [
            'protocol' => 'FPDP-Federation/1.0',
            'domain' => $domain,
            'node_id' => $nodeId,
            'capabilities' => ['follow', 'accept', 'reject', 'undo', 'block', 'create_post', 'update_post', 'delete_post'],
            'endpoints' => [
                'inbox' => "https://{$domain}/api/v1/federation/inbox",
                'outbox' => "https://{$domain}/api/v1/federation/outbox",
                'actor' => "https://{$domain}/api/v1/federation/actor",
                'capability' => "https://{$domain}/api/v1/federation/capability",
            ],
            'public_key' => $ks->hasKey($nodeId) ? $ks->getPublicKeyPem($nodeId) : null,
        ];
    }

    /**
     * The sole locally-hosted node, used to resolve "our own node" when a
     * remote server fetches our capability document without credentials.
     *
     * @return array<string, mixed>|null
     */
    public function getLocalNode(): ?array
    {
        return $this->getLocalNodeRepo()->findFirst();
    }

    /**
     * Dashboard summary for the owner's Federation page: accepted follower/
     * following counts and the node's advertised feature capabilities.
     *
     * @return array{follower_count: int, following_count: int, capabilities: array<int, string>}
     */
    public function getFederationSummary(int $profileId, int $nodeId): array
    {
        $fr = $this->getFollowRepo();

        return [
            'follower_count' => $fr->countByDirection($profileId, 'INCOMING'),
            'following_count' => $fr->countByDirection($profileId, 'OUTGOING'),
            'pending_follow_requests' => $fr->countPendingByProfileId($profileId),
            'capabilities' => $this->readNodeCapabilities($nodeId),
        ];
    }

    /**
     * Sets which feature capabilities (PROFILE/CONTENT/PRODUCTS/PAYMENTS)
     * the owner wants this node to advertise. This is a visible, owner-
     * controlled setting only — it does not currently gate access to the
     * underlying features (e.g. disabling PRODUCTS does not block the
     * marketplace endpoints), so treat it as informational until an
     * enforcement layer is built.
     *
     * @param array<int, string> $capabilities
     * @return array<int, string>
     */
    public function updateNodeCapabilities(int $nodeId, array $capabilities): array
    {
        $normalized = array_values(array_unique(array_map('strtoupper', $capabilities)));
        $invalid = array_diff($normalized, self::ALLOWED_CAPABILITIES);
        if ($invalid !== []) {
            throw new ValidationException([['field' => 'capabilities', 'reason' => 'invalid_value']]);
        }

        $this->getLocalNodeRepo()->updateCapabilities($nodeId, $normalized);

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function readNodeCapabilities(int $nodeId): array
    {
        $node = $this->getLocalNodeRepo()->findById($nodeId);
        $raw = $node['capabilities'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return self::DEFAULT_CAPABILITIES;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== [] ? array_values($decoded) : self::DEFAULT_CAPABILITIES;
    }

    /**
     * Moderation view: every remote node we have seen, enriched with the
     * cached public key and actor count so the owner can decide which
     * domains to trust or block before it affects delivery.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRemoteNodesForModeration(): array
    {
        $nodes = $this->nodes->findAll();
        $keys = $this->getRemoteNodeKeyRepo();

        return array_map(function (array $node) use ($keys): array {
            $key = $keys->findByRemoteNodeId((int) $node['id']);

            return [
                'id' => $node['public_id'],
                'domain' => $node['domain'],
                'name' => $node['name'],
                'status' => $node['status'],
                'trust_state' => $node['trust_state'],
                'public_key_fingerprint' => $key['fingerprint'] ?? null,
                'key_fetched_at' => $key['fetched_at'] ?? null,
                'actor_count' => $this->actors->countByRemoteNodeId((int) $node['id']),
                'last_seen_at' => $node['last_seen_at'],
            ];
        }, $nodes);
    }

    /**
     * Manually trigger discovery of a remote domain by fetching its
     * federation capability document (and public key) over HTTP. If the
     * domain is already known and its key cache is still fresh, this
     * returns the existing record without making an outbound request.
     *
     * @return array<string, mixed> The remote node record with a merged
     *         'public_key' field and a 'discovered' flag.
     */
    public function discoverRemoteNode(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || filter_var("https://{$domain}/", FILTER_VALIDATE_URL) === false) {
            throw new ValidationException([['field' => 'domain', 'reason' => 'invalid_format']]);
        }

        $node = $this->getDiscoveryService()->ensureRemoteNode($domain);
        if ($node === null) {
            throw new ValidationException([['field' => 'domain', 'reason' => 'discovery_failed',
                'message' => "Could not fetch capability document from {$domain}. The remote node may not support FPDP federation, or the domain is unreachable."]]);
        }

        // Enrich with actor count
        $actorCount = $this->actors->countByRemoteNodeId((int) $node['id']);

        return [
            'id' => $node['public_id'],
            'domain' => $node['domain'],
            'name' => $node['name'],
            'status' => $node['status'],
            'trust_state' => $node['trust_state'],
            'public_key' => $node['public_key'],
            'actor_count' => $actorCount,
            'last_seen_at' => $node['last_seen_at'],
            'discovered' => true,
        ];
    }

    /**
     * Sets a remote node's trust_state (UNKNOWN/TRUSTED/BLOCKED). BLOCKED is
     * enforced immediately at the top of receiveActivity(), before any
     * signature verification or discovery is attempted for that domain.
     *
     * @return array<string, mixed>
     */
    public function updateRemoteNodeTrust(string $domain, string $trustState): array
    {
        if (!in_array($trustState, self::ALLOWED_TRUST_STATES, true)) {
            throw new ValidationException([['field' => 'trust_state', 'reason' => 'invalid_value']]);
        }

        $node = $this->nodes->findByDomain(strtolower(trim($domain)));
        if ($node === null) {
            throw new NotFoundException('Remote node not found.');
        }

        $this->nodes->updateTrustState((int) $node['id'], $trustState);

        return $this->nodes->findById((int) $node['id']);
    }

    /**
     * Entry point for a node's /@{handle}/inbox endpoint: verifies the
     * request's HTTP Signature (RFC draft-cavage, as Mastodon and the rest
     * of the Fediverse send it — a `Signature:` header over the actual
     * HTTP request, not a field embedded in the JSON body) against the
     * sending actor's published RSA key, rejects blocked/unverified
     * senders, and dispatches Follow/Undo/Accept/Reject/Block activities
     * to their handlers.
     *
     * @param array<string, mixed> $activity
     * @param array<string, string> $headers Lowercase header names, as Request exposes them.
     */
    public function receiveActivity(array $activity, array $headers = [], string $rawBody = '', string $requestPath = ''): array
    {
        $type = (string) ($activity['type'] ?? '');
        $actorUri = (string) ($activity['actor'] ?? '');
        $activityId = (string) ($activity['id'] ?? '');
        if ($type === '' || $actorUri === '' || $activityId === '') {
            throw new ValidationException([['field' => '_', 'reason' => 'missing_required_fields']]);
        }

        $senderDomain = parse_url($actorUri, PHP_URL_HOST);
        if (!is_string($senderDomain) || $senderDomain === '') {
            throw new ValidationException([['field' => 'actor', 'reason' => 'invalid_actor_uri']]);
        }
        $senderDomain = strtolower($senderDomain);

        if (in_array($senderDomain, array_map('strtolower', $this->nodes->findBlockedDomains()), true)) {
            throw new ForbiddenException('Sender domain is blocked.');
        }

        $published = $activity['published'] ?? null;
        if (is_string($published) && $published !== '') {
            $publishedAt = strtotime($published);
            if ($publishedAt !== false && abs(time() - $publishedAt) > self::MAX_ACTIVITY_SKEW_SECONDS) {
                throw new ForbiddenException('Activity timestamp is outside the acceptable window.');
            }
        }

        $ar = $this->getActivityRepo();
        if ($ar->existsByActivityId($activityId)) {
            return ['status' => 'duplicate', 'message' => 'Already processed'];
        }

        $discovery = $this->getDiscoveryService();
        $remoteNode = $discovery->ensureRemoteNode($senderDomain);

        [$verified, $signatureHeader] = $this->verifyInboundSignature($headers, $rawBody, $requestPath, $actorUri);

        if (in_array($type, ['Follow', 'Block'], true)) {
            $discovery->ensureRemoteActor($actorUri);
        }

        $localProfile = $this->resolveLocalProfileForActivity($type, $activity);
        if ($localProfile === null && in_array($type, ['Follow', 'Undo', 'Block'], true)) {
            throw new NotFoundException('Target profile not found for this activity.');
        }

        if ($localProfile === null) {
            // No local profile to attribute this to (unsupported type, or an
            // Accept/Reject with no matching outgoing Follow) — nothing to
            // persist against our nodes.id foreign key, so just acknowledge.
            // This activity is otherwise lost with no trace, so for the
            // types that SHOULD always resolve (Accept/Reject echo back an
            // id we ourselves generated) log the raw payload — a mismatch
            // here means the id we sent and the id Mastodon echoed back
            // don't line up, which is otherwise invisible.
            if (in_array($type, ['Accept', 'Reject'], true)) {
                error_log("[FederationService] {$type} from {$actorUri} could not be matched to any outgoing Follow — raw activity: " . json_encode($activity));
            }
            return ['status' => 'received', 'message' => "{$type} received, no handler", 'verified' => $verified];
        }

        $localNodeId = (int) $localProfile['node_id'];
        $status = $verified ? 'VERIFIED' : ($signatureHeader !== null ? 'UNVERIFIED_KEY_MISSING' : 'UNSIGNED');
        $rawObject = $activity['object'] ?? null;
        $objectUri = is_string($rawObject) ? $rawObject : (is_array($rawObject) ? (string) ($rawObject['id'] ?? '') : null);
        $ar->create($activityId, $localNodeId, 'INCOMING', $type, $actorUri, $objectUri !== '' ? $objectUri : null, $senderDomain, $activity, $signatureHeader, $status);

        if ($remoteNode !== null) {
            $discovery->touchRemoteNode($senderDomain);
        }

        $result = match ($type) {
            'Follow' => $this->processFollow($localNodeId, $activity, (int) $localProfile['id']),
            'Undo' => $this->processUndo($localNodeId, $activity, (int) $localProfile['id']),
            'Block' => $this->processBlockReceived($activity, $localProfile),
            'Accept' => $this->processAccept($activity),
            'Reject' => $this->processReject($activity),
            'Create' => $this->processCreate($activity),
            'Update' => $this->processUpdate($activity),
            'Delete' => $this->processDelete($activity),
            default => ['status' => 'received', 'message' => "{$type} received, no handler"],
        };

        return array_merge($result, ['verified' => $verified]);
    }

    public function queueOutgoingActivity(int $nodeId, string $type, string $actorUri, string $targetDomain, ?string $objectUri = null, array $extra = [], ?string $targetActorUri = null): array
    {
        $ar = $this->getActivityRepo();
        $payload = array_merge(['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => Uuid::v4(), 'type' => $type, 'actor' => $actorUri, 'object' => $objectUri, 'published' => gmdate('c')], $extra);
        $ar->create((string) $payload['id'], $nodeId, 'OUTGOING', $type, $actorUri, $objectUri, $targetDomain, $payload, null, 'PENDING', $targetActorUri ?? $objectUri);
        return $payload;
    }

    /**
     * Verifies an inbound HTTP Signature (draft-cavage — the header-based
     * scheme Mastodon and the rest of the Fediverse actually use) against
     * the claimed signer's published RSA key, resolved (and cached) from
     * their Actor document via the keyId. Mirrors the old code's leniency:
     * no Signature header at all is accepted as unverified (status
     * UNSIGNED downstream); a header that's present but whose key can't be
     * resolved is also accepted as unverified (UNVERIFIED_KEY_MISSING); a
     * header that's present, resolvable, and simply doesn't verify is a
     * hard rejection — that combination only happens for a forged/tampered
     * request, never a legitimate misconfiguration.
     *
     * @param array<string, string> $headers
     * @return array{0: bool, 1: ?string} [verified, raw Signature header value or null]
     */
    private function verifyInboundSignature(array $headers, string $rawBody, string $requestPath, string $actorUri): array
    {
        $signatureHeader = $headers['signature'] ?? null;
        if (!is_string($signatureHeader) || $signatureHeader === '') {
            return [false, null];
        }

        $parsed = HttpSignature::parseSignatureHeader($signatureHeader);
        if ($parsed === null) {
            return [false, $signatureHeader];
        }

        if (isset($headers['digest'])) {
            $expectedDigest = HttpSignature::digestHeader($rawBody);
            if (!hash_equals($expectedDigest, $headers['digest'])) {
                throw new ForbiddenException('Digest header does not match the request body.');
            }
        }

        $signerActorUri = explode('#', $parsed['keyId'], 2)[0];
        $signerActor = $this->getDiscoveryService()->resolveActorByUri($signerActorUri);
        $publicKeyPem = $signerActor['public_key_pem'] ?? null;
        if ($publicKeyPem === null || $publicKeyPem === '') {
            return [false, $signatureHeader];
        }

        $signingString = HttpSignature::buildSigningString('POST', $requestPath, $headers, $parsed['headers']);
        $verified = $this->getKeyService()->verify($signingString, $parsed['signature'], $publicKeyPem);

        if (!$verified) {
            throw new ForbiddenException('Invalid activity signature.');
        }

        return [true, $signatureHeader];
    }

    public function ensureNodeKey(int $nodeId): array
    {
        $ks = $this->getKeyService();
        if ($ks->hasKey($nodeId)) return ['status' => 'exists'];
        return $ks->generateKeypair($nodeId);
    }

    // ---- Follow / Accept / Reject / Block Flows ----

    /**
     * Owner-facing entry point: resolves whatever the owner typed — a
     * `@user@domain` handle (WebFinger) or a direct profile/actor URL — into
     * a real ActivityPub actor (inbox URL + RSA public key fetched and
     * cached), then sends the Follow. This is what makes following a real
     * Mastodon account possible without the owner needing to know Mastodon's
     * internal actor URI shape.
     */
    public function sendFollowByAccount(int $nodeId, int $profileId, string $accountOrUrl): array
    {
        $accountOrUrl = trim($accountOrUrl);
        if ($accountOrUrl === '') {
            throw new ValidationException([['field' => 'account', 'reason' => 'required']], 'Enter an account (e.g. @user@mastodon.social) or a profile URL to follow.');
        }

        $discovery = $this->getDiscoveryService();

        // Mastodon's own web UI shows a remote account you're browsing to
        // (e.g. via search) as https://<the-instance-you're-on>/@user@theiractualdomain
        // — a local "viewing" URL, not the account's real canonical address.
        // People naturally copy this straight out of the address bar, so
        // detect it and resolve @user@theiractualdomain via WebFinger
        // against its real home instead of fetching the viewing URL itself
        // (which isn't a dereferenceable ActivityPub actor document).
        if (preg_match('#^https?://[^/]+/@([^@/\s]+)@([^/\s]+)/?$#', $accountOrUrl, $matches) === 1) {
            $actor = $discovery->resolveActorByAccount($matches[1] . '@' . $matches[2]);
        } else {
            $looksLikeUrl = str_starts_with($accountOrUrl, 'http://') || str_starts_with($accountOrUrl, 'https://');
            $actor = $looksLikeUrl
                ? $discovery->resolveActorByUri($accountOrUrl)
                : $discovery->resolveActorByAccount($accountOrUrl);
        }

        if ($actor === null || empty($actor['inbox_url'])) {
            throw new ValidationException([['field' => 'account', 'reason' => 'resolution_failed']], "Could not resolve \"{$accountOrUrl}\" to a reachable ActivityPub actor.");
        }

        return $this->sendFollow($nodeId, $profileId, (string) $actor['actor_uri'], (string) $actor['node_domain'], (string) $actor['federated_address']);
    }

    public function sendFollow(int $nodeId, int $profileId, string $targetActorUri, string $targetDomain, ?string $targetFedAddress = null): array
    {
        $fr = $this->getFollowRepo();
        $existing = $fr->findByProfileAndTarget($profileId, $targetActorUri);
        // PENDING and DISCONNECTED are not active relationships: PENDING
        // means the remote server's Accept was never received (e.g. their
        // side accepted before our inbox/WebFinger was reachable, and gave
        // up retrying delivery — there is no other way to recover than
        // resending), and DISCONNECTED means the owner explicitly
        // unfollowed. Both should allow a fresh attempt rather than
        // permanently blocking re-follows the way any existing row used to.
        if ($existing !== null && !in_array($existing['status'], ['PENDING', 'DISCONNECTED'], true)) {
            throw new ValidationException(
                [['field' => 'target_actor_uri', 'reason' => 'already_following']],
                'You already have a follow request or connection to this account (status: ' . $existing['status'] . '). Check Federasi > Koneksi.',
            );
        }
        $localProfile = $this->profiles->findById($profileId);
        if ($localProfile === null) throw new NotFoundException('Profile not found.');
        $actorUri = "https://{$localProfile['node_domain']}/@" . $localProfile['handle'];
        // The follow's own public_id (our internal identifier for this
        // relationship row) stays stable across resends; the AS2 activity's
        // own id must be a fresh UUID every attempt — it's what Mastodon
        // echoes back in Accept.object.id, and federation_activities.public_id
        // is unique, so reusing the follow's id here would collide on resend.
        $followPubId = $existing !== null ? (string) $existing['public_id'] : Uuid::v4();
        $followActivityId = Uuid::v4();
        $activity = $this->queueOutgoingActivity($nodeId, 'Follow', $actorUri, $targetDomain, $targetActorUri, [
            'id' => $followActivityId, 'actor' => $actorUri, 'object' => $targetActorUri, 'target_domain' => $targetDomain,
        ]);
        if ($existing !== null) {
            $fr->updateStatus($followPubId, 'PENDING', (string) $activity['id']);
        } else {
            $fr->create($followPubId, $profileId, $targetActorUri, $targetFedAddress, null, (string) $activity['id']);
        }
        return ['follow_id' => $followPubId, 'activity_id' => (string) $activity['id'], 'status' => 'PENDING'];
    }

    /**
     * Handles an inbound Follow activity: records it as PENDING and leaves
     * it for the owner to approve or reject from the dashboard — see
     * approveFollowRequest()/rejectFollowRequest(). It no longer auto-
     * accepts, except for the pre-existing BLOCKED short-circuit below.
     */
    public function processFollow(int $nodeId, array $activity, int $localProfileId): array
    {
        $actorUri = $activity['actor'] ?? '';
        $objectUri = $activity['object'] ?? '';
        if ($actorUri === '' || $objectUri === '') return ['status' => 'error', 'message' => 'Invalid activity'];
        $remoteActor = $this->actors->findByActorUri($actorUri);
        if ($remoteActor === null) return ['status' => 'error', 'message' => 'Unknown actor'];

        $fr = $this->getFollowRepo();
        $existing = $fr->findByProfileAndTarget($localProfileId, $actorUri);
        if ($existing !== null && $existing['status'] === 'BLOCKED') {
            $this->queueOutgoingActivity($nodeId, 'Reject', $objectUri, parse_url($actorUri, PHP_URL_HOST) ?? '', $actorUri);
            return ['status' => 'rejected', 'message' => 'Blocked actor'];
        }
        if ($existing !== null && $existing['status'] === 'ACCEPTED') {
            return ['status' => 'accepted', 'message' => 'Already following'];
        }

        $followPublicId = $existing['public_id'] ?? Uuid::v4();
        if ($existing === null) {
            $fr->create($followPublicId, $localProfileId, $actorUri, $remoteActor['federated_address'] ?? null, (int) $remoteActor['id'], $activity['id'] ?? null, 'INCOMING');
        } else {
            // Resent while still pending — refresh which activity id Accept/Reject will reference.
            $fr->updateStatus($followPublicId, 'PENDING', $activity['id'] ?? null);
        }

        return ['status' => 'pending', 'message' => "Follow request from {$actorUri} is awaiting approval", 'follow_id' => $followPublicId];
    }

    /**
     * Owner-only list of incoming follow requests awaiting approve/reject.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFollowRequests(int $profileId): array
    {
        return array_map(static function (array $row): array {
            return [
                'id' => $row['public_id'],
                'actor' => [
                    'actor_uri' => $row['target_actor_uri'],
                    'federated_address' => $row['federated_address'] ?? $row['target_federated_address'],
                    'display_name' => $row['actor_display_name'],
                    'avatar_url' => $row['actor_avatar_url'],
                    'node_domain' => $row['node_domain'],
                ],
                'requested_at' => $row['created_at'],
            ];
        }, $this->getFollowRepo()->findPendingByProfileId($profileId));
    }

    /**
     * Owner approves a pending incoming follow request: sends a signed
     * Accept back to the requester and establishes the connection.
     *
     * @return array<string, mixed>
     */
    public function approveFollowRequest(int $nodeId, int $profileId, string $followPublicId): array
    {
        $follow = $this->requireOwnedPendingFollow($profileId, $followPublicId);

        $localActorUri = $this->localActorUri($profileId);
        $this->queueOutgoingActivity($nodeId, 'Accept', $localActorUri, parse_url((string) $follow['target_actor_uri'], PHP_URL_HOST) ?? '', (string) $follow['target_actor_uri'], [
            'id' => Uuid::v4(), 'actor' => $localActorUri,
            // Accept.object embeds the ORIGINAL Follow activity (spec
            // requirement — Mastodon and other real AP servers expect
            // this, not a bare id string): Follow.actor is the follower,
            // Follow.object is us.
            'object' => ['id' => $follow['activity_public_id'], 'type' => 'Follow', 'actor' => $follow['target_actor_uri'], 'object' => $localActorUri],
        ], (string) $follow['target_actor_uri']);

        $this->getFollowRepo()->updateStatus($followPublicId, 'ACCEPTED', null);

        $remoteActor = $follow['remote_actor_id'] !== null
            ? $this->actors->findById((int) $follow['remote_actor_id'])
            : $this->actors->findByActorUri((string) $follow['target_actor_uri']);
        if ($remoteActor !== null) {
            $conn = $this->connections->findByProfileAndActorId($profileId, (int) $remoteActor['id']);
            if ($conn === null) {
                $this->connections->create(Uuid::v4(), $profileId, (int) $remoteActor['id'], 'CONNECTED');
            }
        }

        return ['status' => 'accepted', 'follow_id' => $followPublicId];
    }

    /**
     * Owner rejects a pending incoming follow request: sends a signed
     * Reject back to the requester. No connection is created.
     *
     * @return array<string, mixed>
     */
    public function rejectFollowRequest(int $nodeId, int $profileId, string $followPublicId): array
    {
        $follow = $this->requireOwnedPendingFollow($profileId, $followPublicId);

        $localActorUri = $this->localActorUri($profileId);
        $this->queueOutgoingActivity($nodeId, 'Reject', $localActorUri, parse_url((string) $follow['target_actor_uri'], PHP_URL_HOST) ?? '', (string) $follow['target_actor_uri'], [
            'id' => Uuid::v4(), 'actor' => $localActorUri,
            'object' => ['id' => $follow['activity_public_id'], 'type' => 'Follow', 'actor' => $follow['target_actor_uri'], 'object' => $localActorUri],
        ], (string) $follow['target_actor_uri']);

        $this->getFollowRepo()->updateStatus($followPublicId, 'REJECTED', null);

        return ['status' => 'rejected', 'follow_id' => $followPublicId];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwnedPendingFollow(int $profileId, string $followPublicId): array
    {
        $follow = $this->getFollowRepo()->findByPublicId($followPublicId);
        if ($follow === null) {
            throw new NotFoundException('Follow request not found.');
        }
        if ((int) $follow['profile_id'] !== $profileId || $follow['direction'] !== 'INCOMING') {
            throw new ForbiddenException('You do not own this follow request.');
        }
        if ($follow['status'] !== 'PENDING') {
            throw new ValidationException([['field' => 'status', 'reason' => 'not_pending']]);
        }

        return $follow;
    }

    private function localActorUri(int $profileId): string
    {
        $localProfile = $this->profiles->findById($profileId);
        return $localProfile !== null ? "https://{$localProfile['node_domain']}/@" . $localProfile['handle'] : '';
    }

    public function sendUndo(int $nodeId, int $profileId, string $followPublicId): array
    {
        $fr = $this->getFollowRepo();
        $follow = $fr->findByPublicId($followPublicId);
        if ($follow === null) throw new NotFoundException('Follow not found.');
        if ((int) $follow['profile_id'] !== $profileId) throw new ForbiddenException('You do not own this follow.');
        $localProfile = $this->profiles->findById((int) $follow['profile_id']);
        if ($localProfile === null) throw new NotFoundException('Profile not found.');
        $actorUri = "https://{$localProfile['node_domain']}/@" . $localProfile['handle'];
        $undoId = Uuid::v4();
        $this->queueOutgoingActivity($nodeId, 'Undo', $actorUri, parse_url($follow['target_actor_uri'], PHP_URL_HOST) ?? '', $follow['activity_public_id'] ?? null, [
            'id' => $undoId, 'actor' => $actorUri,
            'object' => ['id' => $follow['activity_public_id'], 'type' => 'Follow', 'actor' => $actorUri, 'object' => $follow['target_actor_uri']],
        ], (string) $follow['target_actor_uri']);
        $fr->updateStatus($followPublicId, 'DISCONNECTED', null);
        return ['status' => 'undone', 'undo_id' => $undoId];
    }

    public function processUndo(int $nodeId, array $activity, int $localProfileId): array
    {
        $object = $activity['object'] ?? [];
        $objectId = is_array($object) ? ($object['id'] ?? '') : $object;
        $fr = $this->getFollowRepo();
        foreach ($fr->findFollowersByProfileId($localProfileId) as $f) {
            if ($f['activity_public_id'] === $objectId) {
                $fr->updateStatus($f['public_id'], 'DISCONNECTED', null);
                return ['status' => 'undone', 'message' => 'Follow undone'];
            }
        }
        return ['status' => 'not_found', 'message' => 'No matching follow found'];
    }

    /**
     * Handles an inbound Accept activity: the remote actor accepted a Follow
     * we sent earlier, so mark it ACCEPTED and establish the connection.
     *
     * @param array<string, mixed> $activity
     */
    public function processAccept(array $activity): array
    {
        $objectId = self::extractActivityObjectId($activity['object'] ?? null);
        if ($objectId === null) {
            return ['status' => 'error', 'message' => 'Invalid Accept payload'];
        }

        $fr = $this->getFollowRepo();
        $follow = $fr->findByActivityPublicId($objectId);
        if ($follow === null) {
            return ['status' => 'not_found', 'message' => 'No matching follow request'];
        }

        $fr->updateStatus($follow['public_id'], 'ACCEPTED', null);

        $remoteActor = $follow['remote_actor_id'] !== null
            ? $this->actors->findById((int) $follow['remote_actor_id'])
            : $this->actors->findByActorUri((string) $follow['target_actor_uri']);

        if ($remoteActor !== null) {
            $conn = $this->connections->findByProfileAndActorId((int) $follow['profile_id'], (int) $remoteActor['id']);
            if ($conn === null) {
                $this->connections->create(Uuid::v4(), (int) $follow['profile_id'], (int) $remoteActor['id'], 'CONNECTED');
            }
        }

        return ['status' => 'accepted', 'message' => 'Follow accepted by remote'];
    }

    /**
     * Handles an inbound Reject activity: the remote actor declined a Follow
     * we sent earlier.
     *
     * @param array<string, mixed> $activity
     */
    public function processReject(array $activity): array
    {
        $objectId = self::extractActivityObjectId($activity['object'] ?? null);
        if ($objectId === null) {
            return ['status' => 'error', 'message' => 'Invalid Reject payload'];
        }

        $fr = $this->getFollowRepo();
        $follow = $fr->findByActivityPublicId($objectId);
        if ($follow === null) {
            return ['status' => 'not_found', 'message' => 'No matching follow request'];
        }

        $fr->updateStatus($follow['public_id'], 'REJECTED', null);

        return ['status' => 'rejected', 'message' => 'Follow rejected by remote'];
    }

    /**
     * Handles an inbound Block activity: the remote actor blocked our local
     * profile. Records it so we stop showing/delivering to them.
     *
     * @param array<string, mixed> $activity
     * @param array<string, mixed> $localProfile
     */
    public function processBlockReceived(array $activity, array $localProfile): array
    {
        $actorUri = (string) ($activity['actor'] ?? '');
        if ($actorUri === '') {
            return ['status' => 'error', 'message' => 'Invalid Block payload'];
        }

        $remoteActor = $this->actors->findByActorUri($actorUri);
        if ($remoteActor !== null) {
            $conn = $this->connections->findByProfileAndActorId((int) $localProfile['id'], (int) $remoteActor['id']);
            if ($conn !== null) {
                $this->connections->updateByPublicId((string) $conn['public_id'], ['relationship_status' => 'BLOCKED']);
            }
        }

        $fr = $this->getFollowRepo();
        $existing = $fr->findByProfileAndTarget((int) $localProfile['id'], $actorUri);
        if ($existing !== null) {
            $fr->updateStatus((string) $existing['public_id'], 'DISCONNECTED', null);
        }

        return ['status' => 'acknowledged', 'message' => 'Remote block recorded'];
    }

    public function sendBlock(int $nodeId, int $profileId, string $targetActorUri, string $targetDomain): array
    {
        $fr = $this->getFollowRepo();
        $existing = $fr->findByProfileAndTarget($profileId, $targetActorUri);
        $localProfile = $this->profiles->findById($profileId);
        if ($localProfile === null) throw new NotFoundException('Profile not found.');
        $actorUri = "https://{$localProfile['node_domain']}/@" . $localProfile['handle'];
        $blockId = Uuid::v4();
        $this->queueOutgoingActivity($nodeId, 'Block', $actorUri, $targetDomain, $targetActorUri, [
            'id' => $blockId, 'actor' => $actorUri, 'object' => $targetActorUri,
        ]);
        $blockTargetPublicId = $existing['public_id'] ?? Uuid::v4();
        if ($existing === null) {
            $fr->create($blockTargetPublicId, $profileId, $targetActorUri, null, null, null);
        }
        $fr->updateStatus($blockTargetPublicId, 'BLOCKED', null);
        return ['status' => 'blocked', 'block_id' => $blockId];
    }

    /**
     * Handles an inbound Create activity: a followed actor published a new
     * post. Stores it in federated_posts so it shows up as that actor's
     * latest post in the owner's connections list. Only Note/Article
     * objects are stored — anything else (e.g. an actor Update wrapped as
     * a Create in some implementations) is acknowledged but not persisted.
     *
     * @param array<string, mixed> $activity
     */
    public function processCreate(array $activity): array
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object)) {
            return ['status' => 'error', 'message' => 'Invalid Create payload'];
        }

        $objectType = (string) ($object['type'] ?? '');
        if (!in_array($objectType, ['Note', 'Article'], true)) {
            return ['status' => 'received', 'message' => "Create({$objectType}) received, unsupported object type"];
        }

        $objectUri = (string) ($object['id'] ?? '');
        if ($objectUri === '') {
            return ['status' => 'error', 'message' => 'Missing object id'];
        }

        if ($this->posts->findByObjectUri($objectUri) !== null) {
            return ['status' => 'duplicate', 'message' => 'Post already stored'];
        }

        $actorUri = (string) ($activity['actor'] ?? '');
        $remoteActor = $this->getDiscoveryService()->ensureRemoteActor($actorUri);
        if ($remoteActor === null) {
            return ['status' => 'error', 'message' => 'Could not resolve the sending actor'];
        }

        $this->posts->create(
            Uuid::v4(),
            (int) $remoteActor['id'],
            $objectUri,
            self::extractObjectUrl($object['url'] ?? null) ?? $objectUri,
            self::extractObjectText($object['name'] ?? $object['summary'] ?? null),
            self::extractObjectText($object['content'] ?? null),
            self::normalizeActivityTimestamp($object['published'] ?? null),
            self::extractObjectVisibility($object),
        );

        return ['status' => 'created', 'message' => 'Federated post stored'];
    }

    /**
     * Handles an inbound Update activity for a previously seen post. If we
     * never stored the original (e.g. the Create predates our follow being
     * accepted), this is treated as a late Create instead of being dropped.
     *
     * @param array<string, mixed> $activity
     */
    public function processUpdate(array $activity): array
    {
        $object = $activity['object'] ?? null;
        if (!is_array($object)) {
            return ['status' => 'error', 'message' => 'Invalid Update payload'];
        }

        $objectUri = (string) ($object['id'] ?? '');
        if ($objectUri === '') {
            return ['status' => 'error', 'message' => 'Missing object id'];
        }

        $existing = $this->posts->findByObjectUri($objectUri);
        if ($existing === null) {
            return $this->processCreate($activity);
        }

        $this->posts->updateByObjectUri(
            $objectUri,
            self::extractObjectText($object['name'] ?? $object['summary'] ?? null) ?? $existing['title'],
            self::extractObjectText($object['content'] ?? null) ?? $existing['content'],
            self::extractObjectUrl($object['url'] ?? null) ?? $existing['canonical_url'],
            self::extractObjectVisibility($object),
        );

        return ['status' => 'updated', 'message' => 'Federated post updated'];
    }

    /**
     * Handles an inbound Delete activity: soft-deletes the post so it stops
     * appearing as that actor's latest post. Mastodon commonly sends the
     * object as a bare tombstone id string rather than an embedded object.
     *
     * @param array<string, mixed> $activity
     */
    public function processDelete(array $activity): array
    {
        $object = $activity['object'] ?? null;
        $objectUri = is_string($object) ? $object : (is_array($object) ? (string) ($object['id'] ?? '') : '');
        if ($objectUri === '') {
            return ['status' => 'error', 'message' => 'Missing object id'];
        }

        $this->posts->softDeleteByObjectUri($objectUri);

        return ['status' => 'deleted', 'message' => 'Federated post removed'];
    }

    private static function normalizeActivityTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * AS2's `url` can be a bare string, a single Link object ({href}), or
     * an array of either — real-world Mastodon posts use a Link object.
     */
    private static function extractObjectUrl(mixed $value): ?string
    {
        if (is_array($value) && array_is_list($value)) {
            $value = $value[0] ?? null;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value) && is_string($value['href'] ?? null) && $value['href'] !== '') {
            return $value['href'];
        }
        return null;
    }

    private static function extractObjectText(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * AS2 has no first-class "visibility" field — Mastodon-style servers
     * signal it via addressing: the public collection IRI in `to`/`cc`
     * means PUBLIC, anything else (typically just the actor's followers
     * collection) means followers-only, stored here as UNLISTED.
     *
     * @param array<string, mixed> $object
     */
    private static function extractObjectVisibility(array $object): string
    {
        $addressees = array_merge(
            self::asStringList($object['to'] ?? null),
            self::asStringList($object['cc'] ?? null),
        );
        return in_array('https://www.w3.org/ns/activitystreams#Public', $addressees, true) ? 'PUBLIC' : 'UNLISTED';
    }

    /** @return array<int, string> */
    private static function asStringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }
        return [];
    }

    /**
     * Actor URIs for a profile's accepted followers/following, for the
     * ActivityPub followers/following OrderedCollection endpoints.
     *
     * @return array<int, string>
     */
    public function listFollowerActorUris(int $profileId): array
    {
        return array_map(static fn (array $row): string => (string) $row['target_actor_uri'], $this->getFollowRepo()->findFollowersByProfileId($profileId));
    }

    /**
     * @return array<int, string>
     */
    public function listFollowingActorUris(int $profileId): array
    {
        return array_map(static fn (array $row): string => (string) $row['target_actor_uri'], $this->getFollowRepo()->findFollowingByProfileId($profileId));
    }

    /**
     * Delivers a local post to every accepted follower as a signed
     * Create/Update/Delete activity — the outbound counterpart to
     * processCreate()/processUpdate()/processDelete() on the receiving
     * side. PRIVATE posts are never federated. A post with no accepted
     * followers is a silent no-op: nothing queued, nothing to fail.
     *
     * @param array<string, mixed> $post
     */
    public function publishLocalPost(int $nodeId, int $profileId, array $post, string $activityType = 'Create'): void
    {
        if ((string) ($post['visibility'] ?? 'PUBLIC') === 'PRIVATE') {
            return;
        }

        $followers = $this->getFollowRepo()->findFollowersByProfileId($profileId);
        if ($followers === []) {
            return;
        }

        $localProfile = $this->profiles->findById($profileId);
        if ($localProfile === null) {
            return;
        }

        $domain = (string) $localProfile['node_domain'];
        $actorUri = "https://{$domain}/@{$localProfile['handle']}";
        $objectUri = "https://{$domain}/posts/{$post['public_id']}";
        $object = $activityType === 'Delete' ? $objectUri : self::buildFederatedPostObject($actorUri, $objectUri, $post);

        foreach ($followers as $follower) {
            $targetActorUri = (string) ($follower['target_actor_uri'] ?? '');
            if ($targetActorUri === '') {
                continue;
            }
            $targetDomain = (string) ($follower['node_domain'] ?? parse_url($targetActorUri, PHP_URL_HOST) ?? '');
            $this->queueOutgoingActivity($nodeId, $activityType, $actorUri, $targetDomain, $objectUri, [
                'object' => $object,
            ], $targetActorUri);
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function buildFederatedPostObject(string $actorUri, string $objectUri, array $post): array
    {
        $type = (string) ($post['post_type'] ?? 'NOTE') === 'ARTICLE' ? 'Article' : 'Note';
        $addressing = (string) ($post['visibility'] ?? 'PUBLIC') === 'UNLISTED'
            ? ['to' => ["{$actorUri}/followers"], 'cc' => []]
            : ['to' => ['https://www.w3.org/ns/activitystreams#Public'], 'cc' => ["{$actorUri}/followers"]];

        $object = array_merge([
            'id' => $objectUri,
            'type' => $type,
            'attributedTo' => $actorUri,
            'content' => (string) ($post['content'] ?? ''),
            'url' => $objectUri,
            'published' => self::toAs2Timestamp($post['published_at'] ?? $post['created_at'] ?? null),
        ], $addressing);

        if (is_string($post['title'] ?? null) && $post['title'] !== '') {
            $object['name'] = $post['title'];
        }

        $media = $post['media'] ?? [];
        if (is_array($media) && $media !== []) {
            $attachments = array_values(array_filter(array_map(static function ($item): ?array {
                if (!is_array($item) || !is_string($item['url'] ?? null) || $item['url'] === '') {
                    return null;
                }
                return [
                    'type' => 'Document',
                    'mediaType' => self::guessAttachmentMediaType((string) ($item['type'] ?? '')),
                    'url' => $item['url'],
                    'name' => is_string($item['alt_text'] ?? null) ? $item['alt_text'] : null,
                ];
            }, $media)));
            if ($attachments !== []) {
                $object['attachment'] = $attachments;
            }
        }

        return $object;
    }

    private static function toAs2Timestamp(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('c');
            } catch (\Exception) {
                // fall through to now
            }
        }
        return gmdate('c');
    }

    private static function guessAttachmentMediaType(string $type): string
    {
        return match (strtoupper($type)) {
            'IMAGE' => 'image/*',
            'VIDEO' => 'video/*',
            'AUDIO' => 'audio/*',
            default => 'application/octet-stream',
        };
    }

    /**
     * Delivers a promoted product to the owner's accepted followers as a
     * signed Create/Update activity, formatted as a simple announcement
     * post (title, price, short description, checkout link, photo as an
     * attachment) rather than a structured commerce object — Mastodon and
     * the rest of the Fediverse have no first-class "Product" type that
     * renders meaningfully in a timeline. Only products explicitly marked
     * is_promoted are federated; everything else stays local-only. There
     * is no product delete/archive flow yet, so unlike
     * publishLocalPost(), this never needs to handle 'Delete'.
     *
     * @param array<string, mixed> $product
     */
    public function publishLocalProduct(int $nodeId, array $product, string $activityType = 'Create'): void
    {
        if (!(bool) ($product['is_promoted'] ?? false)) {
            return;
        }
        if ((string) ($product['visibility'] ?? 'PUBLIC') === 'PRIVATE') {
            return;
        }
        if ((string) ($product['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            return;
        }

        $localProfile = $this->profiles->findByNodeId($nodeId);
        if ($localProfile === null) {
            return;
        }

        $followers = $this->getFollowRepo()->findFollowersByProfileId((int) $localProfile['id']);
        if ($followers === []) {
            return;
        }

        $domain = (string) $localProfile['node_domain'];
        $actorUri = "https://{$domain}/@{$localProfile['handle']}";
        $objectUri = "https://{$domain}/shop/{$product['public_id']}";
        $object = self::buildFederatedProductObject($actorUri, $objectUri, $product);

        foreach ($followers as $follower) {
            $targetActorUri = (string) ($follower['target_actor_uri'] ?? '');
            if ($targetActorUri === '') {
                continue;
            }
            $targetDomain = (string) ($follower['node_domain'] ?? parse_url($targetActorUri, PHP_URL_HOST) ?? '');
            $this->queueOutgoingActivity($nodeId, $activityType, $actorUri, $targetDomain, $objectUri, [
                'object' => $object,
            ], $targetActorUri);
        }
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private static function buildFederatedProductObject(string $actorUri, string $objectUri, array $product): array
    {
        $title = (string) ($product['title'] ?? '');
        $price = number_format((float) ($product['price'] ?? 0), 0, ',', '.');
        $currency = (string) ($product['currency'] ?? 'IDR');
        $descriptionText = trim(strip_tags((string) ($product['description'] ?? '')));
        if (mb_strlen($descriptionText) > 280) {
            $descriptionText = mb_substr($descriptionText, 0, 280) . '…';
        }

        $contentHtml = '<p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong> — '
            . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' ' . $price . '</p>';
        if ($descriptionText !== '') {
            $contentHtml .= '<p>' . htmlspecialchars($descriptionText, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $contentHtml .= '<p>Beli di sini: <a href="' . htmlspecialchars($objectUri, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($objectUri, ENT_QUOTES, 'UTF-8') . '</a></p>';

        $object = [
            'id' => $objectUri,
            'type' => 'Note',
            'attributedTo' => $actorUri,
            'content' => $contentHtml,
            'name' => $title,
            'url' => $objectUri,
            'published' => self::toAs2Timestamp($product['updated_at'] ?? $product['created_at'] ?? null),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => ["{$actorUri}/followers"],
        ];

        $media = $product['media'] ?? [];
        if (is_array($media) && $media !== []) {
            $attachments = array_values(array_filter(array_map(static function ($item): ?array {
                if (!is_array($item) || !is_string($item['url'] ?? null) || $item['url'] === '') {
                    return null;
                }
                return [
                    'type' => 'Document',
                    'mediaType' => self::guessAttachmentMediaType((string) ($item['type'] ?? 'IMAGE')),
                    'url' => $item['url'],
                    'name' => is_string($item['alt_text'] ?? null) ? $item['alt_text'] : null,
                ];
            }, $media)));
            if ($attachments !== []) {
                $object['attachment'] = $attachments;
            }
        }

        return $object;
    }

    private function getFollowRepo(): FollowRepository
    {
        if ($this->follows === null) {
            $this->follows = new FollowRepository(\App\Core\Database::connection());
        }
        return $this->follows;
    }

    private function getKeyService(): NodeKeyService
    {
        if ($this->keyService === null) {
            $kr = $this->nodeKeys ?? new NodeKeyRepository(\App\Core\Database::connection());
            $this->keyService = new NodeKeyService($kr);
        }
        return $this->keyService;
    }

    private function getActivityRepo(): FederationActivityRepository
    {
        if ($this->activities === null) {
            $this->activities = new FederationActivityRepository(\App\Core\Database::connection());
        }
        return $this->activities;
    }

    private function getLocalNodeRepo(): NodeRepository
    {
        if ($this->localNodes === null) {
            $this->localNodes = new NodeRepository(\App\Core\Database::connection());
        }
        return $this->localNodes;
    }

    private function getRemoteNodeKeyRepo(): RemoteNodeKeyRepository
    {
        if ($this->remoteNodeKeys === null) {
            $this->remoteNodeKeys = new RemoteNodeKeyRepository(\App\Core\Database::connection());
        }
        return $this->remoteNodeKeys;
    }

    private function getDiscoveryService(): NodeDiscoveryService
    {
        if ($this->discovery === null) {
            $this->discovery = new NodeDiscoveryService(
                $this->nodes,
                $this->actors,
                new \App\Core\Http\HttpClient(),
                $this->getKeyService(),
                $this->getLocalNodeRepo(),
                $this->profiles,
            );
        }
        return $this->discovery;
    }

    /**
     * Resolves which local profile an inbound activity targets, so it can be
     * attributed to the right node. Follow/Block carry the target actor URI
     * directly in `object`; Undo nests it inside `object.object`; Accept/
     * Reject instead carry the id of the Follow activity we originally sent,
     * which is looked up to find the profile that sent it.
     *
     * @param array<string, mixed> $activity
     * @return array<string, mixed>|null
     */
    private function resolveLocalProfileForActivity(string $type, array $activity): ?array
    {
        return match ($type) {
            'Follow', 'Block' => $this->resolveLocalProfileFromActorUri((string) ($activity['object'] ?? '')),
            'Undo' => $this->resolveLocalProfileFromActorUri($this->extractUndoTargetUri($activity)),
            'Accept', 'Reject' => $this->resolveLocalProfileFromFollowObject(self::extractActivityObjectId($activity['object'] ?? null) ?? ''),
            'Create', 'Update', 'Delete' => $this->resolveLocalProfileFromRemoteActorUri((string) ($activity['actor'] ?? '')),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function extractUndoTargetUri(array $activity): string
    {
        $object = $activity['object'] ?? null;
        if (is_array($object)) {
            return (string) ($object['object'] ?? '');
        }
        return '';
    }

    /**
     * Accept/Reject.object is, per spec, the embedded original Follow
     * activity (`{id, type: 'Follow', actor, object}`) — real Fediverse
     * servers (and our own outgoing Accept/Reject, see
     * approveFollowRequest()/rejectFollowRequest()) always send it this
     * way. A bare id string is still accepted defensively.
     */
    private static function extractActivityObjectId(mixed $object): ?string
    {
        if (is_string($object) && $object !== '') {
            return $object;
        }
        if (is_array($object) && is_string($object['id'] ?? null) && $object['id'] !== '') {
            return $object['id'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLocalProfileFromActorUri(string $uri): ?array
    {
        if ($uri === '') {
            return null;
        }
        $path = parse_url($uri, PHP_URL_PATH) ?: '';
        if (!str_starts_with($path, '/@') || strlen($path) <= 2) {
            return null;
        }
        $handle = substr($path, 2);

        $profile = $this->profiles->findByHandle(strtolower($handle));
        if ($profile === null) {
            return null;
        }

        $domain = parse_url($uri, PHP_URL_HOST);
        if ($domain !== null && strcasecmp((string) $profile['node_domain'], $domain) !== 0) {
            // The handle exists but on a different domain than claimed — reject as spoofed.
            return null;
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveLocalProfileFromFollowObject(string $activityId): ?array
    {
        if ($activityId === '') {
            return null;
        }
        $follow = $this->getFollowRepo()->findByActivityPublicId($activityId);
        if ($follow === null) {
            return null;
        }
        return $this->profiles->findById((int) $follow['profile_id']);
    }

    /**
     * For Create/Update/Delete: there's no explicit target in the activity
     * itself (delivery to our inbox implies we're a follower), so the
     * local profile is whichever one of ours actually has an accepted
     * connection to the sending actor.
     *
     * @return array<string, mixed>|null
     */
    private function resolveLocalProfileFromRemoteActorUri(string $actorUri): ?array
    {
        if ($actorUri === '') {
            return null;
        }
        $remoteActor = $this->actors->findByActorUri($actorUri);
        if ($remoteActor === null) {
            return null;
        }
        foreach ($this->connections->findConnectionsByActorId((int) $remoteActor['id']) as $connection) {
            if (in_array($connection['relationship_status'], ['FOLLOWING', 'CONNECTED'], true)) {
                return $this->profiles->findById((int) $connection['profile_id']);
            }
        }
        return null;
    }
}
