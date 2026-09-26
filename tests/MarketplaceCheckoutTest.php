<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Contracts\GoogleOAuthClientInterface;
use App\Controllers\MarketplaceController;
use App\Controllers\PaymentController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Repositories\AuthTokenRepository;
use App\Repositories\NodeRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentGatewayConfigRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use App\Services\Auth\AuthService;
use App\Services\Marketplace\MarketplaceService;
use App\Services\Payment\PaymentService;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\VisitorAuthService;

final class FakeGoogleOAuthClientForCheckout implements GoogleOAuthClientInterface
{
    public function __construct(private readonly string $sub, private readonly string $email, private readonly string $name)
    {
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/fake?state=' . urlencode($state);
    }

    public function resolveVisitorProfile(string $code, string $redirectUri): array
    {
        return ['sub' => $this->sub, 'email' => $this->email, 'name' => $this->name, 'picture' => null];
    }
}

function assert_that(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-checkout-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-checkout-test-' . uniqid() . '.env';
$appKey = bin2hex(random_bytes(32));

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
NODE_DOMAIN=test.local
AUTH_TOKEN_TTL=3600
VISITOR_TOKEN_TTL=3600
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
    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        price TEXT NOT NULL DEFAULT "0",
        currency TEXT NOT NULL DEFAULT "IDR",
        product_type TEXT NOT NULL DEFAULT "PHYSICAL",
        digital_asset_url TEXT,
        digital_asset_metadata TEXT,
        status TEXT NOT NULL DEFAULT "ACTIVE",
        visibility TEXT NOT NULL DEFAULT "PUBLIC",
        is_promoted INTEGER NOT NULL DEFAULT 0,
        media TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL,
        visitor_id INTEGER,
        buyer_email TEXT,
        buyer_name TEXT,
        status TEXT NOT NULL DEFAULT "PENDING",
        total_amount TEXT NOT NULL DEFAULT "0",
        currency TEXT NOT NULL DEFAULT "IDR",
        notes TEXT,
        shipping_address TEXT,
        payment_reference TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        product_snapshot TEXT,
        quantity INTEGER NOT NULL DEFAULT 1,
        unit_price TEXT NOT NULL DEFAULT "0",
        subtotal TEXT NOT NULL DEFAULT "0",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
$connection->exec("INSERT INTO payment_gateways (code, name) VALUES ('DUMMY', 'Dummy')");
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

// Register a real owner (the shop) via the real AuthService.
$authService = new AuthService(
    new NodeRepository($connection),
    new UserRepository($connection),
    new ProfileRepository($connection),
    new AuthTokenRepository($connection),
);
$registered = $authService->register([
    'email' => 'shop@example.com',
    'password' => 'correct horse battery',
    'handle' => 'shop',
    'display_name' => 'Shop Owner',
]);
$ownerToken = $registered['token']['access_token'];
$nodeId = (int) $registered['node']['id'];

// Two distinct visitors (real Google identities) to prove buyer-scoped access control.
$buyerAuth = new VisitorAuthService(new FakeGoogleOAuthClientForCheckout('sub-buyer', 'buyer@example.com', 'Buyer One'), new VisitorRepository($connection), new VisitorTokenRepository($connection));
$buyerToken = $buyerAuth->handleCallback($nodeId, 'any-code', 'https://example.test/callback')['token']['access_token'];

$otherAuth = new VisitorAuthService(new FakeGoogleOAuthClientForCheckout('sub-other', 'other@example.com', 'Someone Else'), new VisitorRepository($connection), new VisitorTokenRepository($connection));
$otherToken = $otherAuth->handleCallback($nodeId, 'any-code', 'https://example.test/callback')['token']['access_token'];

// A single shared VisitorAuthService instance (any of the two above works — authenticate() just reads the token).
$visitorAuth = $buyerAuth;

$profileService = new ProfileService(new ProfileRepository($connection));
$paymentService = new PaymentService(gatewayConfigs: new PaymentGatewayConfigRepository($connection), nodes: new NodeRepository($connection));
$marketplace = new MarketplaceService(
    new ProductRepository($connection),
    new OrderRepository($connection),
    new OrderItemRepository($connection),
    $paymentService,
    new NodeRepository($connection),
);
$marketplaceController = new MarketplaceController($authService, $marketplace, null, $profileService, $visitorAuth);
$paymentController = new PaymentController($authService, $paymentService, new App\Services\Cv\CvAccessService(
    new App\Repositories\CvDocumentRepository($connection),
    new App\Repositories\CvAccessGrantRepository($connection),
    $paymentService,
    new NodeRepository($connection),
    sys_get_temp_dir(),
), $marketplace);

$router = new Router();
$router->post('/api/v1/products', fn (Request $r, array $p) => $marketplaceController->createProduct($r));
$router->get('/api/v1/products/{productId}', fn (Request $r, array $p) => $marketplaceController->showProduct($r, $p));
$router->get('/api/v1/products/{productId}/download', fn (Request $r, array $p) => $marketplaceController->getDigitalDownload($r, $p));
$router->post('/api/v1/profiles/{handle}/orders', fn (Request $r, array $p) => $marketplaceController->checkout($r, $p));
$router->get('/api/v1/orders/{orderId}', fn (Request $r, array $p) => $marketplaceController->showOrder($r, $p));
$router->get('/api/v1/me/payment-gateways', fn (Request $r, array $p) => $paymentController->listGateways($r));
$router->patch('/api/v1/me/payment-gateways/{code}', fn (Request $r, array $p) => $paymentController->updateGateway($r, $p));
$router->put('/api/v1/me/payment-gateways/{code}/activate', fn (Request $r, array $p) => $paymentController->activateGateway($r, $p));
$router->post('/api/v1/payments/webhook/{gateway}', fn (Request $r, array $p) => $paymentController->webhook($r, $p));

function bearer(?string $token): array
{
    return $token === null ? [] : ['authorization' => 'Bearer ' . $token];
}

// 1. Create a physical and a digital product as the shop owner.
$physical = $router->dispatch(new Request('POST', '/api/v1/products', [], json_encode([
    'title' => 'Sticker Pack', 'description' => 'Vinyl stickers', 'price' => 25000, 'currency' => 'IDR',
]), bearer($ownerToken)));
assert_that($physical->status === 201, "Creating the physical product failed: {$physical->body}");
$physicalId = json_decode($physical->body, true)['data']['public_id'];

$digital = $router->dispatch(new Request('POST', '/api/v1/products', [], json_encode([
    'title' => 'E-book', 'price' => 50000, 'currency' => 'IDR',
    'product_type' => 'DIGITAL', 'digital_asset_url' => 'https://cdn.example.com/ebook.pdf',
]), bearer($ownerToken)));
assert_that($digital->status === 201, "Creating the digital product failed: {$digital->body}");
$digitalId = json_decode($digital->body, true)['data']['public_id'];

// 2. Checkout without a visitor token is rejected.
$unauthCheckout = $router->dispatch(new Request('POST', '/api/v1/profiles/shop/orders', [], json_encode(['items' => [['product_id' => $physicalId, 'quantity' => 1]]])));
assert_that($unauthCheckout->status === 401, "Checkout without a visitor token did not return 401, got {$unauthCheckout->status}");

// 3. Checkout before any gateway is active is refused with a clear error, not a silent charge.
$noGateway = $router->dispatch(new Request('POST', '/api/v1/profiles/shop/orders', [], json_encode(['items' => [['product_id' => $physicalId, 'quantity' => 1]], 'shipping_address' => 'Jl. Contoh No. 1, Jakarta']), bearer($buyerToken)));
assert_that($noGateway->status === 409, "Checkout with no active gateway did not return 409, got {$noGateway->status}: {$noGateway->body}");

// The owner activates DUMMY (no credentials required for it, same as CV's flow).
$activate = $router->dispatch(new Request('PUT', '/api/v1/me/payment-gateways/DUMMY/activate', [], null, bearer($ownerToken)));
assert_that($activate->status === 200, "Activating DUMMY failed: {$activate->body}");

// 4. Real checkout: DUMMY confirms synchronously, so the order is COMPLETED immediately and carries the buyer's visitor_id.
$checkout = $router->dispatch(new Request('POST', '/api/v1/profiles/shop/orders', [], json_encode([
    'items' => [['product_id' => $physicalId, 'quantity' => 2]],
    'shipping_address' => 'Jl. Contoh No. 1, Jakarta',
]), bearer($buyerToken)));
assert_that($checkout->status === 201, "Checkout failed: {$checkout->body}");
$checkoutData = json_decode($checkout->body, true)['data'];
assert_that($checkoutData['order']['status'] === 'COMPLETED', 'Synchronous-gateway checkout should complete the order immediately');
assert_that((float) $checkoutData['order']['total_amount'] === 50000.0, 'Order total should reflect quantity 2 x price 25000');
assert_that($checkoutData['payment']['gateway'] === 'DUMMY', 'Checkout payment was not created via the active DUMMY gateway');
assert_that($checkoutData['order']['buyer_email'] === 'buyer@example.com', "Order did not capture the visitor's email as buyer_email");

// A store owner or anyone with the order id can still read it back publicly (unguessable UUID = access control, same as elsewhere).
$orderId = $checkoutData['order']['public_id'];
$readOrder = $router->dispatch(new Request('GET', "/api/v1/orders/{$orderId}"));
assert_that($readOrder->status === 200 && json_decode($readOrder->body, true)['data']['status'] === 'COMPLETED', 'Order was not readable/COMPLETED after checkout');

// 5. Checking out the digital product completes it too, unlocking the download for the actual buyer only.
$digitalCheckout = $router->dispatch(new Request('POST', '/api/v1/profiles/shop/orders', [], json_encode([
    'items' => [['product_id' => $digitalId, 'quantity' => 1]],
]), bearer($buyerToken)));
assert_that($digitalCheckout->status === 201 && json_decode($digitalCheckout->body, true)['data']['order']['status'] === 'COMPLETED', "Digital checkout failed: {$digitalCheckout->body}");

$downloadNoAuth = $router->dispatch(new Request('GET', "/api/v1/products/{$digitalId}/download"));
assert_that($downloadNoAuth->status === 401, "Download without any token did not return 401, got {$downloadNoAuth->status}");

$downloadWrongBuyer = $router->dispatch(new Request('GET', "/api/v1/products/{$digitalId}/download", [], null, bearer($otherToken)));
assert_that($downloadWrongBuyer->status === 403, "A visitor who never bought this product should get 403, got {$downloadWrongBuyer->status}");

$downloadOk = $router->dispatch(new Request('GET', "/api/v1/products/{$digitalId}/download", [], null, bearer($buyerToken)));
assert_that($downloadOk->status === 200, "The actual buyer's digital download failed: {$downloadOk->body}");
assert_that(json_decode($downloadOk->body, true)['data']['digital_asset_url'] === 'https://cdn.example.com/ebook.pdf', 'Download did not return the product\'s digital asset URL');

// 6. Fulfillment-webhook wiring for a REAL asynchronous gateway:
// PaymentController::fulfill() must recognize a 'marketplace_order'
// purpose and mark the corresponding order COMPLETED once the gateway's
// webhook confirms payment — DUMMY is a poor fit for this (it's always
// completed synchronously, and its own handleWebhook() never even echoes
// back an order_id). The "manual-transfer" gateway plugin (from the
// payment-gateway-plugin feature) is a genuine async gateway, so use it —
// this also doubles as proof that a plugin-provided gateway works for
// marketplace checkout, not just for CV access.
$gatewayList = $router->dispatch(new Request('GET', '/api/v1/me/payment-gateways', [], null, bearer($ownerToken)));
assert_that($gatewayList->status === 200, "Listing gateways failed: {$gatewayList->body}");
$manualEntry = null;
foreach (json_decode($gatewayList->body, true)['data']['gateways'] as $gw) {
    if ($gw['code'] === 'MANUAL_TRANSFER') $manualEntry = $gw;
}
assert_that($manualEntry !== null, 'The manual-transfer gateway plugin was not discovered');

$webhookSecret = 'wh-secret-' . bin2hex(random_bytes(4));
$configureManual = $router->dispatch(new Request('PATCH', '/api/v1/me/payment-gateways/MANUAL_TRANSFER', [], json_encode([
    'environment' => 'LIVE',
    'config' => ['bank_name' => 'Bank Contoh', 'account_number' => '123', 'account_holder' => 'Shop Owner', 'webhook_secret' => $webhookSecret],
]), bearer($ownerToken)));
assert_that($configureManual->status === 200, "Configuring manual-transfer failed: {$configureManual->body}");
$activateManual = $router->dispatch(new Request('PUT', '/api/v1/me/payment-gateways/MANUAL_TRANSFER/activate', [], null, bearer($ownerToken)));
assert_that($activateManual->status === 200, "Activating manual-transfer failed: {$activateManual->body}");

$asyncCheckout = $router->dispatch(new Request('POST', '/api/v1/profiles/shop/orders', [], json_encode([
    'items' => [['product_id' => $physicalId, 'quantity' => 1]],
    'shipping_address' => 'Jl. Contoh No. 1, Jakarta',
]), bearer($buyerToken)));
assert_that($asyncCheckout->status === 201, "Async-gateway checkout failed: {$asyncCheckout->body}");
$asyncData = json_decode($asyncCheckout->body, true)['data'];
assert_that($asyncData['order']['status'] === 'PENDING', 'An async-gateway order must stay PENDING until the webhook confirms it');
assert_that($asyncData['payment']['gateway'] === 'MANUAL_TRANSFER', 'Checkout did not charge the newly activated plugin gateway');
$pendingOrderId = $asyncData['order']['public_id'];
$paymentOrderId = $asyncData['payment']['order_id'];

$webhook = $router->dispatch(new Request(
    'POST',
    '/api/v1/payments/webhook/MANUAL_TRANSFER',
    [],
    json_encode(['order_id' => $paymentOrderId, 'status' => 'PAID']),
    ['x-webhook-secret' => $webhookSecret],
));
assert_that($webhook->status === 200, "Webhook dispatch failed: {$webhook->body}");
$afterWebhook = $router->dispatch(new Request('GET', "/api/v1/orders/{$pendingOrderId}"));
assert_that(json_decode($afterWebhook->body, true)['data']['status'] === 'COMPLETED', 'PaymentController::fulfill() did not complete the marketplace order via its webhook branch');

unset($router, $marketplaceController, $paymentController, $marketplace, $paymentService, $profileService, $visitorAuth, $authService, $connection);
Database::reset();
gc_collect_cycles();
unlink($envPath);
unlink($dbPath);

fwrite(STDOUT, "Marketplace checkout test passed\n");
