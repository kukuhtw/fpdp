<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;
use App\Services\Federation\HttpSignature;

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
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", capabilities TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, status TEXT DEFAULT "ACTIVE", trust_state TEXT DEFAULT "UNKNOWN", last_seen_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT, federated_address TEXT UNIQUE, display_name TEXT, avatar_url TEXT, canonical_url TEXT, inbox_url TEXT, shared_inbox_url TEXT, public_key_id TEXT, public_key_pem TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, relationship_status TEXT DEFAULT "PENDING", show_on_profile INTEGER DEFAULT 1, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (profile_id, remote_actor_id))',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, canonical_url TEXT, title TEXT, content TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
    'CREATE TABLE node_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER, key_type TEXT DEFAULT "rsa", public_key TEXT, private_key TEXT, fingerprint TEXT UNIQUE, is_current INTEGER DEFAULT 1, rotated_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE follows (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, target_actor_uri TEXT, target_federated_address TEXT, status TEXT DEFAULT "PENDING", direction TEXT DEFAULT "OUTGOING", activity_public_id TEXT, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federation_activities (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, direction TEXT, activity_type TEXT, actor_uri TEXT, object_uri TEXT, target_node_domain TEXT, target_actor_uri TEXT, payload TEXT, signature TEXT, status TEXT DEFAULT "PENDING", retry_count INTEGER DEFAULT 0, last_error TEXT, next_attempt_at TIMESTAMP, delivered_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

/**
 * @param array<string, string> $headers
 * @return array{status: int, body: mixed, contentType: ?string}
 */
$dispatch = static function (string $method, string $path, ?string $rawBody = null, array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, [], $rawBody, $headers));

    return [
        'status' => $response->status,
        'body' => $response->body === '' ? null : json_decode($response->body, true),
        'contentType' => $response->headers['Content-Type'] ?? null,
    ];
};

/**
 * Simulates a remote Fediverse server's actor: a real RSA keypair (as
 * Mastodon would have) cached directly into remote_actors — bypassing the
 * actual WebFinger/actor-fetch HTTP calls, which is the part real network
 * access would be needed for, not the part this test is verifying.
 *
 * @return array{privateKey: string, publicKeyPem: string, actorUri: string, keyId: string, inboxUrl: string}
 */
function finbox_seed_remote_actor(PDO $db, string $handle, string $domain, string $trustState = 'UNKNOWN'): array
{
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    $publicKeyPem = openssl_pkey_get_details($keyPair)['key'];

    $actorUri = "https://{$domain}/users/{$handle}";
    $keyId = $actorUri . '#main-key';
    $inboxUrl = $actorUri . '/inbox';

    $nodeStmt = $db->prepare('INSERT INTO remote_nodes (public_id, domain, trust_state) VALUES (:pid, :domain, :trust)');
    $nodeStmt->execute(['pid' => Uuid::v4(), 'domain' => $domain, 'trust' => $trustState]);
    $nodeId = (int) $db->lastInsertId();

    $actorStmt = $db->prepare(
        'INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, inbox_url, public_key_id, public_key_pem, fetched_at)
         VALUES (:pid, :node_id, :actor_uri, :fed_addr, :name, :inbox, :key_id, :pem, CURRENT_TIMESTAMP)',
    );
    $actorStmt->execute([
        'pid' => Uuid::v4(),
        'node_id' => $nodeId,
        'actor_uri' => $actorUri,
        'fed_addr' => "@{$handle}@{$domain}",
        'name' => ucfirst($handle),
        'inbox' => $inboxUrl,
        'key_id' => $keyId,
        'pem' => $publicKeyPem,
    ]);

    return ['privateKey' => $privateKey, 'publicKeyPem' => $publicKeyPem, 'actorUri' => $actorUri, 'keyId' => $keyId, 'inboxUrl' => $inboxUrl];
}

/**
 * Builds a real, correctly HTTP-Signature-signed inbound request — exactly
 * the shape Mastodon actually sends — for the given body to the given path.
 *
 * @return array{headers: array<string, string>, body: string}
 */
function finbox_sign_request(string $privateKeyPem, string $keyId, string $method, string $path, string $host, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = [
        'host' => $host,
        'date' => HttpSignature::httpDate(),
        'digest' => HttpSignature::digestHeader($body),
    ];
    $signingString = HttpSignature::buildSigningString($method, $path, $headers, HttpSignature::DEFAULT_SIGNED_HEADERS);

    $signature = '';
    openssl_sign($signingString, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
    $headers['signature'] = HttpSignature::buildSignatureHeader($keyId, HttpSignature::DEFAULT_SIGNED_HEADERS, base64_encode($signature));

    return ['headers' => $headers, 'body' => $body];
}

// ---- Register the local owner: profile handle "owner" at domain test.local ----
$register = $dispatch('POST', '/api/v1/auth/register', json_encode([
    'email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner',
]));
finbox_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];
$inboxPath = '/@owner/inbox';

// ---- Test 1: Actor document is real ActivityPub JSON-LD with an RSA publicKeyPem ----
$actorDoc = $dispatch('GET', '/@owner', null, ['accept' => 'application/activity+json']);
finbox_assert($actorDoc['status'] === 200, 'Actor document request failed: ' . json_encode($actorDoc));
finbox_assert($actorDoc['contentType'] === 'application/activity+json', 'Actor document should be served as application/activity+json');
finbox_assert($actorDoc['body']['type'] === 'Person', 'Actor document type should be Person');
finbox_assert($actorDoc['body']['id'] === 'https://test.local/@owner', 'Actor id should be the profile URL');
finbox_assert($actorDoc['body']['inbox'] === 'https://test.local/@owner/inbox', 'Actor inbox should be the per-actor inbox URL');
finbox_assert(str_starts_with((string) $actorDoc['body']['publicKey']['publicKeyPem'], '-----BEGIN PUBLIC KEY-----'), 'Actor publicKeyPem should be a real PEM key');
finbox_assert($actorDoc['body']['publicKey']['id'] === 'https://test.local/@owner#main-key', 'publicKey id should match the keyId used for signing');

// ---- Test 2: WebFinger resolves acct:owner@test.local to the actor URI ----
$webfinger = $dispatch('GET', '/.well-known/webfinger?resource=' . rawurlencode('acct:owner@test.local'));
finbox_assert($webfinger['status'] === 200, 'WebFinger lookup failed: ' . json_encode($webfinger));
finbox_assert($webfinger['contentType'] === 'application/jrd+json', 'WebFinger response should be application/jrd+json');
$selfLink = current(array_filter($webfinger['body']['links'], fn ($l) => $l['rel'] === 'self'));
finbox_assert($selfLink !== false && $selfLink['href'] === 'https://test.local/@owner', 'WebFinger self link should point at the actor URI');

// ---- Test 3: a validly HTTP-Signature-signed Follow is accepted and verified ----
$remote = finbox_seed_remote_actor($db, 'alice', 'sender.example');
$followActivityId = 'https://sender.example/activities/' . Uuid::v4();
$followPayload = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => $followActivityId,
    'type' => 'Follow',
    'actor' => $remote['actorUri'],
    'object' => 'https://test.local/@owner',
    'published' => gmdate('c'),
];
$signed = finbox_sign_request($remote['privateKey'], $remote['keyId'], 'POST', $inboxPath, 'test.local', $followPayload);
$follow1 = $dispatch('POST', $inboxPath, $signed['body'], $signed['headers']);
finbox_assert($follow1['status'] === 202, 'Signed Follow should be accepted: ' . json_encode($follow1));
finbox_assert($follow1['body']['data']['verified'] === true, 'Correctly signed Follow should verify: ' . json_encode($follow1));
finbox_assert($follow1['body']['data']['status'] === 'pending', 'A new inbound Follow should be left PENDING for manual approval, not auto-accepted');

$storedActivity = $db->query("SELECT status FROM federation_activities WHERE public_id = " . $db->quote($followActivityId))->fetch();
finbox_assert($storedActivity['status'] === 'VERIFIED', 'A verified Follow should be persisted with status VERIFIED');

// ---- Test 4: the same signature replayed against a different (tampered) body is rejected — digest mismatch ----
$tamperedBody = str_replace('Follow', 'FollowXX', $signed['body']);
$tampered = $dispatch('POST', $inboxPath, $tamperedBody, $signed['headers']);
finbox_assert($tampered['status'] === 403, 'A body that no longer matches the signed Digest header should be rejected: ' . json_encode($tampered));

// ---- Test 5: a signature made with the WRONG private key is rejected ----
$otherRemote = finbox_seed_remote_actor($db, 'mallory', 'attacker.example');
$forgedActivityId = 'https://sender.example/activities/' . Uuid::v4();
$forgedPayload = ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $forgedActivityId, 'type' => 'Follow', 'actor' => $remote['actorUri'], 'object' => 'https://test.local/@owner', 'published' => gmdate('c')];
// Signed with mallory's key but claiming to be alice's keyId — signature won't verify against alice's real public key.
$forged = finbox_sign_request($otherRemote['privateKey'], $remote['keyId'], 'POST', $inboxPath, 'test.local', $forgedPayload);
$forgedResult = $dispatch('POST', $inboxPath, $forged['body'], $forged['headers']);
finbox_assert($forgedResult['status'] === 403, 'A signature made with the wrong private key should be rejected: ' . json_encode($forgedResult));

// ---- Test 6: an unsigned Follow from a known, non-blocked domain is still accepted, but unverified ----
$unsignedId = 'https://sender.example/activities/' . Uuid::v4();
$unsignedPayload = ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $unsignedId, 'type' => 'Follow', 'actor' => $remote['actorUri'], 'object' => 'https://test.local/@owner', 'published' => gmdate('c')];
$unsigned = $dispatch('POST', $inboxPath, json_encode($unsignedPayload), ['host' => 'test.local']);
finbox_assert($unsigned['status'] === 202, 'Unsigned Follow should still be accepted: ' . json_encode($unsigned));
finbox_assert($unsigned['body']['data']['verified'] === false, 'Unsigned Follow should be marked unverified');

// ---- Test 7: a Follow from a BLOCKED domain is rejected before signature verification ----
finbox_seed_remote_actor($db, 'evil', 'blocked.example', 'BLOCKED');
$blockedPayload = ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => 'https://blocked.example/activities/' . Uuid::v4(), 'type' => 'Follow', 'actor' => 'https://blocked.example/users/evil', 'object' => 'https://test.local/@owner', 'published' => gmdate('c')];
$blocked = $dispatch('POST', $inboxPath, json_encode($blockedPayload), ['host' => 'test.local']);
finbox_assert($blocked['status'] === 403, 'A Follow from a blocked domain should be rejected: ' . json_encode($blocked));

// ---- Test 8: duplicate activity id is a no-op ----
$duplicate = $dispatch('POST', $inboxPath, $signed['body'], $signed['headers']);
finbox_assert($duplicate['body']['data']['status'] === 'duplicate', 'Replaying the same activity id should be idempotent: ' . json_encode($duplicate));

// ---- Test 9: owner can list and approve the pending follow request, sending a spec-shaped Accept ----
$pendingList = $dispatch('GET', '/api/v1/me/federation/follow-requests', null, ['authorization' => 'Bearer ' . $ownerToken]);
finbox_assert($pendingList['status'] === 200 && count($pendingList['body']['data']['follow_requests']) === 1, 'Owner should see exactly one pending follow request: ' . json_encode($pendingList));
$followRequestId = $pendingList['body']['data']['follow_requests'][0]['id'];

$approve = $dispatch('POST', "/api/v1/me/federation/follow-requests/{$followRequestId}/approve", null, ['authorization' => 'Bearer ' . $ownerToken]);
finbox_assert($approve['status'] === 200 && $approve['body']['data']['status'] === 'accepted', 'Approving the follow request should succeed: ' . json_encode($approve));

$outgoingAccept = $db->query("SELECT payload, target_actor_uri FROM federation_activities WHERE direction = 'OUTGOING' AND activity_type = 'Accept' ORDER BY id DESC LIMIT 1")->fetch();
finbox_assert($outgoingAccept !== false, 'Approving should queue an outgoing Accept activity');
$acceptPayload = json_decode($outgoingAccept['payload'], true);
finbox_assert($acceptPayload['@context'] === 'https://www.w3.org/ns/activitystreams', 'Outgoing Accept should use the real ActivityStreams context, not the old FPDP-proprietary one');
finbox_assert(is_array($acceptPayload['object']) && $acceptPayload['object']['type'] === 'Follow' && $acceptPayload['object']['id'] === $followActivityId, 'Accept.object must embed the original Follow activity per spec, not a bare id: ' . json_encode($acceptPayload));
finbox_assert($outgoingAccept['target_actor_uri'] === $remote['actorUri'], 'The queued Accept should target the follower\'s actor URI for delivery');

$connectionRow = $db->query('SELECT relationship_status FROM federated_connections')->fetch();
finbox_assert($connectionRow !== false && $connectionRow['relationship_status'] === 'CONNECTED', 'Approving should create a CONNECTED federated_connections row');

Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Federation inbox test passed\n");
