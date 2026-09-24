<?php

declare(strict_types=1);

namespace App\Services\Federation;

/**
 * Pure data shaping for outgoing ActivityPub documents (Actor, WebFinger,
 * collections). No I/O — building the JSON-LD shapes real Fediverse
 * servers (Mastodon included) expect is entirely mechanical once you have
 * the profile row and domain; the interesting work (fetching/signing/
 * verifying) lives in NodeDiscoveryService and HttpSignature.
 */
final class ActivityPubPresenter
{
    private const CONTEXT = ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'];

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function actor(array $profile, string $domain, string $publicKeyPem): array
    {
        $actorUri = self::actorUri($domain, (string) $profile['handle']);

        $document = [
            '@context' => self::CONTEXT,
            'id' => $actorUri,
            'type' => 'Person',
            'preferredUsername' => $profile['handle'],
            'name' => $profile['display_name'],
            'summary' => (string) ($profile['bio'] ?? ''),
            'url' => $actorUri,
            'inbox' => $actorUri . '/inbox',
            'outbox' => $actorUri . '/outbox',
            'followers' => $actorUri . '/followers',
            'following' => $actorUri . '/following',
            'publicKey' => [
                'id' => $actorUri . '#main-key',
                'owner' => $actorUri,
                'publicKeyPem' => $publicKeyPem,
            ],
        ];

        if (!empty($profile['avatar_url'])) {
            $document['icon'] = ['type' => 'Image', 'url' => $profile['avatar_url']];
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public static function webfinger(string $handle, string $domain): array
    {
        $actorUri = self::actorUri($domain, $handle);

        return [
            'subject' => "acct:{$handle}@{$domain}",
            'aliases' => [$actorUri],
            'links' => [
                ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actorUri],
                ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => $actorUri],
            ],
        ];
    }

    /**
     * Minimal (non-paginated) OrderedCollection — adequate for the counts
     * Mastodon and other implementations display; not paginated since a
     * personal node's follower/following counts don't need it.
     *
     * @param array<int, string> $itemUris
     * @return array<string, mixed>
     */
    public static function orderedCollection(string $collectionUri, array $itemUris): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $collectionUri,
            'type' => 'OrderedCollection',
            'totalItems' => count($itemUris),
            'orderedItems' => $itemUris,
        ];
    }

    public static function actorUri(string $domain, string $handle): string
    {
        return "https://{$domain}/@{$handle}";
    }
}
