<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;

function fpub_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-fedpub-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-fedpub-test-' . uniqid() . '.env';
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
    'CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, slug TEXT, user_id INTEGER, profile_id INTEGER, title TEXT, content TEXT, post_type TEXT DEFAULT "NOTE", visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, deleted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE post_media (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, media_type TEXT, url TEXT, alt_text TEXT, sort_order INTEGER DEFAULT 0)',
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
 * @return array{status: int, body: mixed}
 */
$dispatch = static function (string $method, string $path, ?string $rawBody = null, array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, [], $rawBody, $headers));
    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

// ---- Register the local owner and seed an accepted follower (INCOMING) ----
$register = $dispatch('POST', '/api/v1/auth/register', json_encode([
    'email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner',
]));
fpub_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];
$profileId = (int) $db->query('SELECT id FROM profiles LIMIT 1')->fetchColumn();
$authHeader = ['authorization' => 'Bearer ' . $ownerToken];

$nodeStmt = $db->prepare('INSERT INTO remote_nodes (public_id, domain) VALUES (:pid, "follower.example")');
$nodeStmt->execute(['pid' => Uuid::v4()]);
$remoteNodeId = (int) $db->lastInsertId();

$followerActorUri = 'https://follower.example/users/fan';
$actorStmt = $db->prepare(
    'INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, inbox_url)
     VALUES (:pid, :node_id, :actor_uri, "@fan@follower.example", "Fan", :inbox)',
);
$actorStmt->execute(['pid' => Uuid::v4(), 'node_id' => $remoteNodeId, 'actor_uri' => $followerActorUri, 'inbox' => $followerActorUri . '/inbox']);
$remoteActorId = (int) $db->lastInsertId();

$followStmt = $db->prepare(
    "INSERT INTO follows (public_id, profile_id, remote_actor_id, target_actor_uri, status, direction, accepted_at)
     VALUES (:pid, :profile_id, :actor_id, :target_uri, 'ACCEPTED', 'INCOMING', CURRENT_TIMESTAMP)",
);
$followStmt->execute(['pid' => Uuid::v4(), 'profile_id' => $profileId, 'actor_id' => $remoteActorId, 'target_uri' => $followerActorUri]);

// ---- Test 1: publishing a PUBLIC post queues a signed Create for the follower ----
$create = $dispatch('POST', '/api/v1/posts', json_encode([
    'title' => 'Hello', 'content' => '<p>Hello federated world</p>', 'post_type' => 'NOTE', 'visibility' => 'PUBLIC',
]), $authHeader);
fpub_assert($create['status'] === 201, 'Post creation should succeed: ' . json_encode($create));
$postPublicId = $create['body']['data']['id'] ?? $create['body']['data']['public_id'];

$queuedCreate = $db->query("SELECT * FROM federation_activities WHERE activity_type = 'Create' ORDER BY id DESC LIMIT 1")->fetch();
fpub_assert($queuedCreate !== false, 'Publishing a public post should queue an outgoing Create activity');
fpub_assert($queuedCreate['target_actor_uri'] === $followerActorUri, 'The Create should target the accepted follower\'s actor URI');
fpub_assert($queuedCreate['status'] === 'PENDING', 'The queued Create should await delivery by the worker');

$createPayload = json_decode($queuedCreate['payload'], true);
fpub_assert($createPayload['@context'] === 'https://www.w3.org/ns/activitystreams', 'Outgoing Create should use the real ActivityStreams context');
fpub_assert($createPayload['actor'] === 'https://test.local/@owner', 'Create.actor should be the local owner\'s actor URI');
fpub_assert(is_array($createPayload['object']) && $createPayload['object']['type'] === 'Note', 'Create.object should be an embedded Note: ' . json_encode($createPayload));
fpub_assert($createPayload['object']['content'] === '<p>Hello federated world</p>', 'Create.object.content should match the post content');
fpub_assert(in_array('https://www.w3.org/ns/activitystreams#Public', (array) $createPayload['object']['to'], true), 'A PUBLIC post should address the Public collection');

// ---- Test 2: editing the post queues an Update with the new content ----
$update = $dispatch('PATCH', "/api/v1/posts/{$postPublicId}", json_encode(['content' => '<p>Edited!</p>']), $authHeader);
fpub_assert($update['status'] === 200, 'Post update should succeed: ' . json_encode($update));
$queuedUpdate = $db->query("SELECT * FROM federation_activities WHERE activity_type = 'Update' ORDER BY id DESC LIMIT 1")->fetch();
fpub_assert($queuedUpdate !== false, 'Editing a post should queue an outgoing Update activity');
$updatePayload = json_decode($queuedUpdate['payload'], true);
fpub_assert($updatePayload['object']['content'] === '<p>Edited!</p>', 'Update.object.content should reflect the edit');

// ---- Test 3: deleting the post queues a Delete whose object is the bare post URI ----
$delete = $dispatch('DELETE', "/api/v1/posts/{$postPublicId}", null, $authHeader);
fpub_assert($delete['status'] === 204, 'Post deletion should succeed: ' . json_encode($delete));
$queuedDelete = $db->query("SELECT * FROM federation_activities WHERE activity_type = 'Delete' ORDER BY id DESC LIMIT 1")->fetch();
fpub_assert($queuedDelete !== false, 'Deleting a post should queue an outgoing Delete activity');
$deletePayload = json_decode($queuedDelete['payload'], true);
fpub_assert(is_string($deletePayload['object']) && str_ends_with($deletePayload['object'], "/posts/{$postPublicId}"), 'Delete.object should be the bare post URI, not an embedded object: ' . json_encode($deletePayload));

// ---- Test 4: a PRIVATE post is never federated ----
$beforeCount = (int) $db->query('SELECT COUNT(*) FROM federation_activities')->fetchColumn();
$privatePost = $dispatch('POST', '/api/v1/posts', json_encode([
    'title' => 'Secret', 'content' => '<p>Just for me</p>', 'post_type' => 'NOTE', 'visibility' => 'PRIVATE',
]), $authHeader);
fpub_assert($privatePost['status'] === 201, 'Private post creation should still succeed: ' . json_encode($privatePost));
$afterCount = (int) $db->query('SELECT COUNT(*) FROM federation_activities')->fetchColumn();
fpub_assert($afterCount === $beforeCount, 'A PRIVATE post must never be queued for federation delivery');

Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Federated post delivery test passed\n");
