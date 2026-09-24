<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;

function fprod_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-fedprod-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-fedprod-test-' . uniqid() . '.env';
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
    'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, title TEXT, description TEXT, price TEXT DEFAULT "0", currency TEXT DEFAULT "IDR", status TEXT DEFAULT "ACTIVE", visibility TEXT DEFAULT "PUBLIC", is_promoted INTEGER DEFAULT 0, media TEXT, product_type TEXT DEFAULT "PHYSICAL", digital_asset_url TEXT, digital_asset_metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
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

/** @return array{status: int, body: mixed} */
$dispatch = static function (string $method, string $path, ?string $rawBody = null, array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, [], $rawBody, $headers));
    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

// ---- Register the local owner and seed an accepted follower (INCOMING) ----
$register = $dispatch('POST', '/api/v1/auth/register', json_encode([
    'email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner',
]));
fprod_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];
$profileId = (int) $db->query('SELECT id FROM profiles LIMIT 1')->fetchColumn();
$authHeader = ['authorization' => 'Bearer ' . $ownerToken];

$db->exec('INSERT INTO remote_nodes (public_id, domain) VALUES (\'' . Uuid::v4() . '\', "follower.example")');
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

// ---- Test 1: a normal (non-promoted) product is never federated ----
$plain = $dispatch('POST', '/api/v1/products', json_encode([
    'title' => 'Kaos Polos', 'description' => 'Kaos katun biasa', 'price' => 50000, 'currency' => 'IDR',
    'product_type' => 'PHYSICAL', 'status' => 'ACTIVE', 'visibility' => 'PUBLIC',
]), $authHeader);
fprod_assert($plain['status'] === 201, 'Plain product creation should succeed: ' . json_encode($plain));
$countAfterPlain = (int) $db->query('SELECT COUNT(*) FROM federation_activities')->fetchColumn();
fprod_assert($countAfterPlain === 0, 'A non-promoted product must never be federated');

// ---- Test 2: a promoted PUBLIC ACTIVE product queues a signed Create with title+price+description+link, and a photo attachment ----
$promoted = $dispatch('POST', '/api/v1/products', json_encode([
    'title' => 'Kaos Edisi Terbatas', 'description' => 'Kaos edisi terbatas, hanya 50 pcs.', 'price' => 150000, 'currency' => 'IDR',
    'product_type' => 'PHYSICAL', 'status' => 'ACTIVE', 'visibility' => 'PUBLIC', 'is_promoted' => true,
    'media' => [['type' => 'IMAGE', 'url' => 'https://test.local/media/kaos.jpg']],
]), $authHeader);
fprod_assert($promoted['status'] === 201, 'Promoted product creation should succeed: ' . json_encode($promoted));
$productPublicId = $promoted['body']['data']['public_id'];

$queuedCreate = $db->query("SELECT * FROM federation_activities WHERE activity_type = 'Create' ORDER BY id DESC LIMIT 1")->fetch();
fprod_assert($queuedCreate !== false, 'Promoting a product should queue an outgoing Create activity');
fprod_assert($queuedCreate['target_actor_uri'] === $followerActorUri, 'The Create should target the accepted follower\'s actor URI');
$payload = json_decode($queuedCreate['payload'], true);
fprod_assert($payload['object']['type'] === 'Note', 'Product Create.object should be a Note (no AS2 Product type): ' . json_encode($payload));
fprod_assert(str_contains($payload['object']['content'], 'Kaos Edisi Terbatas'), 'Content should include the product title');
fprod_assert(str_contains($payload['object']['content'], '150.000'), 'Content should include the formatted price: ' . $payload['object']['content']);
fprod_assert(str_contains($payload['object']['content'], "/shop/{$productPublicId}"), 'Content should include a checkout link to the shop page');
fprod_assert(isset($payload['object']['attachment'][0]['url']) && $payload['object']['attachment'][0]['url'] === 'https://test.local/media/kaos.jpg', 'The product photo should be attached: ' . json_encode($payload));

// ---- Test 3: editing a promoted product queues an Update ----
$update = $dispatch('PATCH', "/api/v1/products/{$productPublicId}", json_encode(['price' => 175000]), $authHeader);
fprod_assert($update['status'] === 200, 'Product update should succeed: ' . json_encode($update));
$queuedUpdate = $db->query("SELECT * FROM federation_activities WHERE activity_type = 'Update' ORDER BY id DESC LIMIT 1")->fetch();
fprod_assert($queuedUpdate !== false, 'Editing a promoted product should queue an outgoing Update activity');
$updatePayload = json_decode($queuedUpdate['payload'], true);
fprod_assert(str_contains($updatePayload['object']['content'], '175.000'), 'Updated content should reflect the new price: ' . $updatePayload['object']['content']);

// ---- Test 4: a PRIVATE promoted product is never federated ----
$beforeCount = (int) $db->query('SELECT COUNT(*) FROM federation_activities')->fetchColumn();
$privatePromoted = $dispatch('POST', '/api/v1/products', json_encode([
    'title' => 'Rahasia', 'price' => 99000, 'currency' => 'IDR', 'product_type' => 'PHYSICAL',
    'status' => 'ACTIVE', 'visibility' => 'PRIVATE', 'is_promoted' => true,
]), $authHeader);
fprod_assert($privatePromoted['status'] === 201, 'Private product creation should still succeed: ' . json_encode($privatePromoted));
$afterCount = (int) $db->query('SELECT COUNT(*) FROM federation_activities')->fetchColumn();
fprod_assert($afterCount === $beforeCount, 'A PRIVATE product must never be federated even if is_promoted is true');

Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Federated product delivery test passed\n");
