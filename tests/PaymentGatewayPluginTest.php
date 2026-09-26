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
use App\Services\Auth\AuthService;
use App\Services\Cv\CvAccessService;
use App\Services\Cv\CvDocumentService;
use App\Services\Payment\PaymentService;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\VisitorAuthService;

final class FakeGoogleOAuthClientForGatewayPlugin implements GoogleOAuthClientInterface
{
    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/fake?state=' . urlencode($state);
    }

    public function resolveVisitorProfile(string $code, string $redirectUri): array
    {
        return ['sub' => 'google-sub-gateway-plugin-test', 'email' => 'visitor@example.com', 'name' => 'Visitor', 'picture' => null];
    }
}

function assert_that(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-gateway-plugin-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-gateway-plugin-test-' . uniqid() . '.env';
$storageDir = sys_get_temp_dir() . '/fpdp-gateway-plugin-storage-' . uniqid();
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

$connection->exec('
    CREATE TABLE nodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        domain TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        default_locale TEXT NOT NULL DEFAULT "id",
        timezone TEXT NOT NULL DEFAULT "Asia/Jakarta",
        status TEXT NOT NULL DEFAULT "ACTIVE",
        active_gateway TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "OWNER",
        status TEXT NOT NULL DEFAULT "ACTIVE",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        user_id INTEGER NOT NULL UNIQUE,
        handle TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        bio TEXT,
        avatar_url TEXT,
        visibility TEXT NOT NULL DEFAULT "PUBLIC",
        links TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE auth_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        token_type TEXT NOT NULL DEFAULT "ACCESS",
        scopes TEXT,
        expires_at TIMESTAMP NOT NULL,
        revoked_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE visitor_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL,
        google_sub TEXT NOT NULL,
        email TEXT NOT NULL,
        display_name TEXT,
        avatar_url TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (node_id, google_sub)
    )
');
$connection->exec('
    CREATE TABLE visitor_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visitor_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TIMESTAMP NOT NULL,
        revoked_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE cv_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL UNIQUE,
        title TEXT NOT NULL,
        storage_key TEXT NOT NULL,
        content_type TEXT NOT NULL DEFAULT "application/pdf",
        price_amount TEXT NOT NULL DEFAULT "0.00",
        price_currency TEXT NOT NULL DEFAULT "IDR",
        status TEXT NOT NULL DEFAULT "ACTIVE",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE cv_access_grants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cv_document_id INTEGER NOT NULL,
        visitor_id INTEGER NOT NULL,
        payment_reference TEXT,
        granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (cv_document_id, visitor_id)
    )
');
$connection->exec('
    CREATE TABLE payment_gateways (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE,
        name TEXT,
        adapter_class TEXT,
        description TEXT,
        status TEXT DEFAULT "ACTIVE",
        is_plugin INTEGER DEFAULT 0,
        config_keys_json TEXT,
        supports_refund INTEGER DEFAULT 1,
        supports_recurring INTEGER DEFAULT 0,
        supports_qris INTEGER DEFAULT 0,
        supports_va INTEGER DEFAULT 1,
        supports_credit_card INTEGER DEFAULT 0,
        supports_ewallet INTEGER DEFAULT 0
    )
');
$connection->exec('
    CREATE TABLE payment_gateway_configs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        gateway_id INTEGER NOT NULL,
        config_key TEXT NOT NULL,
        encrypted_value TEXT NOT NULL,
        environment TEXT DEFAULT "SANDBOX",
        is_active INTEGER DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (gateway_id, config_key, environment)
    )
');
$connection->exec('
    CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL UNIQUE,
        order_id TEXT NOT NULL,
        gateway_code TEXT NOT NULL,
        external_transaction_id TEXT,
        payment_method TEXT,
        currency TEXT NOT NULL DEFAULT "IDR",
        amount REAL NOT NULL DEFAULT 0,
        fee REAL NOT NULL DEFAULT 0,
        refunded_amount REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT "PENDING",
        payment_url TEXT,
        metadata TEXT,
        expired_at TIMESTAMP,
        paid_at TIMESTAMP,
        refunded_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE payment_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payment_id INTEGER NOT NULL,
        provider TEXT NOT NULL,
        external_id TEXT,
        event_type TEXT NOT NULL,
        status TEXT NOT NULL,
        payload TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (provider, external_id)
    )
');

// Register a real owner via the real AuthService.
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

// Resolve a visitor via the real VisitorAuthService with a fake Google client.
$visitorAuthService = new VisitorAuthService(
    new FakeGoogleOAuthClientForGatewayPlugin(),
    new VisitorRepository($connection),
    new VisitorTokenRepository($connection),
);
$visitorToken = $visitorAuthService->handleCallback($nodeId, 'any-code', 'https://example.test/callback')['token']['access_token'];

$profileService = new ProfileService(new ProfileRepository($connection));
$documentService = new CvDocumentService(new CvDocumentRepository($connection), new CvAccessGrantRepository($connection), $storageDir);
$paymentService = new PaymentService(gatewayConfigs: new PaymentGatewayConfigRepository($connection), nodes: new NodeRepository($connection));
$accessService = new CvAccessService(
    new CvDocumentRepository($connection),
    new CvAccessGrantRepository($connection),
    $paymentService,
    new NodeRepository($connection),
    $storageDir,
);
$cvController = new CvController($authService, $profileService, $visitorAuthService, $documentService, $accessService);
$paymentController = new PaymentController($authService, $paymentService, $accessService);

$router = new Router();
$router->post('/api/v1/me/cv', fn (Request $r, array $p) => $cvController->upload($r));
$router->get('/api/v1/profiles/{handle}/cv', fn (Request $r, array $p) => $cvController->show($r, $p));
$router->post('/api/v1/profiles/{handle}/cv/access', fn (Request $r, array $p) => $cvController->grantAccess($r, $p));
$router->get('/api/v1/profiles/{handle}/cv/download', fn (Request $r, array $p) => $cvController->download($r, $p));
$router->get('/api/v1/me/payment-gateways', fn (Request $r, array $p) => $paymentController->listGateways($r));
$router->patch('/api/v1/me/payment-gateways/{code}', fn (Request $r, array $p) => $paymentController->updateGateway($r, $p));
$router->put('/api/v1/me/payment-gateways/{code}/activate', fn (Request $r, array $p) => $paymentController->activateGateway($r, $p));
$router->post('/api/v1/payments/webhook/{gateway}', fn (Request $r, array $p) => $paymentController->webhook($r, $p));

function bearer(?string $token): array
{
    return $token === null ? [] : ['authorization' => 'Bearer ' . $token];
}

// 1. Discovery: the manual-transfer plugin folder under /gateways is picked up
// and appears in the owner's gateway list, without ever needing a migration
// or manual DB row for it.
$list = $router->dispatch(new Request('GET', '/api/v1/me/payment-gateways', [], null, bearer($ownerToken)));
assert_that($list->status === 200, "Listing gateways failed: {$list->body}");
$listData = json_decode($list->body, true)['data'];
$manualEntry = null;
foreach ($listData['gateways'] as $gw) {
    if ($gw['code'] === 'MANUAL_TRANSFER') {
        $manualEntry = $gw;
    }
}
assert_that($manualEntry !== null, 'The manual-transfer plugin gateway was not discovered/listed');
assert_that($manualEntry['is_plugin'] === true, 'The plugin gateway was not flagged is_plugin=true');
assert_that(
    $manualEntry['allowed_config_keys'] === ['bank_name', 'account_number', 'account_holder', 'webhook_secret'],
    'Plugin config keys did not come from its own gateway.json, got: ' . json_encode($manualEntry['allowed_config_keys']),
);

// 2. Owner configures and activates the plugin gateway through the exact
// same API used for built-in gateways — no plugin-specific endpoint exists.
$webhookSecret = 'wh-secret-' . bin2hex(random_bytes(4));
$configure = $router->dispatch(new Request('PATCH', '/api/v1/me/payment-gateways/MANUAL_TRANSFER', [], json_encode([
    'environment' => 'LIVE',
    'config' => [
        'bank_name' => 'Bank Contoh',
        'account_number' => '1234567890',
        'account_holder' => "Alice O'Brien",
        'webhook_secret' => $webhookSecret,
    ],
]), bearer($ownerToken)));
assert_that($configure->status === 200, "Configuring the plugin gateway failed: {$configure->body}");
$configureData = json_decode($configure->body, true)['data'];
assert_that($configureData['provider_check'] === 'NOT_VERIFIED_PLUGIN_GATEWAY', 'Plugin gateways must skip the built-in live-credential verifier');

$activate = $router->dispatch(new Request('PUT', '/api/v1/me/payment-gateways/MANUAL_TRANSFER/activate', [], null, bearer($ownerToken)));
assert_that($activate->status === 200, "Activating the plugin gateway failed: {$activate->body}");

// 2b. Non-secret fields (bank_name, account_number, account_holder) round-trip
// back to the owner so they aren't forced to retype them on every edit —
// but the true credential (webhook_secret) never does, even though it was
// just saved successfully. A value containing an apostrophe (account_holder)
// also exercises that special characters survive encrypt/decrypt intact.
$listAfterConfigure = $router->dispatch(new Request('GET', '/api/v1/me/payment-gateways', [], null, bearer($ownerToken)));
$manualGateway = null;
foreach (json_decode($listAfterConfigure->body, true)['data']['gateways'] as $gw) {
    if ($gw['code'] === 'MANUAL_TRANSFER') {
        $manualGateway = $gw;
    }
}
assert_that($manualGateway !== null, 'MANUAL_TRANSFER should still be listed after configuring it');
$liveEnv = null;
foreach ($manualGateway['environments'] as $env) {
    if ($env['environment'] === 'LIVE') {
        $liveEnv = $env;
    }
}
assert_that($liveEnv !== null, 'LIVE environment should be present after configuring it');
assert_that($liveEnv['values']['bank_name'] === 'Bank Contoh', 'bank_name should round-trip back to the owner: ' . json_encode($liveEnv['values']));
assert_that($liveEnv['values']['account_number'] === '1234567890', 'account_number should round-trip back to the owner');
assert_that($liveEnv['values']['account_holder'] === "Alice O'Brien", 'account_holder should round-trip back to the owner with special characters intact');
assert_that(!array_key_exists('webhook_secret', $liveEnv['values']), 'webhook_secret must NEVER be returned, even though it was just saved: ' . json_encode($liveEnv['values']));

// 3. Owner uploads a priced CV.
$fileBytes = 'PDF-ish content for the priced CV.';
$upload = $router->dispatch(new Request('POST', '/api/v1/me/cv', [], json_encode([
    'title' => 'Alice Resume',
    'price_amount' => 50000,
    'price_currency' => 'IDR',
    'content_type' => 'application/pdf',
    'content_base64' => base64_encode($fileBytes),
]), bearer($ownerToken)));
assert_that($upload->status === 201, "Priced CV upload failed: {$upload->body}");

// 4. Visitor requests access: the CV purchase flow (unchanged CV/payment
// code) resolves MANUAL_TRANSFER purely from nodes.active_gateway and
// creates a PENDING payment carrying this plugin's own instructions —
// this is "install and switch payment gateway provider, wired into View
// CV/Resume" working end to end.
$access = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/cv/access', [], json_encode(['name' => 'Alice Buyer', 'phone' => '081234567890']), bearer($visitorToken)));
assert_that($access->status === 200, "Requesting CV access failed: {$access->body}");
$accessData = json_decode($access->body, true)['data'];
assert_that($accessData['granted'] === false, 'A plugin (async) gateway must not grant access synchronously');
assert_that($accessData['payment']['gateway'] === 'MANUAL_TRANSFER', 'The created payment was not created via the plugin gateway');
assert_that($accessData['payment']['status'] === 'PENDING', 'A manual-transfer payment should start PENDING');
assert_that(str_contains((string) $accessData['payment']['instructions'], 'Bank Contoh'), 'Payment instructions did not include the configured bank name');
$orderId = $accessData['payment']['order_id'];

$deniedDownload = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv/download', [], null, bearer($visitorToken)));
assert_that($deniedDownload->status === 402, "Download before payment confirmation should 402, got {$deniedDownload->status}");

// 5. A webhook confirmation with the wrong secret is rejected.
$badWebhook = $router->dispatch(new Request(
    'POST',
    '/api/v1/payments/webhook/MANUAL_TRANSFER',
    [],
    json_encode(['order_id' => $orderId, 'status' => 'PAID']),
    ['x-webhook-secret' => 'wrong-secret'],
));
assert_that($badWebhook->status === 401, "Webhook with the wrong secret should be rejected, got {$badWebhook->status}");

// 6. The owner confirms the transfer arrived: a correctly-authenticated
// webhook call marks the payment PAID and grants CV access — exactly the
// same fulfil() dispatch PayPal/Midtrans/Paywuz go through.
$goodWebhook = $router->dispatch(new Request(
    'POST',
    '/api/v1/payments/webhook/MANUAL_TRANSFER',
    [],
    json_encode(['order_id' => $orderId, 'status' => 'PAID']),
    ['x-webhook-secret' => $webhookSecret],
));
assert_that($goodWebhook->status === 200, "Webhook confirmation failed: {$goodWebhook->body}");
$webhookData = json_decode($goodWebhook->body, true)['data'];
assert_that($webhookData['duplicate'] === false, 'The confirmation webhook was incorrectly treated as a duplicate');

$grantedDownload = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv/download', [], null, bearer($visitorToken)));
assert_that($grantedDownload->status === 200 && $grantedDownload->body === $fileBytes, 'CV download after plugin-gateway payment confirmation did not return the uploaded bytes');

unset($router, $cvController, $paymentController, $accessService, $paymentService, $documentService, $profileService, $visitorAuthService, $authService, $connection);
Database::reset();
gc_collect_cycles();
unlink($envPath);
unlink($dbPath);
array_map('unlink', glob($storageDir . '/*'));
@rmdir($storageDir);

fwrite(STDOUT, "Payment gateway plugin test passed\n");
