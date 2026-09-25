<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;
use App\Services\Federation\HttpSignature;

function mutual_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-mutual-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-mutual-test-' . uniqid() . '.env';
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
    'CREATE TABLE federated_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, relationship_status TEXT DEFAULT "PENDING", show_on_profile INTEGER DEFAULT 1, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, canonical_url TEXT, title TEXT, content TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
    'CREATE TABLE node_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER, key_type TEXT DEFAULT "rsa", public_key TEXT, private_key TEXT, fingerprint TEXT UNIQUE, is_current INTEGER DEFAULT 1, rotated_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE follows (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, target_actor_uri TEXT, target_federated_address TEXT, status TEXT DEFAULT "PENDING", direction TEXT DEFAULT "OUTGOING", activity_public_id TEXT, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (profile_id, target_actor_uri, direction))',
    'CREATE TABLE federation_activities (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, direction TEXT, activity_type TEXT, actor_uri TEXT, object_uri TEXT, target_node_domain TEXT, target_actor_uri TEXT, payload TEXT, signature TEXT, status TEXT DEFAULT "PENDING", retry_count INTEGER DEFAULT 0, last_error TEXT, next_attempt_at TIMESTAMP, delivered_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

/** @return array{status: int, body: mixed} */
$dispatch = static function (string $method, string $path, ?string $rawBody = null, array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, [], $rawBody, $headers));
    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

/** @return array{privateKey: string, actorUri: string, keyId: string} */
function mutual_seed_remote_actor(PDO $db, string $handle, string $domain): array
{
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    $publicKeyPem = openssl_pkey_get_details($keyPair)['key'];

    $actorUri = "https://{$domain}/users/{$handle}";
    $keyId = $actorUri . '#main-key';

    $db->exec('INSERT INTO remote_nodes (public_id, domain) VALUES (\'' . Uuid::v4() . '\', "' . $domain . '")');
    $remoteNodeId = (int) $db->lastInsertId();

    $stmt = $db->prepare(
        'INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, inbox_url, public_key_id, public_key_pem, fetched_at)
         VALUES (:pid, :node_id, :actor_uri, :fed_addr, :name, :inbox, :key_id, :pem, CURRENT_TIMESTAMP)',
    );
    $stmt->execute([
        'pid' => Uuid::v4(), 'node_id' => $remoteNodeId, 'actor_uri' => $actorUri, 'fed_addr' => "@{$handle}@{$domain}",
        'name' => ucfirst($handle), 'inbox' => $actorUri . '/inbox', 'key_id' => $keyId, 'pem' => $publicKeyPem,
    ]);

    return ['privateKey' => $privateKey, 'actorUri' => $actorUri, 'keyId' => $keyId];
}

/** @return array{headers: array<string, string>, body: string} */
function mutual_sign_request(string $privateKeyPem, string $keyId, string $method, string $path, string $host, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = ['host' => $host, 'date' => HttpSignature::httpDate(), 'digest' => HttpSignature::digestHeader($body)];
    $signingString = HttpSignature::buildSigningString($method, $path, $headers, HttpSignature::DEFAULT_SIGNED_HEADERS);

    $signature = '';
    openssl_sign($signingString, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
    $headers['signature'] = HttpSignature::buildSignatureHeader($keyId, HttpSignature::DEFAULT_SIGNED_HEADERS, base64_encode($signature));

    return ['headers' => $headers, 'body' => $body];
}

// ---- Register the local owner ----
$register = $dispatch('POST', '/api/v1/auth/register', json_encode([
    'email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner',
]));
mutual_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];
$profileId = (int) $db->query('SELECT id FROM profiles LIMIT 1')->fetchColumn();

// ---- Seed the remote actor and an OUTGOING, already-ACCEPTED follow to them (we follow them, confirmed) ----
$remote = mutual_seed_remote_actor($db, 'alice', 'friend.example');
$outgoingStmt = $db->prepare(
    "INSERT INTO follows (public_id, profile_id, target_actor_uri, status, direction, accepted_at)
     VALUES (:pid, :profile_id, :target_uri, 'ACCEPTED', 'OUTGOING', CURRENT_TIMESTAMP)",
);
$outgoingStmt->execute(['pid' => Uuid::v4(), 'profile_id' => $profileId, 'target_uri' => $remote['actorUri']]);

// ---- Test: the SAME actor now sends US a real inbound Follow (mutual-follow scenario) ----
$inboxPath = '/@owner/inbox';
$followPayload = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => 'https://friend.example/activities/' . Uuid::v4(),
    'type' => 'Follow',
    'actor' => $remote['actorUri'],
    'object' => 'https://test.local/@owner',
    'published' => gmdate('c'),
];
$signed = mutual_sign_request($remote['privateKey'], $remote['keyId'], 'POST', $inboxPath, 'test.local', $followPayload);
$result = $dispatch('POST', $inboxPath, $signed['body'], $signed['headers']);
mutual_assert($result['status'] === 202, 'Inbound Follow should be accepted: ' . json_encode($result));
mutual_assert(
    $result['body']['data']['status'] === 'pending',
    'A Follow from someone we already follow (OUTGOING/ACCEPTED) must still create a new INCOMING pending '
    . 'request, not be swallowed as "already following": ' . json_encode($result),
);

// ---- Verify: a genuine INCOMING PENDING row now exists, separate from the OUTGOING ACCEPTED one ----
$incoming = $db->query(
    "SELECT * FROM follows WHERE target_actor_uri = " . $db->quote($remote['actorUri']) . " AND direction = 'INCOMING'",
)->fetch();
mutual_assert($incoming !== false, 'An INCOMING follow row should have been created');
mutual_assert($incoming['status'] === 'PENDING', 'The INCOMING row should be PENDING, awaiting owner approval: ' . json_encode($incoming));

$outgoing = $db->query(
    "SELECT * FROM follows WHERE target_actor_uri = " . $db->quote($remote['actorUri']) . " AND direction = 'OUTGOING'",
)->fetch();
mutual_assert($outgoing['status'] === 'ACCEPTED', 'The pre-existing OUTGOING row must be untouched: ' . json_encode($outgoing));

$rowCount = (int) $db->query('SELECT COUNT(*) FROM follows')->fetchColumn();
mutual_assert($rowCount === 2, "Expected exactly 2 follow rows (one OUTGOING, one INCOMING), found {$rowCount}");

// ---- Verify: the dashboard's pending-requests listing actually surfaces it ----
$pending = $dispatch('GET', '/api/v1/me/federation/follow-requests', null, ['authorization' => 'Bearer ' . $ownerToken]);
mutual_assert(
    $pending['status'] === 200 && count($pending['body']['data']['follow_requests']) === 1,
    'The owner should see exactly one pending follow request from the mutual-follow actor: ' . json_encode($pending),
);

Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Mutual follow test passed\n");
