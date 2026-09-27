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

final class FakeGoogleOAuthClientForDownload implements GoogleOAuthClientInterface
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

$dbPath = sys_get_temp_dir() . '/fpdp-download-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-download-test-' . uniqid() . '.env';
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
        revoked_at TIMESTAMP, public_id TEXT UNIQUE, user_agent TEXT, ip_hint TEXT, last_used_at TIMESTAMP,
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
foreach ([
    'CREATE TABLE product_digital_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, kind TEXT, storage_key TEXT, original_filename TEXT, content_type TEXT, size_bytes INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (product_id, kind))',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, actor_user_id INTEGER, action TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $connection->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$call = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($router) {
    return $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $token === null ? [] : ['authorization' => "Bearer {$token}"]));
};

$register = json_decode($call('POST', '/api/v1/auth/register', ['email' => 'shop@example.com', 'password' => 'correct horse battery', 'handle' => 'shop', 'display_name' => 'Shop'])->body, true);
$ownerToken = $register['data']['token']['access_token'];
$nodeId = (int) $connection->query('SELECT id FROM nodes')->fetchColumn();
assert_that($call('PUT', '/api/v1/me/payment-gateways/DUMMY/activate', null, $ownerToken)->status === 200, 'activate DUMMY');

$created = json_decode($call('POST', '/api/v1/products', ['title' => 'Ebook Rust', 'price' => 50000, 'currency' => 'IDR', 'product_type' => 'DIGITAL'], $ownerToken)->body, true);
$productId = $created['data']['public_id'] ?? $created['data']['id'];
$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
$upload = $call('POST', "/api/v1/me/products/{$productId}/digital-assets", ['kind' => 'PDF', 'filename' => 'ebook-rust.pdf', 'content_base64' => base64_encode($pdf)], $ownerToken);
assert_that($upload->status === 201, 'upload: ' . $upload->body);

$buyer = new VisitorAuthService(new FakeGoogleOAuthClientForDownload('sub-buyer', 'buyer@example.com', 'Buyer'), new VisitorRepository($connection), new VisitorTokenRepository($connection));
$buyerToken = $buyer->handleCallback($nodeId, 'code', 'https://example.test/cb')['token']['access_token'];
$checkout = $call('POST', '/api/v1/profiles/shop/orders', ['items' => [['product_id' => $productId, 'quantity' => 1]]], $buyerToken);
assert_that($checkout->status === 201, 'checkout: ' . $checkout->body);

// Uploaded files land in the real storage directory: remove them however the test ends.
register_shutdown_function(static function () use ($connection): void {
    foreach ($connection->query('SELECT storage_key FROM product_digital_assets')->fetchAll(PDO::FETCH_COLUMN) as $key) {
        @unlink(dirname(__DIR__) . '/storage/products/' . $key);
    }
});

// 1. The buyer gets the file list, each file with a short-lived signed link.
$info = $call('GET', "/api/v1/products/{$productId}/download", null, $buyerToken);
assert_that($info->status === 200, 'download info: ' . $info->body);
$asset = json_decode($info->body, true)['data']['digital_assets'][0];
assert_that($asset['kind'] === 'PDF' && $asset['original_filename'] === 'ebook-rust.pdf', 'asset listed: ' . json_encode($asset));
assert_that(str_starts_with($asset['download_url'], "/api/v1/products/{$productId}/digital-assets/pdf/file?visitor="), 'signed link: ' . $asset['download_url']);
assert_that(strtotime($asset['download_expires_at']) > time() + 800, 'link valid ~15 minutes');

// 2. The signed link downloads the file with no Authorization header (a plain <a href>).
$link = static function (string $url, array $override = []) use ($router) {
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $router->dispatch(new Request('GET', $path, $override + $query, null, []));
};
$file = $link($asset['download_url']);
assert_that($file->status === 200 && $file->body === $pdf, 'the signed link serves the file: ' . $file->status);
assert_that($file->headers['Content-Type'] === 'application/pdf' && str_contains($file->headers['Content-Disposition'], 'filename="ebook-rust.pdf"'), 'download headers: ' . json_encode($file->headers));

// 3. The bearer endpoint still works for older pages.
$bearer = $call('GET', "/api/v1/products/{$productId}/digital-assets/PDF/download", null, $buyerToken);
assert_that($bearer->status === 200 && $bearer->body === $pdf, 'bearer download still works');

// 4. Links cannot be forged, stretched, or moved to another visitor.
parse_str((string) parse_url($asset['download_url'], PHP_URL_QUERY), $q);
assert_that($link($asset['download_url'], ['token' => str_repeat('0', 64)])->status === 403, 'a forged token is refused');
assert_that($link($asset['download_url'], ['expires' => (string) ((int) $q['expires'] + 3600)])->status === 403, 'the expiry cannot be stretched');
assert_that($link($asset['download_url'], ['visitor' => (string) ((int) $q['visitor'] + 1)])->status === 403, 'the link is bound to the buyer');
assert_that($link(str_replace('/pdf/file', '/source_code/file', $asset['download_url']))->status === 403, 'the link is bound to the file kind');
$expired = hash_hmac('sha256', "fpdp-asset-download|{$productId}|PDF|{$q['visitor']}|1000", $appKey);
assert_that($link($asset['download_url'], ['expires' => '1000', 'token' => $expired])->status === 403, 'an expired link is refused');

// 5. Without a purchase there is no link, and the purchase is re-checked at download time.
$other = new VisitorAuthService(new FakeGoogleOAuthClientForDownload('sub-other', 'other@example.com', 'Other'), new VisitorRepository($connection), new VisitorTokenRepository($connection));
$otherToken = $other->handleCallback($nodeId, 'code', 'https://example.test/cb')['token']['access_token'];
assert_that($call('GET', "/api/v1/products/{$productId}/download", null, $otherToken)->status === 403, 'no purchase, no download list');
$connection->exec("UPDATE orders SET status = 'REFUNDED'");
assert_that($link($asset['download_url'])->status === 403, 'a refund stops a link already handed out');

// 6. Non-ASCII filenames are sent safely (RFC 6266 filename*).
$header = App\Core\Http\Response::binary('x', 'application/pdf', 'Ebook Rust — edisi "2".pdf')->headers['Content-Disposition'];
assert_that($header === 'attachment; filename="Ebook Rust ___ edisi _2_.pdf"; filename*=UTF-8\'\'Ebook%20Rust%20%E2%80%94%20edisi%20%222%22.pdf', 'filename header: ' . $header);

@unlink($dbPath);
@unlink($envPath);
fwrite(STDOUT, "DigitalDownloadTest passed\n");
