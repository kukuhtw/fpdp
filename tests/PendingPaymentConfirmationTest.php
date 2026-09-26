<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Contracts\GoogleOAuthClientInterface;
use App\Controllers\CvController;
use App\Controllers\PaymentController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Repositories\AuthTokenRepository;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Repositories\NodeRepository;
use App\Repositories\PaymentGatewayConfigRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use App\Repositories\VisitorWalletRepository;
use App\Services\Auth\AuthService;
use App\Services\Chatbot\VisitorWalletService;
use App\Services\Cv\CvAccessService;
use App\Services\Payment\PaymentService;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\VisitorAuthService;

final class FakeGoogleOAuthClientForPendingPaymentTest implements GoogleOAuthClientInterface
{
    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/fake?state=' . urlencode($state);
    }

    public function resolveVisitorProfile(string $code, string $redirectUri): array
    {
        return ['sub' => 'google-sub-pending-payment-test', 'email' => 'visitor@example.com', 'name' => 'Visitor', 'picture' => null];
    }
}

function ppc_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-pending-payment-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-pending-payment-test-' . uniqid() . '.env';
$storageDir = sys_get_temp_dir() . '/fpdp-pending-payment-storage-' . uniqid();
$appKey = bin2hex(random_bytes(32));

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
NODE_DOMAIN=test.local
AUTH_TOKEN_TTL=3600
VISITOR_TOKEN_TTL=3600
CV_MAX_FILE_SIZE_BYTES=1048576
APP_KEY={$appKey}
ENV);

Config::load($envPath);
Database::reset();
$connection = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, domain TEXT NOT NULL UNIQUE, name TEXT NOT NULL, default_locale TEXT NOT NULL DEFAULT "id", timezone TEXT NOT NULL DEFAULT "Asia/Jakarta", status TEXT NOT NULL DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, node_id INTEGER NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "OWNER", status TEXT NOT NULL DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL UNIQUE, handle TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL, bio TEXT, avatar_url TEXT, visibility TEXT NOT NULL DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, token_type TEXT NOT NULL DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE visitor_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, node_id INTEGER NOT NULL, google_sub TEXT NOT NULL, email TEXT NOT NULL, display_name TEXT, avatar_url TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (node_id, google_sub))',
    'CREATE TABLE visitor_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE cv_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, node_id INTEGER NOT NULL UNIQUE, title TEXT NOT NULL, storage_key TEXT NOT NULL, content_type TEXT NOT NULL DEFAULT "application/pdf", price_amount TEXT NOT NULL DEFAULT "0.00", price_currency TEXT NOT NULL DEFAULT "IDR", status TEXT NOT NULL DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE cv_access_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, cv_document_id INTEGER NOT NULL, visitor_id INTEGER NOT NULL, payment_reference TEXT, granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (cv_document_id, visitor_id))',
    'CREATE TABLE payment_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, adapter_class TEXT, description TEXT, status TEXT DEFAULT "ACTIVE", is_plugin INTEGER DEFAULT 0, config_keys_json TEXT, supports_refund INTEGER DEFAULT 1, supports_recurring INTEGER DEFAULT 0, supports_qris INTEGER DEFAULT 0, supports_va INTEGER DEFAULT 1, supports_credit_card INTEGER DEFAULT 0, supports_ewallet INTEGER DEFAULT 0)',
    'CREATE TABLE payment_gateway_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway_id INTEGER NOT NULL, config_key TEXT NOT NULL, encrypted_value TEXT NOT NULL, environment TEXT DEFAULT "SANDBOX", is_active INTEGER DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (gateway_id, config_key, environment))',
    'CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE, order_id TEXT NOT NULL, gateway_code TEXT NOT NULL, external_transaction_id TEXT, payment_method TEXT, currency TEXT NOT NULL DEFAULT "IDR", amount REAL NOT NULL DEFAULT 0, fee REAL NOT NULL DEFAULT 0, refunded_amount REAL NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT "PENDING", payment_url TEXT, metadata TEXT, expired_at TIMESTAMP, paid_at TIMESTAMP, refunded_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER NOT NULL, provider TEXT NOT NULL, external_id TEXT, event_type TEXT NOT NULL, status TEXT NOT NULL, payload TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (provider, external_id))',
    // NUMERIC affinity — SQLite compares TEXT lexicographically, which would
    // make VisitorWalletRepository::debit()'s balance comparison wrong here
    // even though it's correct against a real DECIMAL column in MySQL.
    'CREATE TABLE visitor_wallets (id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id INTEGER UNIQUE, balance_amount NUMERIC DEFAULT 0, currency TEXT DEFAULT "IDR", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE visitor_wallet_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, wallet_id INTEGER, type TEXT, amount NUMERIC, payment_id INTEGER, note TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $connection->exec($sql);
}

$authService = new AuthService(
    new NodeRepository($connection),
    new UserRepository($connection),
    new ProfileRepository($connection),
    new AuthTokenRepository($connection),
);
$registered = $authService->register([
    'email' => 'alice@example.com',
    'password' => 'correct horse battery',
    'handle' => 'alice',
    'display_name' => 'Alice Owner',
]);
$ownerToken = $registered['token']['access_token'];
$nodeId = (int) $registered['node']['id'];

$visitorAuthService = new VisitorAuthService(
    new FakeGoogleOAuthClientForPendingPaymentTest(),
    new VisitorRepository($connection),
    new VisitorTokenRepository($connection),
);
$visitorLogin = $visitorAuthService->handleCallback($nodeId, 'any-code', 'https://example.test/callback');
$visitorToken = $visitorLogin['token']['access_token'];
$visitor = $visitorLogin['visitor'];

$profileService = new ProfileService(new ProfileRepository($connection));
$documentService = new \App\Services\Cv\CvDocumentService(new CvDocumentRepository($connection), new CvAccessGrantRepository($connection), $storageDir);
$paymentService = new PaymentService(gatewayConfigs: new PaymentGatewayConfigRepository($connection), nodes: new NodeRepository($connection));
$accessService = new CvAccessService(
    new CvDocumentRepository($connection),
    new CvAccessGrantRepository($connection),
    $paymentService,
    new NodeRepository($connection),
    $storageDir,
);
$walletService = new VisitorWalletService(
    new VisitorWalletRepository($connection),
    new VisitorRepository($connection),
    $paymentService,
    new NodeRepository($connection),
);
$cvController = new CvController($authService, $profileService, $visitorAuthService, $documentService, $accessService);
$paymentController = new PaymentController($authService, $paymentService, $accessService, null, $walletService);

$router = new Router();
$router->post('/api/v1/me/cv', fn (Request $r, array $p) => $cvController->upload($r));
$router->post('/api/v1/profiles/{handle}/cv/access', fn (Request $r, array $p) => $cvController->grantAccess($r, $p));
$router->get('/api/v1/profiles/{handle}/cv/download', fn (Request $r, array $p) => $cvController->download($r, $p));
$router->get('/api/v1/me/payment-gateways', fn (Request $r, array $p) => (new \App\Controllers\PaymentController($authService, $paymentService, $accessService))->listGateways($r));
$router->patch('/api/v1/me/payment-gateways/{code}', fn (Request $r, array $p) => (new \App\Controllers\PaymentController($authService, $paymentService, $accessService))->updateGateway($r, $p));
$router->put('/api/v1/me/payment-gateways/{code}/activate', fn (Request $r, array $p) => (new \App\Controllers\PaymentController($authService, $paymentService, $accessService))->activateGateway($r, $p));
$router->get('/api/v1/me/payments/pending', fn (Request $r, array $p) => $paymentController->listPending($r));
$router->post('/api/v1/me/payments/{uuid}/confirm', fn (Request $r, array $p) => $paymentController->confirmPayment($r, $p));
$router->get('/api/v1/profiles/{handle}/wallet', fn (Request $r, array $p) => (new \App\Controllers\ChatbotController($authService, $profileService, $visitorAuthService, new \App\Services\Chatbot\ChatbotService(
    new \App\Repositories\ChatbotSettingsRepository($connection),
    new VisitorWalletRepository($connection),
    new \App\Repositories\ChatSessionRepository($connection),
    new \App\Repositories\ChatMessageRepository($connection),
    new \App\Repositories\RagFaqRepository($connection),
    new \App\Repositories\LlmConfigRepository($connection),
), $walletService))->getWallet($r, $p));
$router->post('/api/v1/profiles/{handle}/wallet/topup', fn (Request $r, array $p) => (new \App\Controllers\ChatbotController($authService, $profileService, $visitorAuthService, new \App\Services\Chatbot\ChatbotService(
    new \App\Repositories\ChatbotSettingsRepository($connection),
    new VisitorWalletRepository($connection),
    new \App\Repositories\ChatSessionRepository($connection),
    new \App\Repositories\ChatMessageRepository($connection),
    new \App\Repositories\RagFaqRepository($connection),
    new \App\Repositories\LlmConfigRepository($connection),
), $walletService))->topUp($r, $p));

function bearer(?string $token): array
{
    return $token === null ? [] : ['authorization' => 'Bearer ' . $token];
}

// Extra tables ChatbotSettingsRepository/RagFaqRepository/ChatSessionRepository/ChatMessageRepository/LlmConfigRepository touch.
foreach ([
    'CREATE TABLE chatbot_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER UNIQUE, price_per_question NUMERIC DEFAULT 0, currency TEXT DEFAULT "IDR", status TEXT DEFAULT "DISABLED", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rag_faqs (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, document_id INTEGER, question TEXT, answer TEXT, embedding TEXT, embedding_model TEXT, embedding_generated_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE chat_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, visitor_id INTEGER, message_count INTEGER DEFAULT 0, status TEXT DEFAULT "ACTIVE", started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_message_at TIMESTAMP)',
    'CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, role TEXT, content TEXT, cost_amount NUMERIC, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE llm_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER UNIQUE, provider_code TEXT, model TEXT, encrypted_api_key TEXT, supports_vision INTEGER DEFAULT 0, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $connection->exec($sql);
}

// ---- Setup: configure and activate MANUAL_TRANSFER (async — leaves payments PENDING, unlike DUMMY) ----
// Listing gateways first is what triggers plugin discovery/sync (the
// MANUAL_TRANSFER row only exists in `payment_gateways` after this) — same
// as PaymentGatewayPluginTest.php's Test 1.
$discover = $router->dispatch(new Request('GET', '/api/v1/me/payment-gateways', [], null, bearer($ownerToken)));
ppc_assert($discover->status === 200, "Discovering gateways failed: {$discover->body}");

$configure = $router->dispatch(new Request('PATCH', '/api/v1/me/payment-gateways/MANUAL_TRANSFER', [], json_encode([
    'environment' => 'LIVE',
    'config' => ['bank_name' => 'Bank Contoh', 'account_number' => '1234567890', 'account_holder' => 'Alice Owner', 'webhook_secret' => 'unused-in-this-test'],
]), bearer($ownerToken)));
ppc_assert($configure->status === 200, "Configuring MANUAL_TRANSFER failed: {$configure->body}");
$activate = $router->dispatch(new Request('PUT', '/api/v1/me/payment-gateways/MANUAL_TRANSFER/activate', [], null, bearer($ownerToken)));
ppc_assert($activate->status === 200, "Activating MANUAL_TRANSFER failed: {$activate->body}");

// 1. Unauthenticated access is rejected.
ppc_assert($router->dispatch(new Request('GET', '/api/v1/me/payments/pending'))->status === 401, 'Listing pending payments should require owner auth');
ppc_assert($router->dispatch(new Request('POST', '/api/v1/me/payments/some-uuid/confirm'))->status === 401, 'Confirming a payment should require owner auth');

// 2. Priced CV purchase creates a PENDING payment (purpose=cv_access).
$upload = $router->dispatch(new Request('POST', '/api/v1/me/cv', [], json_encode([
    'title' => 'Alice Resume', 'price_amount' => 50000, 'price_currency' => 'IDR',
    'content_type' => 'application/pdf', 'content_base64' => base64_encode('PDF bytes'),
]), bearer($ownerToken)));
ppc_assert($upload->status === 201, "Priced CV upload failed: {$upload->body}");

$access = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/cv/access', [], json_encode(['name' => 'Visitor One', 'phone' => '081234567890']), bearer($visitorToken)));
ppc_assert($access->status === 200, "Requesting CV access failed: {$access->body}");
$cvOrderId = json_decode($access->body, true)['data']['payment']['order_id'];

$pending = $router->dispatch(new Request('GET', '/api/v1/me/payments/pending', [], null, bearer($ownerToken)));
ppc_assert($pending->status === 200, "Listing pending payments failed: {$pending->body}");
$pendingData = json_decode($pending->body, true)['data'];
ppc_assert(count($pendingData) === 1, 'Exactly one payment should be pending (the CV purchase): ' . json_encode($pendingData));
ppc_assert($pendingData[0]['purpose'] === 'cv_access', 'The pending payment should be labelled cv_access');
ppc_assert($pendingData[0]['order_id'] === $cvOrderId, 'The pending payment should match the CV checkout order_id');
$cvPaymentUuid = $pendingData[0]['uuid'];

// 3. Download is denied before confirmation.
$deniedDownload = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv/download', [], null, bearer($visitorToken)));
ppc_assert($deniedDownload->status === 402, "Download before confirmation should 402, got {$deniedDownload->status}");

// 4. Owner manually confirms — this is the whole point of this feature:
// Manual Transfer has no automatic webhook, so this dashboard action IS
// how the payment ever gets fulfilled.
$confirm = $router->dispatch(new Request('POST', "/api/v1/me/payments/{$cvPaymentUuid}/confirm", [], null, bearer($ownerToken)));
ppc_assert($confirm->status === 200, "Confirming the payment failed: {$confirm->body}");
ppc_assert(json_decode($confirm->body, true)['data']['status'] === 'PAID', 'Confirmed payment should report status PAID');

$grantedDownload = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv/download', [], null, bearer($visitorToken)));
ppc_assert($grantedDownload->status === 200 && $grantedDownload->body === 'PDF bytes', 'CV download after manual confirmation should return the uploaded bytes');

$pendingAfter = $router->dispatch(new Request('GET', '/api/v1/me/payments/pending', [], null, bearer($ownerToken)));
ppc_assert(json_decode($pendingAfter->body, true)['data'] === [], 'The confirmed payment should no longer appear in the pending list');

// 5. Confirming an unknown uuid 404s.
ppc_assert($router->dispatch(new Request('POST', '/api/v1/me/payments/does-not-exist/confirm', [], null, bearer($ownerToken)))->status === 404, 'Confirming an unknown payment uuid should 404');

// 6. Wallet top-up: confirming twice (a double click) must credit only ONCE —
// this is the exact scenario confirmPaymentManually()'s status_changed
// guard exists to prevent.
$topup = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/wallet/topup', [], json_encode(['amount' => 20000, 'name' => 'Visitor One', 'phone' => '081234567890']), bearer($visitorToken)));
ppc_assert($topup->status === 201, "Wallet top-up request failed: {$topup->body}");
$walletOrderId = json_decode($topup->body, true)['data']['payment']['order_id'];

$pendingWallet = $router->dispatch(new Request('GET', '/api/v1/me/payments/pending', [], null, bearer($ownerToken)));
$walletPending = null;
foreach (json_decode($pendingWallet->body, true)['data'] as $p) {
    if ($p['order_id'] === $walletOrderId) {
        $walletPending = $p;
    }
}
ppc_assert($walletPending !== null && $walletPending['purpose'] === 'wallet_topup', 'The wallet top-up payment should be pending with purpose=wallet_topup');
$walletPaymentUuid = $walletPending['uuid'];

$confirmWallet1 = $router->dispatch(new Request('POST', "/api/v1/me/payments/{$walletPaymentUuid}/confirm", [], null, bearer($ownerToken)));
ppc_assert($confirmWallet1->status === 200, "First wallet confirmation failed: {$confirmWallet1->body}");
$balanceAfterFirst = (float) json_decode($router->dispatch(new Request('GET', '/api/v1/profiles/alice/wallet', [], null, bearer($visitorToken)))->body, true)['data']['balance_amount'];
ppc_assert($balanceAfterFirst === 20000.0, "Wallet should be credited 20000 after the first confirmation, got {$balanceAfterFirst}");

$confirmWallet2 = $router->dispatch(new Request('POST', "/api/v1/me/payments/{$walletPaymentUuid}/confirm", [], null, bearer($ownerToken)));
ppc_assert($confirmWallet2->status === 200, "A repeated (idempotent) confirmation should still succeed: {$confirmWallet2->body}");
$balanceAfterSecond = (float) json_decode($router->dispatch(new Request('GET', '/api/v1/profiles/alice/wallet', [], null, bearer($visitorToken)))->body, true)['data']['balance_amount'];
ppc_assert($balanceAfterSecond === 20000.0, "A double confirmation must NOT credit the wallet twice, got {$balanceAfterSecond}");

// 7. Confirming an already-CANCELLED payment is a 409, not silently accepted.
$connection->exec("UPDATE payments SET status = 'CANCELLED' WHERE uuid = '{$walletPaymentUuid}'");
$cancelledConfirm = $router->dispatch(new Request('POST', "/api/v1/me/payments/{$walletPaymentUuid}/confirm", [], null, bearer($ownerToken)));
ppc_assert($cancelledConfirm->status === 409, "Confirming an already-CANCELLED payment should 409, got {$cancelledConfirm->status}");

unset($router, $cvController, $paymentController, $accessService, $walletService, $paymentService, $documentService, $profileService, $visitorAuthService, $authService, $connection);
Database::reset();
gc_collect_cycles();
unlink($envPath);
unlink($dbPath);
array_map('unlink', glob($storageDir . '/*'));
@rmdir($storageDir);

fwrite(STDOUT, "Pending payment confirmation test passed\n");
