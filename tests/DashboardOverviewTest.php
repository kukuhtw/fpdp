<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

function dash_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-dash-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-dash-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", capabilities TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER, profile_id INTEGER, title TEXT, content TEXT, post_type TEXT DEFAULT "NOTE", visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, deleted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE post_media (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, media_type TEXT, url TEXT, alt_text TEXT, sort_order INTEGER DEFAULT 0)',
    'CREATE TABLE external_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, provider TEXT NOT NULL, external_post_id TEXT NOT NULL, status TEXT DEFAULT "ACTIVE", UNIQUE (provider, external_post_id))',
    'CREATE TABLE remote_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, status TEXT DEFAULT "ACTIVE", trust_state TEXT DEFAULT "UNKNOWN", last_seen_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT, federated_address TEXT UNIQUE, display_name TEXT, avatar_url TEXT, canonical_url TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, relationship_status TEXT DEFAULT "PENDING", show_on_profile INTEGER DEFAULT 1, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (profile_id, remote_actor_id))',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, canonical_url TEXT, title TEXT, content TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
    'CREATE TABLE follows (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, target_actor_uri TEXT, target_federated_address TEXT, status TEXT DEFAULT "PENDING", direction TEXT DEFAULT "OUTGOING", activity_public_id TEXT, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE node_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER, key_type TEXT DEFAULT "ed25519", public_key TEXT, private_key TEXT, fingerprint TEXT UNIQUE, is_current INTEGER DEFAULT 1, rotated_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federation_activities (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, direction TEXT, activity_type TEXT, actor_uri TEXT, object_uri TEXT, target_node_domain TEXT, payload TEXT, signature TEXT, status TEXT DEFAULT "PENDING", retry_count INTEGER DEFAULT 0, last_error TEXT, next_attempt_at TIMESTAMP, delivered_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, title TEXT, description TEXT, price TEXT, currency TEXT DEFAULT "IDR", media TEXT, status TEXT DEFAULT "ACTIVE", visibility TEXT DEFAULT "PUBLIC", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, buyer_email TEXT, buyer_name TEXT, status TEXT DEFAULT "PENDING", total_amount TEXT DEFAULT "0", currency TEXT DEFAULT "IDR", notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, order_id TEXT, gateway_code TEXT, external_transaction_id TEXT, payment_method TEXT, currency TEXT DEFAULT "IDR", amount REAL DEFAULT 0, fee REAL DEFAULT 0, status TEXT DEFAULT "PENDING", payment_url TEXT, metadata TEXT, expired_at TIMESTAMP, paid_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER, actor_user_id INTEGER, action TEXT, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE analytics_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, event_type TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, visitor_hash TEXT, occurred_on TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
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

// Register a real owner via the real AuthService.
$register = $dispatch('POST', '/api/v1/auth/register', [
    'email' => 'owner@test.local',
    'password' => 'correct horse battery',
    'handle' => 'owner',
    'display_name' => 'Owner',
]);
dash_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];
// register()'s JSON response exposes public UUIDs, not internal ids, so the
// internal integer ids used to seed raw rows below are read back from the DB.
$ownerUserId = (int) $db->query("SELECT id FROM users WHERE email = 'owner@test.local'")->fetch()['id'];
$ownerNodeId = (int) $db->query("SELECT id FROM nodes WHERE domain = 'test.local'")->fetch()['id'];
$ownerProfileId = (int) $db->query("SELECT id FROM profiles WHERE handle = 'owner'")->fetch()['id'];

// Seed one published + one draft local post.
$db->exec("INSERT INTO posts (public_id, user_id, profile_id, title, content, visibility, published_at) VALUES ('p1', {$ownerUserId}, {$ownerProfileId}, 'Published', 'x', 'PUBLIC', CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO posts (public_id, user_id, profile_id, title, content, visibility, published_at) VALUES ('p2', {$ownerUserId}, {$ownerProfileId}, 'Draft', 'x', 'PUBLIC', NULL)");

// Seed one external post owned by this user.
$db->exec("INSERT INTO external_posts (user_id, provider, external_post_id, status) VALUES ({$ownerUserId}, 'RSS', 'ext-1', 'ACTIVE')");

// Seed a federated post reachable via a connection the owner has.
$db->exec("INSERT INTO remote_nodes (public_id, domain) VALUES ('rn1', 'alice.example')");
$remoteNodeId = (int) $db->lastInsertId();
$db->exec("INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address) VALUES ('ra1', {$remoteNodeId}, 'https://alice.example/@alice', '@alice@alice.example')");
$remoteActorId = (int) $db->lastInsertId();
$db->exec("INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status) VALUES ('fc1', {$ownerProfileId}, {$remoteActorId}, 'CONNECTED')");
$db->exec("INSERT INTO federated_posts (public_id, remote_actor_id, object_uri, title, visibility, published_at) VALUES ('fp1', {$remoteActorId}, 'https://alice.example/posts/1', 'Alice post', 'PUBLIC', CURRENT_TIMESTAMP)");

// Seed one product and two orders (one pending, one completed).
$db->exec("INSERT INTO products (public_id, node_id, title, price) VALUES ('prod1', {$ownerNodeId}, 'Workshop', '100000')");
$db->exec("INSERT INTO orders (public_id, node_id, status, total_amount) VALUES ('ord1', {$ownerNodeId}, 'PENDING', '100000')");
$db->exec("INSERT INTO orders (public_id, node_id, status, total_amount) VALUES ('ord2', {$ownerNodeId}, 'COMPLETED', '100000')");

// Seed one PAID payment this month, for revenue_this_month.
$db->exec("INSERT INTO payments (uuid, order_id, gateway_code, currency, amount, status, paid_at) VALUES ('pay-uuid-1', 'ord2', 'DUMMY', 'IDR', 100000, 'PAID', CURRENT_TIMESTAMP)");

// Seed one follower (INCOMING) and one following (OUTGOING).
$db->exec("INSERT INTO follows (public_id, profile_id, target_actor_uri, status, direction) VALUES ('f1', {$ownerProfileId}, 'https://bob.example/@bob', 'ACCEPTED', 'INCOMING')");
$db->exec("INSERT INTO follows (public_id, profile_id, target_actor_uri, status, direction) VALUES ('f2', {$ownerProfileId}, 'https://carol.example/@carol', 'ACCEPTED', 'OUTGOING')");

// Seed two audit events.
$db->exec("INSERT INTO audit_events (node_id, actor_user_id, action, subject_type) VALUES ({$ownerNodeId}, {$ownerUserId}, 'user.registered', 'user')");
$db->exec("INSERT INTO audit_events (node_id, actor_user_id, action, subject_type) VALUES ({$ownerNodeId}, {$ownerUserId}, 'post.created', 'post')");

// ---- Test 1: overview requires auth ----
$unauth = $dispatch('GET', '/api/v1/me/dashboard/overview');
dash_assert($unauth['status'] === 401, 'Overview should require auth');

// ---- Test 2: overview aggregates every metric correctly ----
$overview = $dispatch('GET', '/api/v1/me/dashboard/overview', null, $ownerToken);
dash_assert($overview['status'] === 200, 'Overview should succeed for an authenticated owner: ' . json_encode($overview));
$data = $overview['body']['data'];

dash_assert($data['node']['domain'] === 'test.local', 'Overview should report the node domain');
dash_assert($data['content'] === ['total' => 2, 'published' => 1, 'draft' => 1], 'Content counts wrong: ' . json_encode($data['content']));
dash_assert($data['timeline_mix'] === ['local' => 1, 'external' => 1, 'federated' => 1], 'Timeline mix wrong: ' . json_encode($data['timeline_mix']));
dash_assert($data['commerce']['product_count'] === 1, 'product_count wrong');
dash_assert($data['commerce']['order_count'] === 2, 'order_count wrong');
dash_assert($data['commerce']['pending_order_count'] === 1, 'pending_order_count wrong');
dash_assert((float) $data['commerce']['revenue_this_month'] === 100000.0, 'revenue_this_month wrong: ' . json_encode($data['commerce']));
dash_assert($data['federation'] === ['follower_count' => 1, 'following_count' => 1], 'Federation counts wrong: ' . json_encode($data['federation']));
dash_assert(count($data['recent_activity']) === 2, 'recent_activity should include both seeded audit events');
dash_assert($data['analytics']['available'] === true, 'Analytics should report available now that the subsystem exists');
dash_assert(count($data['analytics']['daily_traffic']) === 7, 'daily_traffic should always have exactly 7 entries (gaps filled with zero)');
dash_assert($data['analytics']['profile_views'] === 0, 'No views have happened yet at this point in the test');

// ---- Test 3: payments dashboard summary matches the same underlying data ----
$paymentsSummary = $dispatch('GET', '/api/v1/me/dashboard/payments', null, $ownerToken);
dash_assert($paymentsSummary['status'] === 200, 'Payments summary should succeed: ' . json_encode($paymentsSummary));
$psData = $paymentsSummary['body']['data'];
dash_assert((float) $psData['available_balance'] === 100000.0, 'available_balance should equal total PAID amount');
dash_assert($psData['paid_count'] === 1, 'paid_count wrong');
dash_assert(count($psData['recent_transactions']) === 1, 'recent_transactions should include the seeded payment');

$unauthPayments = $dispatch('GET', '/api/v1/me/dashboard/payments');
dash_assert($unauthPayments['status'] === 401, 'Payments summary should require auth');

// ---- Test 4: viewing the public profile/post and tracking an outbound click actually records analytics events ----
$profileView = $dispatch('GET', '/api/v1/profiles/owner');
dash_assert($profileView['status'] === 200, 'Public profile view should succeed: ' . json_encode($profileView));

$postView = $dispatch('GET', '/api/v1/posts/p1');
dash_assert($postView['status'] === 200, 'Public post view should succeed: ' . json_encode($postView));

$unauthClick = $dispatch('POST', '/api/v1/profiles/owner/track/outbound-click', ['target_url' => 'https://shop.example/product']);
dash_assert($unauthClick['status'] === 202, 'Tracking an outbound click should not require auth and should succeed: ' . json_encode($unauthClick));

$badClick = $dispatch('POST', '/api/v1/profiles/owner/track/outbound-click', ['target_url' => 'not-a-url']);
dash_assert($badClick['status'] === 422, 'An invalid target_url should 422');

$analytics = $dispatch('GET', '/api/v1/me/dashboard/analytics', null, $ownerToken);
dash_assert($analytics['status'] === 200, 'Analytics summary should succeed: ' . json_encode($analytics));
$aData = $analytics['body']['data'];
dash_assert($aData['profile_views'] === 1, 'profile_views should be 1 after one profile view: ' . json_encode($aData));
dash_assert($aData['content_views'] === 1, 'content_views should be 1 after one post view');
dash_assert($aData['outbound_clicks'] === 1, 'outbound_clicks should be 1 after one successfully tracked click (the invalid one must not count)');
dash_assert($aData['unique_visitors'] === 1, 'unique_visitors should be 1: profile view + post view share the same day/IP/UA hash');
dash_assert($aData['top_content'][0]['subject_public_id'] === 'p1', 'top_content should surface the viewed post: ' . json_encode($aData['top_content']));

$unauthAnalytics = $dispatch('GET', '/api/v1/me/dashboard/analytics');
dash_assert($unauthAnalytics['status'] === 401, 'Analytics summary should require auth');

// Cleanup
Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Dashboard overview test passed\n");
