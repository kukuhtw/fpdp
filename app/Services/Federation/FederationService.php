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
use App\Repositories\ProfileRepository;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeRepository;

final class FederationService
{
    private const ALLOWED_RELATIONSHIPS = ['PENDING', 'FOLLOWING', 'CONNECTED', 'MUTED', 'BLOCKED', 'DISCONNECTED'];
    private const UPDATABLE_FIELDS = ['show_on_profile', 'relationship_status'];
    private const DEFAULT_LIMIT = 6;
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly FederatedConnectionRepository $connections,
        private readonly RemoteActorRepository $actors,
        private readonly RemoteNodeRepository $nodes,
        private readonly FederatedPostRepository $posts,
        private readonly ProfileRepository $profiles,
        private readonly ?NodeKeyRepository $nodeKeys = null,
        private readonly ?FederationActivityRepository $activities = null,
        private readonly ?NodeKeyService $keyService = null,
        private readonly ?FollowRepository $follows = null,
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
        $actorIds = array_map(static fn (array $row): int => (int) $row['remote_actor_id'], $rows);
        $latestPosts = $this->posts->findLatestByActorIds($actorIds);
        $items = array_map(function (array $row) use ($latestPosts): array {
            $latestPost = $latestPosts[(int) $row['remote_actor_id']] ?? null;
            return [
                'id' => $row['public_id'],
                'actor' => [
                    'id' => $row['actor_public_id'], 'federated_address' => $row['federated_address'],
                    'display_name' => $row['actor_display_name'], 'avatar_url' => $row['actor_avatar_url'],
                    'canonical_url' => $row['actor_canonical_url'], 'node_domain' => $row['node_domain'], 'node_name' => $row['node_name'],
                ],
                'relationship_status' => $row['relationship_status'],
                'latest_post' => $latestPost !== null ? [
                    'id' => $latestPost['public_id'], 'title' => $latestPost['title'], 'content' => $latestPost['content'],
                    'canonical_url' => $latestPost['canonical_url'], 'published_at' => $latestPost['published_at'],
                ] : null,
                'synced_at' => $row['updated_at'],
            ];
        }, $rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return [
            'items' => $items,
            'next_cursor' => $hasMore && $last !== null ? rtrim(strtr(base64_encode((string) $last['id']), '+/', '-_'), '=') : null,
            'has_more' => $hasMore,
        ];
    }

    public function listOwnConnections(int $profileId): array
    {
        $rows = $this->connections->listByProfileId($profileId);
        $actorIds = array_map(static fn (array $row): int => (int) $row['remote_actor_id'], $rows);
        $latestPosts = $this->posts->findLatestByActorIds($actorIds);
        return array_map(function (array $row) use ($latestPosts): array {
            $latestPost = $latestPosts[(int) $row['remote_actor_id']] ?? null;
public function updateConnection(int $profileId, string $connectionPublicId, array $input): array
    {
        $connection = $this->connections->findByPublicId($connectionPublicId, true);
        if ($connection === null) throw new NotFoundException('Connection not found.');
        if ((int) $connection['profile_id'] !== $profileId) throw new ForbiddenException('You do not own this connection.');
        $errors = [];
        $unknown = array_diff(array_keys($input), self::UPDATABLE_FIELDS);
        foreach ($unknown as $field) $errors[] = ['field' => $field, 'reason' => 'unknown_field'];
        if (array_key_exists('show_on_profile', $input) && !is_bool($input['show_on_profile'])) $errors[] = ['field' => 'show_on_profile', 'reason' => 'invalid_value'];
        if (array_key_exists('relationship_status', $input) && !in_array($input['relationship_status'], self::ALLOWED_RELATIONSHIPS, true)) $errors[] = ['field' => 'relationship_status', 'reason' => 'invalid_value'];
        if ($input === []) $errors[] = ['field' => '_', 'reason' => 'empty_update'];
        if ($errors !== []) throw new ValidationException($errors);
        $fields = [];
        if (array_key_exists('show_on_profile', $input)) $fields['show_on_profile'] = $input['show_on_profile'] ? 1 : 0;
        if (array_key_exists('relationship_status', $input)) $fields['relationship_status'] = $input['relationship_status'];
        $this->connections->updateByPublicId($connectionPublicId, $fields);
        return $this->connections->findByPublicId($connectionPublicId, true);
    }

    private function parseLimit(int|string $raw): int
    {
        $limit = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT]]);
        return $limit === false ? self::DEFAULT_LIMIT : $limit;
    }

    private function parseCursor(?string $raw): ?int
    {
        if ($raw === null || $raw === '') return null;
        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded === false || !ctype_digit($decoded)) throw new ValidationException([['field' => 'cursor', 'reason' => 'invalid_value']]);
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

    public function processIncomingActivity(int $nodeId, array $activity): array
    {
        $ar = $this->getActivityRepo();
        $id = $activity['id'] ?? Uuid::v4();
        $type = $activity['type'] ?? 'Unknown';
        $actorUri = $activity['actor'] ?? '';
        $objectUri = $activity['object'] ?? null;
        $sig = $activity['signature'] ?? null;
        if ($ar->existsByActivityId($id)) return ['status' => 'duplicate', 'message' => 'Already processed'];
        $ar->create($id, $nodeId, 'INCOMING', $type, $actorUri, $objectUri, parse_url($actorUri, PHP_URL_HOST), $activity, $sig);
        return ['status' => 'received', 'message' => "{$type} received"];
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
        return (int) $decoded;
    }

    // ---- Federation Node Identity & Discovery ----
            return [
                'id' => $row['public_id'],
                'actor' => [
                    'id' => $row['actor_public_id'], 'federated_address' => $row['federated_address'],
                    'display_name' => $row['actor_display_name'], 'avatar_url' => $row['actor_avatar_url'],
                    'canonical_url' => $row['actor_canonical_url'], 'node_domain' => $row['node_domain'], 'node_name' => $row['node_name'],
                ],
                'relationship_status' => $row['relationship_status'],
                'show_on_profile' => (bool) $row['show_on_profile'],
                'latest_post' => $latestPost !== null ? [
                    'id' => $latestPost['public_id'], 'title' => $latestPost['title'], 'content' => $latestPost['content'],
                    'canonical_url' => $latestPost['canonical_url'], 'published_at' => $latestPost['published_at'],
                ] : null,
                'accepted_at' => $row['accepted_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ];
        }, $rows);
    }