<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

function fed_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-fed-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-fed-test-' . uniqid() . '.env';
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
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT, federated_address TEXT UNIQUE, display_name TEXT, avatar_url TEXT, canonical_url TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, relationship_status TEXT DEFAULT "PENDING", show_on_profile INTEGER DEFAULT 1, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (profile_id, remote_actor_id))',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, canonical_url TEXT, title TEXT, content TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}
/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$dispatch = static function (string $method, string $path, ?array $body = null, ?string $token = null, array $query = []) use ($router): array {
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    $response = $router->dispatch(new Request($method, $path, $query, $body === null ? null : json_encode($body), $headers));
    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

// Register owner
$register = $dispatch('POST', '/api/v1/auth/register', [
    'email' => 'owner@test.local',
    'password' => 'correct horse battery',
    'handle' => 'owner',
    'display_name' => 'Owner',
]);
fed_assert($register['status'] === 201, 'Owner registration failed');
$token = $register['body']['data']['token']['access_token'];

// Register a second user
$register2 = $dispatch('POST', '/api/v1/auth/register', [
    'email' => 'other@test.local',
    'password' => 'correct horse battery',
    'handle' => 'other',
    'display_name' => 'Other',
]);
fed_assert($register2['status'] === 201, 'Second user registration failed');
$token2 = $register2['body']['data']['token']['access_token'];
// Seed remote_nodes and remote_actors
$db->exec("INSERT INTO remote_nodes (public_id, domain, name, status, trust_state) VALUES ('rn-1', 'remote1.example', 'Remote One', 'ACTIVE', 'TRUSTED')");
$db->exec("INSERT INTO remote_nodes (public_id, domain, name, status, trust_state) VALUES ('rn-2', 'remote2.example', 'Remote Two', 'ACTIVE', 'UNKNOWN')");
$db->exec("INSERT INTO remote_nodes (public_id, domain, name, status, trust_state) VALUES ('rn-3', 'blocked.example', 'Blocked', 'ACTIVE', 'BLOCKED')");

$db->exec("INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, avatar_url, canonical_url)
           VALUES ('ra-1', 1, 'https://remote1.example/@alice', '@alice@remote1.example', 'Alice', 'https://remote1.example/avatar/alice.jpg', 'https://remote1.example/@alice')");
$db->exec("INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, avatar_url, canonical_url)
           VALUES ('ra-2', 1, 'https://remote1.example/@bob', '@bob@remote1.example', 'Bob', 'https://remote1.example/avatar/bob.jpg', 'https://remote1.example/@bob')");
$db->exec("INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, avatar_url, canonical_url)
           VALUES ('ra-3', 2, 'https://remote2.example/@carol', '@carol@remote2.example', 'Carol', 'https://remote2.example/avatar/carol.jpg', 'https://remote2.example/@carol')");
$db->exec("INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, avatar_url, canonical_url)
           VALUES ('ra-4', 3, 'https://blocked.example/@mallory', '@mallory@blocked.example', 'Mallory', null, 'https://blocked.example/@mallory')");

// Seed federated posts
$db->exec("INSERT INTO federated_posts (public_id, remote_actor_id, object_uri, canonical_url, title, content, visibility, published_at)
           VALUES ('fp-1', 1, 'https://remote1.example/@alice/posts/1', 'https://remote1.example/posts/1', 'Alice Post', 'Hello from Alice', 'PUBLIC', '2026-09-19 08:00:00')");
$db->exec("INSERT INTO federated_posts (public_id, remote_actor_id, object_uri, canonical_url, title, content, visibility, published_at)
           VALUES ('fp-2', 2, 'https://remote1.example/@bob/posts/1', 'https://remote1.example/posts/1', 'Bob Post', 'Hello from Bob', 'PUBLIC', '2026-09-19 09:00:00')");
// Get profile IDs
$ownerRow = $db->query("SELECT id FROM profiles WHERE handle = 'owner'")->fetch();
$ownerProfileId = (int) $ownerRow['id'];
$otherRow = $db->query("SELECT id FROM profiles WHERE handle = 'other'")->fetch();
$otherProfileId = (int) $otherRow['id'];
// Seed federated_connections for owner
$db->exec("INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status, show_on_profile)
           VALUES ('conn-1', {$ownerProfileId}, 1, 'CONNECTED', 1)");
$db->exec("INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status, show_on_profile)
           VALUES ('conn-2', {$ownerProfileId}, 2, 'FOLLOWING', 1)");
$db->exec("INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status, show_on_profile)
           VALUES ('conn-3', {$ownerProfileId}, 3, 'CONNECTED', 0)");
$db->exec("INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status, show_on_profile)
           VALUES ('conn-4', {$ownerProfileId}, 4, 'FOLLOWING', 1)");

// Seed federated_connections for other user
$db->exec("INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status, show_on_profile)
           VALUES ('conn-5', {$otherProfileId}, 1, 'CONNECTED', 1)");
// ---- Test 1: Public endpoint returns only visible, non-blocked connections ----
$publicList = $dispatch('GET', '/api/v1/profiles/owner/federated-connections');
fed_assert($publicList['status'] === 200, 'Public list endpoint failed');
fed_assert(isset($publicList['body']['data']), 'Public list missing data key');
$count = count($publicList['body']['data']);
fed_assert($count === 2, "Expected exactly 2 public connections, got {$count}");

// ---- Test 2: Public endpoint for non-existent handle returns 404 ----
$notFound = $dispatch('GET', '/api/v1/profiles/nobody/federated-connections');
fed_assert($notFound['status'] === 404, 'Non-existent handle should 404');

// ---- Test 3: Public endpoint pagination with limit ----
$limitResult = $dispatch('GET', '/api/v1/profiles/owner/federated-connections', null, null, ['limit' => 1]);
fed_assert($limitResult['status'] === 200, 'Public list with limit failed');
fed_assert(count($limitResult['body']['data']) === 1, 'Limit=1 should return 1 item');
fed_assert($limitResult['body']['meta']['has_more'] === true, 'Should have more items');

// ---- Test 4: Owner endpoint returns all connections including hidden/blocked ----
$ownList = $dispatch('GET', '/api/v1/me/federated-connections', null, $token);
fed_assert($ownList['status'] === 200, 'Owner list endpoint failed');
fed_assert(isset($ownList['body']['data']['connections']), 'Owner list missing connections key');
fed_assert(count($ownList['body']['data']['connections']) === 4, 'Expected 4 connections for owner');

$hiddenConn = array_filter($ownList['body']['data']['connections'], fn ($c) => $c['id'] === 'conn-3');
fed_assert(count($hiddenConn) === 1, 'Hidden connection should appear in owner view');
fed_assert(current($hiddenConn)['show_on_profile'] === false, 'Hidden connection should have show_on_profile=false');

// ---- Test 5: Owner endpoint requires authentication ----
$unauthList = $dispatch('GET', '/api/v1/me/federated-connections');
fed_assert($unauthList['status'] === 401, 'Unauthenticated owner list should 401');

// ---- Test 6: Update connection - show_on_profile ----
$updateShow = $dispatch('PATCH', '/api/v1/me/federated-connections/conn-3', ['show_on_profile' => true], $token);
fed_assert($updateShow['status'] === 200, 'Update show_on_profile failed');

// ---- Test 7: Update connection - block ----
$updateBlock = $dispatch('PATCH', '/api/v1/me/federated-connections/conn-2', ['relationship_status' => 'BLOCKED'], $token);
fed_assert($updateBlock['status'] === 200, 'Block connection failed');

$checkBlocked = $dispatch('GET', '/api/v1/me/federated-connections', null, $token);
$blockedConn = current(array_filter($checkBlocked['body']['data']['connections'], fn ($c) => $c['id'] === 'conn-2'));
fed_assert($blockedConn['relationship_status'] === 'BLOCKED', 'Connection should be BLOCKED');

// ---- Test 8: Update connection - mute ----
$updateMute = $dispatch('PATCH', '/api/v1/me/federated-connections/conn-3', ['relationship_status' => 'MUTED'], $token);
fed_assert($updateMute['status'] === 200, 'Mute connection failed');

// ---- Test 9: Update connection from another owner fails ----
$updateOther = $dispatch('PATCH', '/api/v1/me/federated-connections/conn-1', ['show_on_profile' => false], $token2);
fed_assert($updateOther['status'] === 403, 'Other owner should not update another connection');

// ---- Test 10: Invalid relationship_status rejected ----
$invalidStatus = $dispatch('PATCH', '/api/v1/me/federated-connections/conn-1', ['relationship_status' => 'INVALID'], $token);
fed_assert($invalidStatus['status'] === 422, 'Invalid relationship_status should 422');

// ---- Test 11: Unknown field rejected ----
$unknownField = $dispatch('PATCH', '/api/v1/me/federated-connections/conn-1', ['foo' => 'bar'], $token);
fed_assert($unknownField['status'] === 422, 'Unknown field should 422');

// ---- Test 12: Non-existent connection returns 404 ----
$nonexistent = $dispatch('PATCH', '/api/v1/me/federated-connections/conn-nonexistent', ['show_on_profile' => false], $token);
fed_assert($nonexistent['status'] === 404, 'Non-existent connection should 404');

// ---- Test 13: Private profile hides federated connections ----
$db->exec("UPDATE profiles SET visibility = 'PRIVATE' WHERE handle = 'owner'");
$privateProfile = $dispatch('GET', '/api/v1/profiles/owner/federated-connections');
fed_assert($privateProfile['status'] === 404, 'Private profile should 404 on public connections');

// Cleanup
Database::reset();
unset($db);
unlink($envPath);
unlink($dbPath);
fwrite(STDOUT, "Federated connections test passed\n");