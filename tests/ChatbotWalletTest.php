<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;

function cw_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-chatwallet-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-chatwallet-test-' . uniqid() . '.env';
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
    // NUMERIC (not TEXT) affinity for money columns — SQLite compares TEXT
    // values lexicographically ('20000' < '5000.00' as strings!), which
    // would make VisitorWalletRepository::debit()'s "balance_amount >=
    // :amount" check wrong in this test even though it's correct against a
    // real DECIMAL column in MySQL (verified separately against a live
    // MariaDB instance). NUMERIC affinity here matches that real behavior.
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

$dispatch = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($router): array {
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $headers));

    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

// ---- Setup: owner, chatbot settings, LLM config, visitor ----
$register = $dispatch('POST', '/api/v1/auth/register', ['email' => 'chatowner@test.local', 'password' => 'correct horse battery', 'handle' => 'chatowner', 'display_name' => 'Chat Owner']);
cw_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];
// register()'s JSON response exposes the node's public UUID, not its
// internal integer id, so the id used to seed raw rows below is read back
// from the DB instead (same convention as DashboardOverviewTest.php).
$nodeId = (int) $db->query('SELECT id FROM nodes WHERE domain = "test.local"')->fetch()['id'];

cw_assert($dispatch('GET', '/api/v1/profiles/chatowner/chatbot/settings')['body']['data']['enabled'] === false, 'Chatbot should be disabled before configuration');

$updateSettings = $dispatch('PATCH', '/api/v1/me/chatbot-settings', ['price_per_question' => '5000', 'currency' => 'IDR', 'enabled' => true], $ownerToken);
cw_assert($updateSettings['status'] === 200 && $updateSettings['body']['data']['enabled'] === true, 'Enabling chatbot failed: ' . json_encode($updateSettings));

$db->exec('INSERT INTO llm_configs (node_id, provider_code, model, encrypted_api_key, supports_vision) VALUES (' . $nodeId . ', "OPENAI", "gpt-4o-mini", ' . $db->quote(Crypto::encrypt('sk-fake-key')) . ', 0)');

$db->exec('INSERT INTO visitor_accounts (public_id, node_id, google_sub, email, display_name) VALUES ("' . Uuid::v4() . '", ' . $nodeId . ', "sub-1", "visitor@test.local", "Visitor One")');
$visitorId = (int) $db->lastInsertId();
$visitorPublicId = $db->query('SELECT public_id FROM visitor_accounts WHERE id = ' . $visitorId)->fetch()['public_id'];
$rawVisitorToken = bin2hex(random_bytes(32));
$db->prepare('INSERT INTO visitor_tokens (visitor_id, token_hash, expires_at) VALUES (?, ?, ?)')
    ->execute([$visitorId, hash('sha256', $rawVisitorToken), date('Y-m-d H:i:s', time() + 3600)]);

// ---- Test 1: wallet auto-creates at zero balance ----
$wallet = $dispatch('GET', '/api/v1/profiles/chatowner/wallet', null, $rawVisitorToken);
cw_assert($wallet['status'] === 200 && (float) $wallet['body']['data']['balance_amount'] === 0.0, 'New wallet should start at 0: ' . json_encode($wallet));

// ---- Test 2: asking with zero balance is rejected (402), no session/messages created ----
$askNoBalance = $dispatch('POST', '/api/v1/profiles/chatowner/chatbot/messages', ['question' => 'Halo?'], $rawVisitorToken);
cw_assert($askNoBalance['status'] === 402, 'Asking with zero balance should 402: ' . json_encode($askNoBalance));
cw_assert((int) $db->query('SELECT COUNT(*) c FROM chat_sessions')->fetch()['c'] === 0, 'No session should be created when the question is rejected for insufficient balance');

// ---- Test 3: owner grants a deposit directly (no payment involved) ----
$grant = $dispatch('POST', "/api/v1/me/visitors/{$visitorPublicId}/wallet/grant", ['amount' => 20000, 'note' => 'Comp for testing'], $ownerToken);
cw_assert($grant['status'] === 200 && (float) $grant['body']['data']['balance_amount'] === 20000.0, 'Owner grant should credit the wallet: ' . json_encode($grant));

$visitorsList = $dispatch('GET', '/api/v1/me/visitors', null, $ownerToken);
cw_assert($visitorsList['status'] === 200 && count($visitorsList['body']['data']) === 1 && (float) $visitorsList['body']['data'][0]['balance_amount'] === 20000.0, 'Owner visitor list should show the granted balance: ' . json_encode($visitorsList));

// ---- Test 4: asking now debits atomically before calling the LLM, and refunds on failure (no real network here → the LLM call fails, so the charge must come back) ----
// ChatbotService::ask() throws a plain RuntimeException on an LLM-call
// failure (matching PaywuzGateway's convention for external-API failures),
// which only Router's caller (public/index.php's top-level catch-all, not
// exercised here) turns into a 500 JSON body — dispatching directly, as
// this test does, gets the exception itself instead.
$askFailed = false;
try {
    $dispatch('POST', '/api/v1/profiles/chatowner/chatbot/messages', ['question' => 'Halo?'], $rawVisitorToken);
} catch (RuntimeException $e) {
    $askFailed = true;
    cw_assert(str_contains($e->getMessage(), 'not charged'), 'Failure message should reassure the visitor they were not charged: ' . $e->getMessage());
}
cw_assert($askFailed, 'A failed LLM call should throw rather than silently succeed');

$walletAfterFailedAsk = $dispatch('GET', '/api/v1/profiles/chatowner/wallet', null, $rawVisitorToken);
cw_assert((float) $walletAfterFailedAsk['body']['data']['balance_amount'] === 20000.0, 'A failed chatbot call must refund the debited cost in full: ' . json_encode($walletAfterFailedAsk));

$transactionTypes = array_column($db->query('SELECT type FROM visitor_wallet_transactions ORDER BY id ASC')->fetchAll(), 'type');
cw_assert($transactionTypes === ['OWNER_GRANT', 'CHAT_COST', 'REFUND'], 'Wallet ledger should record grant, then the failed attempt debit+refund: ' . json_encode($transactionTypes));

// ---- Test 5: unauthenticated/owner-less access is rejected ----
cw_assert($dispatch('GET', '/api/v1/me/visitors')['status'] === 401, 'Listing visitors should require owner auth');
cw_assert($dispatch('POST', '/api/v1/profiles/chatowner/chatbot/messages', ['question' => 'x'])['status'] === 401, 'Asking without a visitor token should 401');

Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Chatbot wallet test passed\n");
