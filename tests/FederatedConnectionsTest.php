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