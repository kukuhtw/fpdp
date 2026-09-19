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