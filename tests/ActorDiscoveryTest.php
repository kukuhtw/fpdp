<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\HttpClient;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeRepository;
use App\Services\Federation\NodeDiscoveryService;

function ad_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Stands in for a real Mastodon-style server: the vanity URL
 * (/@handle) returns an actor document whose own `id` is the
 * different, canonical path (/users/handle) — exactly what real
 * Mastodon does, and exactly the case that broke actor resolution
 * before this fix (a strict "document.id === requested URL" check
 * rejected every legitimate Mastodon /@handle URL).
 */
final class FakeMastodonHttpClient extends HttpClient
{
    public function get(string $url, array $headers = [], ?int $timeout = null, ?int $maxSize = null): array
    {
        return $this->request('GET', $url, $headers, null, $timeout, $maxSize);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?int $timeout = null, ?int $maxSize = null): array
    {
        if ($url === 'https://mastodon.example/@kukuhtw') {
            return ['status' => 200, 'headers' => [], 'body' => json_encode([
                'id' => 'https://mastodon.example/users/kukuhtw',
                'type' => 'Person',
                'preferredUsername' => 'kukuhtw',
                'name' => 'Kukuh TW',
                'inbox' => 'https://mastodon.example/users/kukuhtw/inbox',
                'publicKey' => ['id' => 'https://mastodon.example/users/kukuhtw#main-key', 'publicKeyPem' => "-----BEGIN PUBLIC KEY-----\nFAKE\n-----END PUBLIC KEY-----"],
            ]), 'url' => $url];
        }

        if ($url === 'https://evil.example/@spoofed') {
            // A malicious/misconfigured server claiming an identity on a
            // DIFFERENT host than the one actually serving this response —
            // this must still be rejected.
            return ['status' => 200, 'headers' => [], 'body' => json_encode([
                'id' => 'https://mastodon.example/users/kukuhtw',
                'type' => 'Person',
                'preferredUsername' => 'kukuhtw',
                'inbox' => 'https://evil.example/inbox',
                'publicKey' => ['id' => 'https://evil.example/users/spoofed#main-key', 'publicKeyPem' => 'x'],
            ]), 'url' => $url];
        }

        return ['status' => 404, 'headers' => [], 'body' => '', 'url' => $url];
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-actordiscovery-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-actordiscovery-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE remote_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, status TEXT DEFAULT "ACTIVE", trust_state TEXT DEFAULT "UNKNOWN", last_seen_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT UNIQUE, federated_address TEXT UNIQUE, display_name TEXT, avatar_url TEXT, canonical_url TEXT, inbox_url TEXT, shared_inbox_url TEXT, public_key_id TEXT, public_key_pem TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

$actors = new RemoteActorRepository($db);
$discovery = new NodeDiscoveryService(new RemoteNodeRepository($db), $actors, new FakeMastodonHttpClient());

// ---- Test 1: a Mastodon /@handle vanity URL resolves via its canonical /users/handle id, not rejected as a mismatch ----
$resolved = $discovery->resolveActorByUri('https://mastodon.example/@kukuhtw');
ad_assert($resolved !== null, 'A vanity /@handle URL whose actor document canonicalizes to a different path should still resolve');
ad_assert($resolved['actor_uri'] === 'https://mastodon.example/users/kukuhtw', 'The cached actor_uri should be the canonical id, not the vanity URL requested: ' . json_encode($resolved));
ad_assert($resolved['inbox_url'] === 'https://mastodon.example/users/kukuhtw/inbox', 'inbox_url should be cached from the actor document');
ad_assert(str_contains((string) $resolved['public_key_pem'], 'FAKE'), 'public_key_pem should be cached from the actor document');

// ---- Test 2: resolving again finds the same cached row by its canonical id, no duplicate ----
$resolvedAgain = $discovery->resolveActorByUri('https://mastodon.example/users/kukuhtw');
ad_assert($resolvedAgain !== null && (int) $resolvedAgain['id'] === (int) $resolved['id'], 'Resolving by the canonical URI directly should hit the same cached row, not create a duplicate');
$count = (int) $db->query('SELECT COUNT(*) c FROM remote_actors')->fetch()['c'];
ad_assert($count === 1, "Expected exactly one cached remote_actors row, found {$count}");

// ---- Test 3: a server vouching for an identity on a DIFFERENT host is rejected (real spoofing case) ----
$spoofed = $discovery->resolveActorByUri('https://evil.example/@spoofed');
ad_assert($spoofed === null, 'An actor document whose id is on a different host than the one that served it must be rejected as spoofed');

Database::reset();
unset($db);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Actor discovery test passed\n");
