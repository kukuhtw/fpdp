<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;

function utl_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-timeline-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-timeline-test-' . uniqid() . '.env';
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
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, canonical_url TEXT, title TEXT, content TEXT, attachments TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
    'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, title TEXT, description TEXT, price TEXT DEFAULT "0", currency TEXT DEFAULT "IDR", status TEXT DEFAULT "ACTIVE", visibility TEXT DEFAULT "PUBLIC", is_promoted INTEGER DEFAULT 0, media TEXT, product_type TEXT DEFAULT "PHYSICAL", digital_asset_url TEXT, digital_asset_metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

/** @return array{status: int, body: mixed} */
$dispatch = static function (string $method, string $path, ?string $rawBody = null, array $headers = [], array $query = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, $query, $rawBody, $headers));
    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

// ---- Register the local owner, publish a local post, and follow a remote actor with a federated post ----
$register = $dispatch('POST', '/api/v1/auth/register', json_encode([
    'email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner',
]));
utl_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];
$profileId = (int) $db->query('SELECT id FROM profiles LIMIT 1')->fetchColumn();

$localPost = $dispatch('POST', '/api/v1/posts', json_encode([
    'title' => 'Postingan Lokal', 'content' => '<p>Ini postingan lokal saya.</p>', 'post_type' => 'NOTE', 'visibility' => 'PUBLIC',
    'published_at' => gmdate('c'),
]), ['authorization' => 'Bearer ' . $ownerToken]);
utl_assert($localPost['status'] === 201, 'Local post creation failed: ' . json_encode($localPost));

$db->exec('INSERT INTO remote_nodes (public_id, domain) VALUES (\'' . Uuid::v4() . '\', "friend.example")');
$remoteNodeId = (int) $db->lastInsertId();
$actorStmt = $db->prepare(
    'INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name)
     VALUES (:pid, :node_id, "https://friend.example/users/maya", "@maya@friend.example", "Maya")',
);
$actorStmt->execute(['pid' => Uuid::v4(), 'node_id' => $remoteNodeId]);
$remoteActorId = (int) $db->lastInsertId();

$connStmt = $db->prepare(
    'INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status, accepted_at)
     VALUES (:pid, :profile_id, :actor_id, "CONNECTED", CURRENT_TIMESTAMP)',
);
$connStmt->execute(['pid' => Uuid::v4(), 'profile_id' => $profileId, 'actor_id' => $remoteActorId]);

// Federated content includes a script tag to prove it gets sanitized, and is published slightly
// earlier than the local post so ordering can be asserted deterministically.
$federatedPublishedAt = gmdate('Y-m-d H:i:s', time() - 60);
$fedPostStmt = $db->prepare(
    'INSERT INTO federated_posts (public_id, remote_actor_id, object_uri, canonical_url, title, content, visibility, published_at)
     VALUES (:pid, :actor_id, "https://friend.example/notes/1", "https://friend.example/@maya/1", NULL,
             :content, "PUBLIC", :published_at)',
);
$fedPostStmt->execute([
    'pid' => Uuid::v4(),
    'actor_id' => $remoteActorId,
    'content' => '<p>Halo dari fediverse!</p><script>alert(1)</script>',
    'published_at' => $federatedPublishedAt,
]);

// A post from an actor the owner does NOT follow must never appear.
$strangerActorStmt = $db->prepare(
    'INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name)
     VALUES (:pid, :node_id, "https://friend.example/users/bob", "@bob@friend.example", "Bob")',
);
$strangerActorStmt->execute(['pid' => Uuid::v4(), 'node_id' => $remoteNodeId]);
$strangerActorId = (int) $db->lastInsertId();
$strangerPostStmt = $db->prepare(
    'INSERT INTO federated_posts (public_id, remote_actor_id, object_uri, canonical_url, content, visibility, published_at)
     VALUES (:pid, :actor_id, "https://friend.example/notes/2", "https://friend.example/@bob/2", "<p>Not followed</p>", "PUBLIC", :published_at)',
);
$strangerPostStmt->execute(['pid' => Uuid::v4(), 'actor_id' => $strangerActorId, 'published_at' => $federatedPublishedAt]);

// ---- Test 1: /api/v1/timeline?source_type=ALL merges local + federated, sorted newest first ----
$merged = $dispatch('GET', '/api/v1/timeline', null, [], ['source_type' => 'ALL']);
utl_assert($merged['status'] === 200, 'Unified timeline request should succeed: ' . json_encode($merged));
$items = $merged['body']['data'];
utl_assert(count($items) === 2, 'Timeline should contain exactly the local post and the followed actor\'s post, not the unfollowed one: ' . json_encode($items));
utl_assert($items[0]['source_type'] === 'LOCAL', 'The newer local post should be first: ' . json_encode($items));
utl_assert($items[1]['source_type'] === 'FEDERATED', 'The older federated post should be second: ' . json_encode($items));

// ---- Test 2: federated content is sanitized before it ever reaches the API response ----
utl_assert(!str_contains($items[1]['content'], '<script>'), 'Federated post content must be sanitized: ' . $items[1]['content']);
utl_assert(str_contains($items[1]['content'], 'Halo dari fediverse'), 'Sanitized content should keep safe text: ' . $items[1]['content']);
utl_assert($items[1]['author']['handle'] === 'maya@friend.example', 'Federated author handle should be the federated address without a leading @: ' . json_encode($items[1]['author']));
utl_assert($items[1]['canonical_url'] === 'https://friend.example/@maya/1', 'Federated canonical_url should point at the remote post');

// ---- Test 3: source_type=LOCAL (the old default) still returns only the local post ----
$localOnly = $dispatch('GET', '/api/v1/timeline', null, [], ['source_type' => 'LOCAL']);
utl_assert($localOnly['status'] === 200 && count($localOnly['body']['data']) === 1, 'LOCAL source_type should be unaffected by the merge feature: ' . json_encode($localOnly));

// ---- Test 4: the HTML /timeline page defaults to the merged view without an explicit source_type ----
$html = $dispatch('GET', '/timeline');
utl_assert($html['status'] === 200, '/timeline should render successfully: ' . json_encode($html));

// ---- Test 5: a product marked "Promosikan ke Fediverse" appears in the timeline; a non-promoted one does not ----
$nodeId = (int) $db->query('SELECT id FROM nodes LIMIT 1')->fetchColumn();
$promotedStmt = $db->prepare(
    "INSERT INTO products (public_id, node_id, title, description, price, currency, status, visibility, is_promoted, created_at)
     VALUES (:pid, :node_id, 'Kaos Edisi Terbatas', 'Kaos katun premium.', '150000', 'IDR', 'ACTIVE', 'PUBLIC', 1, :created_at)",
);
$promotedStmt->execute(['pid' => Uuid::v4(), 'node_id' => $nodeId, 'created_at' => gmdate('Y-m-d H:i:s', time() + 10)]);
$promotedProductId = (string) $db->lastInsertId();
$promotedProductPublicId = $db->query('SELECT public_id FROM products WHERE id = ' . $promotedProductId)->fetchColumn();

$notPromotedStmt = $db->prepare(
    "INSERT INTO products (public_id, node_id, title, description, price, currency, status, visibility, is_promoted, created_at)
     VALUES (:pid, :node_id, 'Produk Biasa', 'Tidak dipromosikan.', '50000', 'IDR', 'ACTIVE', 'PUBLIC', 0, :created_at)",
);
$notPromotedStmt->execute(['pid' => Uuid::v4(), 'node_id' => $nodeId, 'created_at' => gmdate('Y-m-d H:i:s', time() + 20)]);

$withProduct = $dispatch('GET', '/api/v1/timeline', null, [], ['source_type' => 'ALL']);
utl_assert($withProduct['status'] === 200, 'Timeline with a promoted product should succeed: ' . json_encode($withProduct));
$productItems = array_filter($withProduct['body']['data'], static fn (array $it): bool => $it['source_type'] === 'PRODUCT');
utl_assert(count($productItems) === 1, 'Exactly the promoted product should appear, not the non-promoted one: ' . json_encode($withProduct['body']['data']));
$productItem = array_values($productItems)[0];
utl_assert($productItem['canonical_url'] === "/shop/{$promotedProductPublicId}", 'Product canonical_url should point at its shop page: ' . json_encode($productItem));
utl_assert(str_contains($productItem['content'], '150.000'), 'Product content should include the formatted price: ' . $productItem['content']);
utl_assert(str_contains($productItem['content'], 'Kaos katun premium'), 'Product content should include the description: ' . $productItem['content']);

Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Unified timeline test passed\n");
