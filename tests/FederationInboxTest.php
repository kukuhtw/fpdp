<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

function finbox_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-fedinbox-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-fedinbox-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, status TEXT DEFAULT "ACTIVE", trust_state TEXT DEFAULT "UNKNOWN", last_seen_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_node_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, remote_node_id INTEGER UNIQUE, key_type TEXT DEFAULT "ed25519", public_key TEXT, fingerprint TEXT UNIQUE, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT, federated_address TEXT UNIQUE, display_name TEXT, avatar_url TEXT, canonical_url TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, relationship_status TEXT DEFAULT "PENDING", show_on_profile INTEGER DEFAULT 1, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (profile_id, remote_actor_id))',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, canonical_url TEXT, title TEXT, content TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
    'CREATE TABLE node_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER, key_type TEXT DEFAULT "ed25519", public_key TEXT, private_key TEXT, fingerprint TEXT UNIQUE, is_current INTEGER DEFAULT 1, rotated_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE follows (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, target_actor_uri TEXT, target_federated_address TEXT, status TEXT DEFAULT "PENDING", activity_public_id TEXT, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federation_activities (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, direction TEXT, activity_type TEXT, actor_uri TEXT, object_uri TEXT, target_node_domain TEXT, payload TEXT, signature TEXT, status TEXT DEFAULT "PENDING", retry_count INTEGER DEFAULT 0, last_error TEXT, next_attempt_at TIMESTAMP, delivered_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$dispatch = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($router): array {
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $headers));
    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

function finbox_seed_remote_node(PDO $db, string $domain, string $trustState, ?string $publicKey): int
{
    $publicId = 'rn-' . bin2hex(random_bytes(4));
    $stmt = $db->prepare('INSERT INTO remote_nodes (public_id, domain, name, status, trust_state) VALUES (:pid, :domain, :name, "ACTIVE", :trust)');
    $stmt->execute(['pid' => $publicId, 'domain' => $domain, 'name' => $domain, 'trust' => $trustState]);
    $nodeId = (int) $db->lastInsertId();

    if ($publicKey !== null) {
        $stmt = $db->prepare('INSERT INTO remote_node_keys (remote_node_id, key_type, public_key, fingerprint, fetched_at) VALUES (:id, "ed25519", :pk, :fp, CURRENT_TIMESTAMP)');
        $stmt->execute(['id' => $nodeId, 'pk' => $publicKey, 'fp' => hash('sha256', $publicKey . $nodeId)]);
    }

    return $nodeId;
}

// Register the local owner ("us"): profile handle "owner" at domain test.local
$register = $dispatch('POST', '/api/v1/auth/register', [
    'email' => 'owner@test.local',
    'password' => 'correct horse battery',
    'handle' => 'owner',
    'display_name' => 'Owner',
]);
finbox_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];
$ownerProfileId = (int) $db->query("SELECT id FROM profiles WHERE handle = 'owner'")->fetch()['id'];

// ---- Test 1: valid signed Follow is verified, auto-accepted, and creates a connection ----
$kp = sodium_crypto_sign_keypair();
$secretKey = sodium_crypto_sign_secretkey($kp);
$publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($kp));
finbox_seed_remote_node($db, 'sender.example', 'UNKNOWN', $publicKeyB64);

$followPayload = [
    '@context' => 'https://fpdp.dev/ns/federation/v1',
    'id' => 'act-follow-1',
    'type' => 'Follow',
    'actor' => 'https://sender.example/@alice',
    'object' => 'https://owner.test.local/@owner',
    'published' => gmdate('c'),
];
$signature = base64_encode(sodium_crypto_sign_detached(json_encode($followPayload), $secretKey));
$followPayload['signature'] = $signature;

$result1 = $dispatch('POST', '/api/v1/federation/inbox', $followPayload);
finbox_assert($result1['status'] === 202, 'Signed Follow should be accepted: ' . json_encode($result1));
finbox_assert($result1['body']['data']['verified'] === true, 'Signed Follow should be marked verified');
finbox_assert($result1['body']['data']['status'] === 'accepted', 'Signed Follow should be auto-accepted');

$followRow = $db->query("SELECT * FROM follows WHERE target_actor_uri = 'https://sender.example/@alice'")->fetch();
finbox_assert($followRow !== false && $followRow['status'] === 'ACCEPTED', 'Follow row should be ACCEPTED');

$connRow = $db->query("SELECT fc.* FROM federated_connections fc INNER JOIN remote_actors ra ON ra.id = fc.remote_actor_id WHERE ra.actor_uri = 'https://sender.example/@alice'")->fetch();
finbox_assert($connRow !== false && $connRow['relationship_status'] === 'CONNECTED', 'Connection should be CONNECTED after signed Follow');

$activityRow = $db->query("SELECT * FROM federation_activities WHERE public_id = 'act-follow-1'")->fetch();
finbox_assert($activityRow !== false && $activityRow['status'] === 'VERIFIED', 'Activity should be stored as VERIFIED');

// ---- Test 2: tampered signature is rejected, no state changes ----
$tamperedPayload = $followPayload;
$tamperedPayload['id'] = 'act-follow-2';
$tamperedPayload['signature'] = substr($signature, 0, -4) . 'XXXX';

$result2 = $dispatch('POST', '/api/v1/federation/inbox', $tamperedPayload);
finbox_assert($result2['status'] === 403, 'Tampered signature should be rejected with 403: ' . json_encode($result2));

$noActivity = $db->query("SELECT * FROM federation_activities WHERE public_id = 'act-follow-2'")->fetch();
finbox_assert($noActivity === false, 'Rejected activity should not be persisted');

// ---- Test 3: blocked sender domain is rejected before any discovery/verification ----
finbox_seed_remote_node($db, 'blocked-sender.example', 'BLOCKED', null);
$blockedPayload = [
    'id' => 'act-follow-3',
    'type' => 'Follow',
    'actor' => 'https://blocked-sender.example/@mallory',
    'object' => 'https://owner.test.local/@owner',
];
$result3 = $dispatch('POST', '/api/v1/federation/inbox', $blockedPayload);
finbox_assert($result3['status'] === 403, 'Blocked domain should be rejected with 403: ' . json_encode($result3));

// ---- Test 4: unsigned Follow from a known, non-blocked domain is still processed (marked UNSIGNED) ----
finbox_seed_remote_node($db, 'open-sender.example', 'UNKNOWN', null);
$unsignedPayload = [
    'id' => 'act-follow-4',
    'type' => 'Follow',
    'actor' => 'https://open-sender.example/@dave',
    'object' => 'https://owner.test.local/@owner',
];
$result4 = $dispatch('POST', '/api/v1/federation/inbox', $unsignedPayload);
finbox_assert($result4['status'] === 202, 'Unsigned Follow from known domain should still be processed: ' . json_encode($result4));
finbox_assert($result4['body']['data']['verified'] === false, 'Unsigned Follow should be marked unverified');

$unsignedActivity = $db->query("SELECT * FROM federation_activities WHERE public_id = 'act-follow-4'")->fetch();
finbox_assert($unsignedActivity !== false && $unsignedActivity['status'] === 'UNSIGNED', 'Unsigned activity should be stored with status UNSIGNED');

// ---- Test 5: full send-follow -> inbound Accept round trip ----
finbox_seed_remote_node($db, 'remote-target.example', 'UNKNOWN', null);
$targetNodeId = (int) $db->query("SELECT id FROM remote_nodes WHERE domain = 'remote-target.example'")->fetch()['id'];
$db->exec("INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name)
           VALUES ('ra-bob', {$targetNodeId}, 'https://remote-target.example/@bob', '@bob@remote-target.example', 'Bob')");
$bobActorId = (int) $db->lastInsertId();

$sendFollow = $dispatch('POST', '/api/v1/federation/send-follow', [
    'target_actor_uri' => 'https://remote-target.example/@bob',
    'target_domain' => 'remote-target.example',
    'target_federated_address' => '@bob@remote-target.example',
], $ownerToken);
finbox_assert($sendFollow['status'] === 201, 'send-follow should succeed: ' . json_encode($sendFollow));
$sentActivityId = $sendFollow['body']['data']['activity_id'];

$acceptPayload = [
    'id' => 'act-accept-1',
    'type' => 'Accept',
    'actor' => 'https://remote-target.example/@bob',
    'object' => $sentActivityId,
];
$resultAccept = $dispatch('POST', '/api/v1/federation/inbox', $acceptPayload);
finbox_assert($resultAccept['status'] === 202, 'Inbound Accept should be processed: ' . json_encode($resultAccept));
finbox_assert($resultAccept['body']['data']['status'] === 'accepted', 'Accept should report accepted');

$followAfterAccept = $db->query("SELECT * FROM follows WHERE activity_public_id = '{$sentActivityId}'")->fetch();
finbox_assert($followAfterAccept !== false && $followAfterAccept['status'] === 'ACCEPTED', 'Follow should be ACCEPTED after remote Accept');

$connAfterAccept = $db->query("SELECT * FROM federated_connections WHERE profile_id = {$ownerProfileId} AND remote_actor_id = {$bobActorId}")->fetch();
finbox_assert($connAfterAccept !== false && $connAfterAccept['relationship_status'] === 'CONNECTED', 'Connection to Bob should be CONNECTED after Accept');

// ---- Test 6: duplicate activity id is a no-op ----
$dup = $dispatch('POST', '/api/v1/federation/inbox', $followPayload);
finbox_assert($dup['status'] === 200 || $dup['status'] === 202, 'Duplicate activity should not error: ' . json_encode($dup));
finbox_assert($dup['body']['data']['status'] === 'duplicate', 'Replayed activity id should be reported as duplicate');

// ---- Test 7: activity with a stale/out-of-window timestamp is rejected ----
finbox_seed_remote_node($db, 'stale-sender.example', 'UNKNOWN', null);
$stalePayload = [
    'id' => 'act-follow-stale',
    'type' => 'Follow',
    'actor' => 'https://stale-sender.example/@eve',
    'object' => 'https://owner.test.local/@owner',
    'published' => gmdate('c', time() - 3600),
];
$staleResult = $dispatch('POST', '/api/v1/federation/inbox', $stalePayload);
finbox_assert($staleResult['status'] === 403, 'Stale-timestamp activity should be rejected with 403: ' . json_encode($staleResult));

// ---- Test 8: remote-node moderation list requires auth and reflects seeded nodes ----
$unauthList = $dispatch('GET', '/api/v1/me/federation/remote-nodes');
finbox_assert($unauthList['status'] === 401, 'Remote-node list should require auth');

$modList = $dispatch('GET', '/api/v1/me/federation/remote-nodes', null, $ownerToken);
finbox_assert($modList['status'] === 200, 'Remote-node list should succeed for an authenticated owner: ' . json_encode($modList));
$senderNode = current(array_filter($modList['body']['data']['remote_nodes'], fn ($n) => $n['domain'] === 'sender.example'));
finbox_assert($senderNode !== false, 'Moderation list should include previously-discovered sender.example');

// ---- Test 9: owner can block a remote node's domain, which then takes effect on the inbox ----
$blockResult = $dispatch('PATCH', '/api/v1/me/federation/remote-nodes/open-sender.example/trust', ['trust_state' => 'BLOCKED'], $ownerToken);
finbox_assert($blockResult['status'] === 200, 'Blocking a remote node should succeed: ' . json_encode($blockResult));
finbox_assert($blockResult['body']['data']['trust_state'] === 'BLOCKED', 'Remote node should now be BLOCKED');

$afterBlock = $dispatch('POST', '/api/v1/federation/inbox', [
    'id' => 'act-follow-after-block',
    'type' => 'Follow',
    'actor' => 'https://open-sender.example/@dave',
    'object' => 'https://owner.test.local/@owner',
]);
finbox_assert($afterBlock['status'] === 403, 'Activity from a newly-blocked domain should be rejected: ' . json_encode($afterBlock));

$invalidTrust = $dispatch('PATCH', '/api/v1/me/federation/remote-nodes/open-sender.example/trust', ['trust_state' => 'NOPE'], $ownerToken);
finbox_assert($invalidTrust['status'] === 422, 'Invalid trust_state should 422');

$unknownDomainTrust = $dispatch('PATCH', '/api/v1/me/federation/remote-nodes/never-seen.example/trust', ['trust_state' => 'TRUSTED'], $ownerToken);
finbox_assert($unknownDomainTrust['status'] === 404, 'Unknown remote-node domain should 404');

// Cleanup
Database::reset();
unset($db);
unlink($envPath);
unlink($dbPath);
fwrite(STDOUT, "Federation inbox test passed\n");
