<?php

declare(strict_types=1);

namespace App\Core\Http;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Maps internal database rows (auto-increment ids, raw timestamps) onto the
 * public JSON shapes defined in documentation/openapi.yaml.
 */
final class ResourcePresenter
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function user(array $user): array
    {
        return [
            'id' => $user['public_id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'created_at' => self::timestamp((string) $user['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public static function node(array $node): array
    {
        return [
            'id' => $node['public_id'],
            'domain' => $node['domain'],
            'status' => $node['status'],
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function profile(array $profile): array
    {
        $links = json_decode((string) ($profile['links'] ?? '[]'), true);

        return [
            'id' => $profile['public_id'],
            'handle' => $profile['handle'],
            'display_name' => $profile['display_name'],
            'bio' => $profile['bio'],
            'avatar_url' => $profile['avatar_url'],
            'links' => is_array($links) ? $links : [],
            'visibility' => $profile['visibility'],
            'canonical_url' => sprintf('https://%s/@%s', $profile['node_domain'], $profile['handle']),
        ];
    }

    public static function post(array $post): array
    {
        return [
            'id' => $post['public_id'],
            'title' => $post['title'],
            'content' => $post['content'],
            'post_type' => $post['post_type'],
            'source_type' => 'LOCAL',
            'source_provider' => 'FPDP',
            'canonical_url' => sprintf('https://%s/posts/%s', $post['node_domain'], $post['public_id']),
            'author' => [
                'handle' => $post['handle'],
                'display_name' => $post['display_name'],
                'profile_url' => sprintf('https://%s/@%s', $post['node_domain'], $post['handle']),
                'avatar_url' => $post['avatar_url'],
            ],
            'media' => [],
            'visibility' => $post['visibility'],
            'published_at' => $post['published_at'] === null ? null : self::timestamp((string) $post['published_at']),
            'updated_at' => self::timestamp((string) $post['updated_at']),
        ];
    }

    private static function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
