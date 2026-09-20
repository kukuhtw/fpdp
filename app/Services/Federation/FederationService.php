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
     * Moderation view: every remote node we have seen, so the owner can
     * decide which domains to trust or block before it affects delivery.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRemoteNodesForModeration(): array
    {
        return $this->nodes->findAll();
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
     * Entry point for the public /api/v1/federation/inbox endpoint: verifies
     * the activity's signature against the sender's discovered public key,
     * rejects blocked/unverified senders, and dispatches Follow/Undo/Accept/
     * Reject/Block activities to their handlers.
     *
     * @param array<string, mixed> $activity
     * @return array<string, mixed>
     */
    public function receiveActivity(array $activity): array
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

        $signature = $activity['signature'] ?? null;
        $verified = false;
        if (is_string($signature) && $remoteNode !== null && !empty($remoteNode['public_key'])) {
            $payloadToVerify = $activity;
            unset($payloadToVerify['signature']);
            $verified = $this->getKeyService()->verify((string) json_encode($payloadToVerify), $signature, (string) $remoteNode['public_key']);
            if (!$verified) {
                throw new ForbiddenException('Invalid activity signature.');
            }
        }

        if (in_array($type, ['Follow', 'Block'], true) && $remoteNode !== null) {
            $discovery->ensureRemoteActor($actorUri, $remoteNode);
        }

        $localProfile = $this->resolveLocalProfileForActivity($type, $activity);
        if ($localProfile === null && in_array($type, ['Follow', 'Undo', 'Block'], true)) {
            throw new NotFoundException('Target profile not found for this activity.');
        }

        if ($localProfile === null) {
            // No local profile to attribute this to (unsupported type, or an
            // Accept/Reject with no matching outgoing Follow) — nothing to
            // persist against our nodes.id foreign key, so just acknowledge.
            return ['status' => 'received', 'message' => "{$type} received, no handler", 'verified' => $verified];
        }

        $localNodeId = (int) $localProfile['node_id'];
        $status = $verified ? 'VERIFIED' : (is_string($signature) ? 'UNVERIFIED_KEY_MISSING' : 'UNSIGNED');
        $objectUri = is_string($activity['object'] ?? null) ? $activity['object'] : null;
        $ar->create($activityId, $localNodeId, 'INCOMING', $type, $actorUri, $objectUri, $senderDomain, $activity, is_string($signature) ? $signature : null, $status);

        if ($remoteNode !== null) {
            $discovery->touchRemoteNode($senderDomain);
        }

        $result = match ($type) {
            'Follow' => $this->processFollow($localNodeId, $activity, (int) $localProfile['id']),
            'Undo' => $this->processUndo($localNodeId, $activity, (int) $localProfile['id']),
            'Block' => $this->processBlockReceived($activity, $localProfile),
            'Accept' => $this->processAccept($activity),
            'Reject' => $this->processReject($activity),
            default => ['status' => 'received', 'message' => "{$type} received, no handler"],
        };

        return array_merge($result, ['verified' => $verified]);
    }

    public function queueOutgoingActivity(int $nodeId, string $type, string $actorUri, string $targetDomain, ?string $objectUri = null, array $extra = []): array
    {
        $ar = $this->getActivityRepo();
        $payload = array_merge(['@context' => 'https://fpdp.dev/ns/federation/v1', 'id' => Uuid::v4(), 'type' => $type, 'actor' => $actorUri, 'object' => $objectUri, 'published' => gmdate('c')], $extra);
        $ar->create((string) $payload['id'], $nodeId, 'OUTGOING', $type, $actorUri, $objectUri, $targetDomain, $payload);
        return $payload;
    }

    public function ensureNodeKey(int $nodeId): array
    {
        $ks = $this->getKeyService();
        if ($ks->hasKey($nodeId)) return ['status' => 'exists'];
        return $ks->generateKeypair($nodeId);
    }

    // ---- Follow / Accept / Reject / Block Flows ----

    public function sendFollow(int $nodeId, int $profileId, string $targetActorUri, string $targetDomain, ?string $targetFedAddress = null): array
    {
        $fr = $this->getFollowRepo();
        $existing = $fr->findByProfileAndTarget($profileId, $targetActorUri);
        if ($existing !== null) {
            throw new ValidationException([['field' => 'target_actor_uri', 'reason' => 'already_following']]);
        }
        $localProfile = $this->profiles->findById($profileId);
        if ($localProfile === null) throw new NotFoundException('Profile not found.');
        $actorUri = "https://{$localProfile['node_domain']}/@" . $localProfile['handle'];
        $followPubId = Uuid::v4();
        $activity = $this->queueOutgoingActivity($nodeId, 'Follow', $actorUri, $targetDomain, $targetActorUri, [
            'id' => $followPubId, 'actor' => $actorUri, 'object' => $targetActorUri, 'target_domain' => $targetDomain,
        ]);
        $fr->create($followPubId, $profileId, $targetActorUri, $targetFedAddress, null, (string) $activity['id']);
        return ['follow_id' => $followPubId, 'activity_id' => (string) $activity['id'], 'status' => 'PENDING'];
    }

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
        $followPublicId = $existing['public_id'] ?? Uuid::v4();
        if ($existing === null) {
            $fr->create($followPublicId, $localProfileId, $actorUri, $remoteActor['federated_address'] ?? null, (int) $remoteActor['id'], $activity['id'] ?? null, 'INCOMING');
        }
        $fr->updateStatus($followPublicId, 'ACCEPTED', $activity['id'] ?? null);

        $localProfile = $this->profiles->findById($localProfileId);
        $localActorUri = $localProfile ? "https://{$localProfile['node_domain']}/@" . $localProfile['handle'] : '';
        $acceptId = Uuid::v4();
        $this->queueOutgoingActivity($nodeId, 'Accept', $localActorUri, parse_url($actorUri, PHP_URL_HOST) ?? '', $actorUri, [
            'id' => $acceptId, 'actor' => $localActorUri, 'object' => $activity['id'] ?? null,
        ]);

        $conn = $this->connections->findByProfileAndActorId($localProfileId, (int) $remoteActor['id']);
        if ($conn === null) {
            $this->connections->create(Uuid::v4(), $localProfileId, (int) $remoteActor['id'], 'CONNECTED');
        }
        return ['status' => 'accepted', 'message' => "Follow from {$actorUri} accepted"];
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
        ]);
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
        $objectId = $activity['object'] ?? null;
        if (!is_string($objectId) || $objectId === '') {
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
        $objectId = $activity['object'] ?? null;
        if (!is_string($objectId) || $objectId === '') {
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
                $this->getRemoteNodeKeyRepo(),
                $this->actors,
                new \App\Core\Http\HttpClient(),
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
            'Accept', 'Reject' => $this->resolveLocalProfileFromFollowObject((string) ($activity['object'] ?? '')),
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
}
