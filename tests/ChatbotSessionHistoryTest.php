<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;

function csh_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-chathistory-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-chathistory-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nVISITOR_TOKEN_TTL=3600\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE visitor_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, google_sub TEXT, email TEXT, display_name TEXT, avatar_url TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_seen_at TIMESTAMP, UNIQUE(node_id, google_sub))',
    'CREATE TABLE visitor_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id INTEGER, token_hash TEXT UNIQUE, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE llm_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER UNIQUE, provider_code TEXT, model TEXT, encrypted_api_key TEXT, supports_vision INTEGER DEFAULT 0, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, adapter_class TEXT, description TEXT, status TEXT DEFAULT "ACTIVE", is_plugin INTEGER DEFAULT 0, config_keys_json TEXT, supports_refund INTEGER DEFAULT 1, supports_recurring INTEGER DEFAULT 0, supports_qris INTEGER DEFAULT 0, supports_va INTEGER DEFAULT 1, supports_credit_card INTEGER DEFAULT 0, supports_ewallet INTEGER DEFAULT 0)',
    'CREATE TABLE payment_gateway_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway_id INTEGER NOT NULL, config_key TEXT NOT NULL, encrypted_value TEXT NOT NULL, environment TEXT DEFAULT "SANDBOX", is_active INTEGER DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (gateway_id, config_key, environment))',
    'CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, order_id TEXT, gateway_code TEXT, external_transaction_id TEXT, payment_method TEXT, currency TEXT DEFAULT "IDR", amount REAL DEFAULT 0, fee REAL DEFAULT 0, status TEXT DEFAULT "PENDING", payment_url TEXT, metadata TEXT, expired_at TIMESTAMP, paid_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rag_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, title TEXT, original_filename TEXT, content TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rag_faqs (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, document_id INTEGER, question TEXT, answer TEXT, embedding TEXT, embedding_model TEXT, embedding_generated_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE chatbot_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER UNIQUE, price_per_question NUMERIC DEFAULT 0, currency TEXT DEFAULT "IDR", status TEXT DEFAULT "DISABLED", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE visitor_wallets (id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id INTEGER UNIQUE, balance_amount NUMERIC DEFAULT 0, currency TEXT DEFAULT "IDR", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE visitor_wallet_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, wallet_id INTEGER, type TEXT, amount NUMERIC, payment_id INTEGER, note TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE chat_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, visitor_id INTEGER, message_count INTEGER DEFAULT 0, status TEXT DEFAULT "ACTIVE", started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_message_at TIMESTAMP)',
    'CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, role TEXT, content TEXT, cost_amount NUMERIC, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}
$db->exec('INSERT INTO payment_gateways (code, name) VALUES ("DUMMY", "Dummy")');

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$dispatch = static function (string $method, string $pathWithQuery, ?array $body = null, ?string $token = null) use ($router): array {
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    $path = (string) parse_url($pathWithQuery, PHP_URL_PATH);
    $query = [];
    parse_str((string) parse_url($pathWithQuery, PHP_URL_QUERY), $query);
    $response = $router->dispatch(new Request($method, $path, $query, $body === null ? null : json_encode($body), $headers));

    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

// ---- Setup: two owners (two nodes), one visitor per node, seeded sessions/messages ----
$registerA = $dispatch('POST', '/api/v1/auth/register', ['email' => 'ownera@test.local', 'password' => 'correct horse battery', 'handle' => 'ownera', 'display_name' => 'Owner A']);
csh_assert($registerA['status'] === 201, 'Owner A registration failed: ' . json_encode($registerA));
$ownerAToken = $registerA['body']['data']['token']['access_token'];
$nodeAId = (int) $db->query('SELECT id FROM nodes WHERE domain = "test.local"')->fetch()['id'];

// A second node needs its own domain — registration derives the node from
// NODE_DOMAIN (single-tenant-per-deploy design), so this test inserts node B
// directly rather than registering a second owner through the API.
$db->exec('INSERT INTO nodes (public_id, domain, name, default_locale, timezone) VALUES ("' . Uuid::v4() . '", "nodeb.test.local", "Node B", "id", "UTC")');
$nodeBId = (int) $db->lastInsertId();

$db->exec('INSERT INTO visitor_accounts (public_id, node_id, google_sub, email, display_name) VALUES ("' . Uuid::v4() . '", ' . $nodeAId . ', "sub-a", "alice@test.local", "Alice Visitor")');
$visitorAId = (int) $db->lastInsertId();
$db->exec('INSERT INTO visitor_accounts (public_id, node_id, google_sub, email, display_name) VALUES ("' . Uuid::v4() . '", ' . $nodeBId . ', "sub-b", "bob@test.local", "Bob Visitor")');
$visitorBId = (int) $db->lastInsertId();

// Three sessions on node A (to exercise pagination), one on node B (to
// exercise cross-node isolation).
$sessionIds = [];
foreach (['session-a1', 'session-a2', 'session-a3'] as $publicId) {
    $db->exec("INSERT INTO chat_sessions (public_id, node_id, visitor_id, message_count, last_message_at) VALUES ('{$publicId}', {$nodeAId}, {$visitorAId}, 2, CURRENT_TIMESTAMP)");
    $sessionIds[$publicId] = (int) $db->lastInsertId();
}
$db->exec("INSERT INTO chat_sessions (public_id, node_id, visitor_id, message_count, last_message_at) VALUES ('session-b1', {$nodeBId}, {$visitorBId}, 1, CURRENT_TIMESTAMP)");
$sessionIds['session-b1'] = (int) $db->lastInsertId();

$sessionA1Id = $sessionIds['session-a1'];
$db->exec("INSERT INTO chat_messages (session_id, role, content) VALUES ({$sessionA1Id}, 'VISITOR', 'Apakah ada diskon?')");
$db->exec("INSERT INTO chat_messages (session_id, role, content) VALUES ({$sessionA1Id}, 'ASSISTANT', 'Saat ini belum ada diskon aktif.')");

// ---- Test 1: unauthenticated access is rejected ----
csh_assert($dispatch('GET', '/api/v1/me/chatbot-sessions')['status'] === 401, 'Listing sessions should require owner auth');
csh_assert($dispatch('GET', "/api/v1/me/chatbot-sessions/session-a1/messages")['status'] === 401, 'Reading messages should require owner auth');

// ---- Test 2: owner A sees only node A's 3 sessions, newest first, with visitor info joined in ----
$listA = $dispatch('GET', '/api/v1/me/chatbot-sessions', null, $ownerAToken);
csh_assert($listA['status'] === 200, 'Listing sessions failed: ' . json_encode($listA));
csh_assert(count($listA['body']['data']) === 3, 'Owner A should see exactly 3 sessions, got ' . count($listA['body']['data']));
csh_assert($listA['body']['data'][0]['id'] === 'session-a3', 'Sessions should be ordered newest first: ' . json_encode($listA['body']['data']));
csh_assert($listA['body']['data'][2]['visitor_email'] === 'alice@test.local', 'Session row should carry the visitor email');
csh_assert($listA['body']['data'][2]['visitor_display_name'] === 'Alice Visitor', 'Session row should carry the visitor display name');
csh_assert($listA['body']['data'][2]['message_count'] === 2, 'Session row should carry its message_count');
foreach ($listA['body']['data'] as $row) {
    csh_assert($row['id'] !== 'session-b1', 'Owner A must never see node B\'s session in the list');
}

// ---- Test 3: cursor pagination ----
$page1 = $dispatch('GET', '/api/v1/me/chatbot-sessions?limit=2', null, $ownerAToken);
csh_assert(count($page1['body']['data']) === 2 && $page1['body']['meta']['has_more'] === true, 'First page (limit=2) should return 2 rows with has_more=true: ' . json_encode($page1));
$cursor = $page1['body']['meta']['next_cursor'];
csh_assert($cursor !== null, 'First page should carry a next_cursor');
$page2 = $dispatch('GET', '/api/v1/me/chatbot-sessions?limit=2&cursor=' . urlencode($cursor), null, $ownerAToken);
csh_assert(count($page2['body']['data']) === 1 && $page2['body']['meta']['has_more'] === false, 'Second page should return the remaining 1 row: ' . json_encode($page2));
csh_assert($page2['body']['data'][0]['id'] === 'session-a1', 'Second page should reach the oldest session: ' . json_encode($page2));

// ---- Test 4: message transcript, oldest first ----
$messages = $dispatch('GET', '/api/v1/me/chatbot-sessions/session-a1/messages', null, $ownerAToken);
csh_assert($messages['status'] === 200 && count($messages['body']['data']) === 2, 'Reading a session\'s transcript failed: ' . json_encode($messages));
csh_assert($messages['body']['data'][0]['role'] === 'VISITOR' && $messages['body']['data'][1]['role'] === 'ASSISTANT', 'Messages should be ordered oldest first: ' . json_encode($messages));
csh_assert($messages['body']['data'][0]['content'] === 'Apakah ada diskon?', 'Message content should round-trip unchanged');

// ---- Test 5: owner A cannot read node B's session (403), and a nonexistent session 404s ----
$forbidden = $dispatch('GET', '/api/v1/me/chatbot-sessions/session-b1/messages', null, $ownerAToken);
csh_assert($forbidden['status'] === 403, 'Reading another node\'s session should 403, got ' . $forbidden['status']);

$notFound = $dispatch('GET', '/api/v1/me/chatbot-sessions/does-not-exist/messages', null, $ownerAToken);
csh_assert($notFound['status'] === 404, 'Reading a nonexistent session should 404, got ' . $notFound['status']);

Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Chatbot session history test passed\n");
