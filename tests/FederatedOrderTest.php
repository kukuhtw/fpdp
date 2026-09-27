<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\HttpClient;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;
use App\Repositories\FederatedPostRepository;
use App\Repositories\FederatedPurchaseRepository;
use App\Repositories\NodeKeyRepository;
use App\Repositories\ProfileRepository;
use App\Services\Federation\HttpSignature;
use App\Services\Federation\NodeKeyService;
use App\Services\Federation\SignedRequestSigner;
use App\Services\Marketplace\FederatedPurchaseService;

/**
 * Node-to-node orders (FEDERATION-CONCEPT §11b), both halves:
 * A. this node as the SELLER: signed order requests from another node's
 *    owner, through the real routes and signature checks;
 * B. this node as the BUYER: the owner orders from a followed shop; the
 *    seller's node is faked, but every request it receives is checked
 *    against this node's public key.
 */
function fo_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** A seller node's answers, checking each request's HTTP Signature. */
final class FakeSellerNode extends HttpClient
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string, verified: bool}> */
    public array $requests = [];
    /** @var array<int, array{status: int, body: mixed}|\Throwable> */
    public array $replies = [];

    public function __construct(private readonly string $publicKeyPem)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?int $timeout = null, ?int $maxSize = null): array
    {
        $lower = array_change_key_case($headers, CASE_LOWER);
        $parsed = HttpSignature::parseSignatureHeader((string) ($lower['signature'] ?? ''));
        $verified = false;
        if ($parsed !== null) {
            $signing = HttpSignature::buildSigningString($method, (string) parse_url($url, PHP_URL_PATH), $lower, $parsed['headers']);
            $digestOk = $body === null || ($lower['digest'] ?? '') === HttpSignature::digestHeader($body);
            $verified = $digestOk && openssl_verify($signing, base64_decode($parsed['signature']), $this->publicKeyPem, OPENSSL_ALGO_SHA256) === 1
                && $parsed['keyId'] === 'https://test.local/@owner#main-key'
                && (in_array('digest', $parsed['headers'], true) || $body === null);
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'verified' => $verified];

        $reply = array_shift($this->replies) ?? ['status' => 500, 'body' => ['error' => ['message' => 'no scripted reply']]];
        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return ['status' => $reply['status'], 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($reply['body']), 'url' => $url];
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-fed-order-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-fed-order-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nRATE_LIMIT_LOGIN_MAX=50\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, user_agent TEXT, ip_hint TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, last_used_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, actor_user_id INTEGER, action TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, slug TEXT, title TEXT, description TEXT, price TEXT DEFAULT "0", currency TEXT DEFAULT "IDR", product_type TEXT DEFAULT "PHYSICAL", digital_asset_url TEXT, digital_asset_metadata TEXT, status TEXT DEFAULT "ACTIVE", visibility TEXT DEFAULT "PUBLIC", is_promoted INTEGER DEFAULT 0, media TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE product_digital_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, kind TEXT, storage_key TEXT, original_filename TEXT, content_type TEXT, size_bytes INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, visitor_id INTEGER, buyer_email TEXT, buyer_name TEXT, status TEXT DEFAULT "PENDING", total_amount TEXT DEFAULT "0", currency TEXT DEFAULT "IDR", notes TEXT, shipping_address TEXT, payment_reference TEXT, remote_actor_uri TEXT, remote_client_reference TEXT UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, product_snapshot TEXT, quantity INTEGER DEFAULT 1, unit_price TEXT DEFAULT "0", subtotal TEXT DEFAULT "0", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, adapter_class TEXT, description TEXT, status TEXT DEFAULT "ACTIVE", is_plugin INTEGER DEFAULT 0, config_keys_json TEXT, supports_refund INTEGER DEFAULT 1, supports_recurring INTEGER DEFAULT 0, supports_qris INTEGER DEFAULT 0, supports_va INTEGER DEFAULT 1, supports_credit_card INTEGER DEFAULT 0, supports_ewallet INTEGER DEFAULT 0)',
    'CREATE TABLE payment_gateway_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway_id INTEGER, config_key TEXT, encrypted_value TEXT, environment TEXT DEFAULT "SANDBOX", is_active INTEGER DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (gateway_id, config_key, environment))',
    'CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, order_id TEXT, gateway_code TEXT, external_transaction_id TEXT, payment_method TEXT, currency TEXT DEFAULT "IDR", amount REAL DEFAULT 0, fee REAL DEFAULT 0, refunded_amount REAL DEFAULT 0, status TEXT DEFAULT "PENDING", payment_url TEXT, metadata TEXT, expired_at TIMESTAMP, paid_at TIMESTAMP, refunded_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER, provider TEXT, external_id TEXT, event_type TEXT, status TEXT, payload TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (provider, external_id))',
    'CREATE TABLE remote_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, status TEXT DEFAULT "ACTIVE", trust_state TEXT DEFAULT "UNKNOWN", last_seen_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT, federated_address TEXT UNIQUE, display_name TEXT, avatar_url TEXT, canonical_url TEXT, inbox_url TEXT, shared_inbox_url TEXT, public_key_id TEXT, public_key_pem TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, relationship_status TEXT DEFAULT "PENDING", show_on_profile INTEGER DEFAULT 1, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (profile_id, remote_actor_id))',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, canonical_url TEXT, title TEXT, content TEXT, attachments TEXT, product_data TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
    'CREATE TABLE node_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER, key_type TEXT DEFAULT "rsa", public_key TEXT, private_key TEXT, fingerprint TEXT UNIQUE, is_current INTEGER DEFAULT 1, rotated_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_purchases (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, seller_domain TEXT, seller_actor_uri TEXT, order_endpoint TEXT, product_object_uri TEXT, remote_order_id TEXT, items TEXT, total_amount TEXT DEFAULT "0", currency TEXT DEFAULT "IDR", status TEXT DEFAULT "SUBMITTING", payment_url TEXT, downloads TEXT, buyer_name TEXT, buyer_email TEXT, shipping_address TEXT, notes TEXT, last_error TEXT, last_synced_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}
$db->exec("INSERT INTO payment_gateways (code, name) VALUES ('DUMMY', 'Dummy')");

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$call = static function (string $method, string $path, ?string $body = null, array $headers = [], array $query = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, $query, $body, $headers, '198.51.100.7'));

    return ['status' => $response->status, 'body' => json_decode($response->body, true), 'raw' => $response->body];
};

// ---- Setup: the local owner (test.local), gateway, and products ----
$register = $call('POST', '/api/v1/auth/register', json_encode(['email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner']));
fo_assert($register['status'] === 201, 'registration failed: ' . $register['raw']);
$ownerToken = $register['body']['data']['token']['access_token'];
$auth = ['authorization' => "Bearer {$ownerToken}"];
fo_assert($call('PUT', '/api/v1/me/payment-gateways/DUMMY/activate', null, $auth)['status'] === 200, 'activating DUMMY failed');
$nodeId = (int) $db->query('SELECT id FROM nodes')->fetchColumn();

$product = static function (string $title, string $price, string $type, string $visibility = 'PUBLIC', ?string $url = null) use ($db, $nodeId): array {
    $id = Uuid::v4();
    $db->prepare('INSERT INTO products (public_id, node_id, title, price, currency, product_type, visibility, digital_asset_url) VALUES (?, ?, ?, ?, "IDR", ?, ?, ?)')
        ->execute([$id, $nodeId, $title, $price, $type, $visibility, $url]);

    return ['id' => $id, 'row' => (int) $db->lastInsertId()];
};
$shirt = $product('Kaos', '75000.00', 'PHYSICAL');
$ebook = $product('E-book', '50000.00', 'DIGITAL', 'PUBLIC', 'https://files.example/ebook-link');
$secret = $product('Rahasia', '10000.00', 'PHYSICAL', 'PRIVATE');

// A digital file for the e-book (the real storage directory; removed at the end).
$storageKey = 'fed-order-test-' . bin2hex(random_bytes(6)) . '.pdf';
$storageFile = dirname(__DIR__) . '/storage/products/' . $storageKey;
@mkdir(dirname($storageFile), 0770, true);
file_put_contents($storageFile, '%PDF-1.4 test');
register_shutdown_function(static fn () => @unlink($storageFile)); // also when an assertion exits early
$db->prepare('INSERT INTO product_digital_assets (product_id, kind, storage_key, original_filename, content_type, size_bytes) VALUES (?, "PDF", ?, "ebook.pdf", "application/pdf", 13)')->execute([$ebook['row'], $storageKey]);

// Remote actors with keys this test holds (so no network is needed).
$seedActor = static function (string $actorUri) use ($db): string {
    ['private_key' => $private, 'public_key' => $public] = NodeKeyService::createRsaKeyPair(2048);
    $domain = (string) parse_url($actorUri, PHP_URL_HOST);
    $db->prepare('INSERT OR IGNORE INTO remote_nodes (public_id, domain) VALUES (?, ?)')->execute([Uuid::v4(), $domain]);
    $nodeRow = (int) $db->query("SELECT id FROM remote_nodes WHERE domain = '{$domain}'")->fetchColumn();
    $db->prepare('INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, public_key_id, public_key_pem) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([Uuid::v4(), $nodeRow, $actorUri, '@' . basename($actorUri) . '@' . $domain, 'Remote', $actorUri . '#main-key', $public]);

    return $private;
};
$buyerActor = 'https://buyer.example/@ari';
$buyerKey = $seedActor($buyerActor);
$evilKey = $seedActor('https://evil.example/@mallory');

$signed = static function (string $method, string $path, ?array $payload, string $privateKey, string $keyId, ?int $date = null, ?string $bodyOverride = null): array {
    $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null;
    $headers = ['host' => 'test.local', 'date' => HttpSignature::httpDate($date)];
    $names = ['(request-target)', 'host', 'date'];
    if ($body !== null) {
        $headers['digest'] = HttpSignature::digestHeader($body);
        $names[] = 'digest';
    }
    openssl_sign(HttpSignature::buildSigningString($method, $path, $headers, $names), $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $headers['signature'] = HttpSignature::buildSignatureHeader($keyId, $names, base64_encode($signature));
    $headers['content-type'] = 'application/json';

    return [$bodyOverride ?? $body, $headers];
};
$orderRequest = static fn (array $overrides = []): array => $overrides + [
    'type' => 'fpdp:OrderRequest',
    'actor' => $buyerActor,
    'clientReference' => Uuid::v4(),
    'items' => [['productId' => $shirt['id'], 'quantity' => 2, 'price' => '1.00']],
    'buyer' => ['name' => 'Ari', 'email' => 'Ari@Buyer.Example'],
    'shippingAddress' => "Jl. Mawar 1, Bandung\nTelepon: 0812",
    'notes' => 'Ukuran L',
    'returnUrl' => 'https://buyer.example/dashboard/purchases?ref=abc',
];
$post = static function (array $payload, ?string $key = null, ?string $keyId = null, ?int $date = null, ?string $bodyOverride = null) use ($call, $signed, $buyerKey, $buyerActor): array {
    [$body, $headers] = $signed('POST', '/api/v1/federation/orders', $payload, $key ?? $buyerKey, $keyId ?? "{$buyerActor}#main-key", $date, $bodyOverride);

    return $call('POST', '/api/v1/federation/orders', $body, $headers);
};

// ======================= A. This node as the SELLER =======================

// ---- A1. A signed order: priced here, charged on this node's gateway ----
$request = $orderRequest();
$placed = $post($request);
fo_assert($placed['status'] === 201, 'a signed order should be accepted: ' . $placed['raw']);
$order = $placed['body']['data']['order'];
fo_assert($order['total_amount'] === '150000.00' && $order['currency'] === 'IDR', 'the seller prices the order (client price ignored): ' . json_encode($order));
fo_assert($order['status'] === 'COMPLETED' && $order['items'][0]['quantity'] === 2 && $order['items'][0]['title'] === 'Kaos', 'DUMMY completes at once: ' . json_encode($order));
fo_assert($order['status_url'] === 'https://test.local/api/v1/federation/orders/' . $order['id'], 'status URL on this node');
fo_assert(!str_contains($placed['raw'], 'payment_reference') && !str_contains($placed['raw'], 'Mawar'), 'the response carries no payment reference or buyer address');
$row = $db->query("SELECT * FROM orders WHERE public_id = '{$order['id']}'")->fetch();
fo_assert($row['remote_actor_uri'] === $buyerActor && $row['remote_client_reference'] === $request['clientReference'] && $row['visitor_id'] === null, 'the order is tied to the buyer actor');
fo_assert($row['buyer_email'] === 'ari@buyer.example' && $row['buyer_name'] === 'Ari' && str_contains((string) $row['shipping_address'], 'Mawar') && $row['notes'] === 'Ukuran L', 'buyer details stored for fulfilment');

// ---- A2. A retry with the same client reference is the same order ----
$retry = $post($request);
fo_assert($retry['status'] === 200 && $retry['body']['data']['order']['id'] === $order['id'], 'a retry returns the first order: ' . $retry['raw']);
fo_assert((int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1, 'no double order');
// Still unpaid (an asynchronous gateway): the retry hands out the same payment page again.
$db->exec("UPDATE orders SET status = 'PENDING' WHERE public_id = '{$order['id']}'");
$db->prepare("INSERT INTO payments (uuid, order_id, gateway_code, status, payment_url) VALUES (?, ?, 'DUMMY', 'PENDING', 'https://pay.example/abc')")->execute([Uuid::v4(), 'MKT-' . $row['id'] . '-Rx-1']);
$retry = $post($request);
fo_assert($retry['status'] === 200 && ($retry['body']['data']['order']['payment']['payment_url'] ?? null) === 'https://pay.example/abc', 'a retry of an unpaid order returns its payment page: ' . $retry['raw']);
fo_assert((int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn() === 2, 'and does not charge again');
$db->exec("UPDATE orders SET status = 'COMPLETED' WHERE public_id = '{$order['id']}'");

// ---- A3. Only the actor itself can place it ----
fo_assert($call('POST', '/api/v1/federation/orders', json_encode($orderRequest()), ['host' => 'test.local', 'content-type' => 'application/json'])['status'] === 401, 'an unsigned order is refused');
fo_assert($post($orderRequest(), $evilKey, 'https://evil.example/@mallory#main-key')['status'] === 401, 'another actor cannot order as the buyer');
fo_assert($post($orderRequest(), null, null, null, json_encode($orderRequest(['notes' => 'swapped'])))['status'] === 403, 'a body swapped after signing is refused');
fo_assert($post($orderRequest(), null, null, time() - 3600)['status'] === 401, 'an old signed request is refused');
$stolen = $post(['clientReference' => $request['clientReference']] + $orderRequest(['actor' => 'https://evil.example/@mallory', 'returnUrl' => null]), $evilKey, 'https://evil.example/@mallory#main-key');
fo_assert($stolen['status'] === 409, 'another actor cannot claim a used client reference: ' . $stolen['raw']);

// ---- A4. Same rules as the shop ----
$private = $post($orderRequest(['items' => [['productId' => $secret['id'], 'quantity' => 1]]]));
fo_assert($private['status'] === 422, 'a private product cannot be ordered: ' . $private['raw']);
$noAddress = $post($orderRequest(['shippingAddress' => '']));
fo_assert($noAddress['status'] === 422, 'a physical item needs an address');
fo_assert($post($orderRequest(['returnUrl' => 'https://phish.example/x']))['status'] === 422, 'the return URL must be on the buyer node');
fo_assert($post($orderRequest(['buyer' => ['name' => 'Ari', 'email' => 'not-an-email']]))['status'] === 422, 'the buyer email is validated');
fo_assert($post($orderRequest(['actor' => 'https://test.local/@owner', 'returnUrl' => null]), null, 'https://test.local/@owner#main-key')['status'] === 403, 'a node cannot order from itself');
$db->exec("UPDATE remote_nodes SET trust_state = 'BLOCKED' WHERE domain = 'evil.example'");
fo_assert($post($orderRequest(['actor' => 'https://evil.example/@mallory', 'returnUrl' => null]), $evilKey, 'https://evil.example/@mallory#main-key')['status'] === 403, 'a blocked domain cannot order');

// ---- A5. Status: signed GET by the buyer only ----
$ebookOrder = $post($orderRequest(['items' => [['productId' => $ebook['id'], 'quantity' => 1]], 'shippingAddress' => '']))['body']['data']['order'];
$statusPath = "/api/v1/federation/orders/{$ebookOrder['id']}";
[, $getHeaders] = $signed('GET', $statusPath, null, $buyerKey, "{$buyerActor}#main-key");
$status = $call('GET', $statusPath, null, $getHeaders);
fo_assert($status['status'] === 200 && $status['body']['data']['status'] === 'COMPLETED', 'the buyer reads the status: ' . $status['raw']);
$downloads = $status['body']['data']['downloads'];
fo_assert(count($downloads) === 2 && $downloads[0]['url'] === 'https://files.example/ebook-link', 'a paid digital order lists the link and the file: ' . json_encode($downloads));
fo_assert(str_starts_with($downloads[1]['url'], "https://test.local/api/v1/federation/orders/{$ebookOrder['id']}/downloads/{$ebook['id']}/pdf?expires="), 'file download URL: ' . $downloads[1]['url']);
[, $evilGet] = $signed('GET', $statusPath, null, $evilKey, 'https://evil.example/@mallory#main-key');
fo_assert($call('GET', $statusPath, null, $evilGet)['status'] === 401, 'another actor cannot read the order');
fo_assert($call('GET', $statusPath, null, ['host' => 'test.local'])['status'] === 401, 'an unsigned status read is refused');
[, $unknownGet] = $signed('GET', '/api/v1/federation/orders/' . Uuid::v4(), null, $buyerKey, "{$buyerActor}#main-key");
fo_assert($call('GET', '/api/v1/federation/orders/' . Uuid::v4(), null, $unknownGet)['status'] === 404, 'unknown order is a 404');

// ---- A6. Download links: valid for their order only, and expire ----
parse_str((string) parse_url($downloads[1]['url'], PHP_URL_QUERY), $q);
$downloadPath = (string) parse_url($downloads[1]['url'], PHP_URL_PATH);
$file = $router->dispatch(new Request('GET', $downloadPath, $q, null, [], '198.51.100.7'));
fo_assert($file->status === 200 && $file->body === '%PDF-1.4 test', 'the signed link downloads the file: ' . $file->status);
fo_assert($call('GET', $downloadPath, null, [], ['expires' => $q['expires'], 'token' => str_repeat('0', 64)])['status'] === 403, 'a forged token is refused');
fo_assert($call('GET', $downloadPath, null, [], ['expires' => (string) ((int) $q['expires'] + 60), 'token' => $q['token']])['status'] === 403, 'a token cannot be stretched');
fo_assert($call('GET', "/api/v1/federation/orders/{$order['id']}/downloads/{$ebook['id']}/pdf", null, [], $q)['status'] === 403, 'a token for one order does not open another');

// ---- A7. The owner sees where the order came from; audit has no address ----
$list = $call('GET', '/api/v1/orders', null, $auth);
fo_assert(in_array($buyerActor, array_column($list['body']['data'], 'remote_actor_uri'), true), 'the owner order list shows the buyer actor');
$auditRows = $db->query("SELECT COALESCE(metadata, '') FROM audit_events WHERE action = 'order.federated_received'")->fetchAll(PDO::FETCH_COLUMN);
$audit = implode("\n", $auditRows);
fo_assert(count($auditRows) === 2 && json_decode($auditRows[0], true)['buyer_actor'] === $buyerActor, 'each new order audited once (not the retry): ' . $audit);
fo_assert(!str_contains($audit, 'Mawar') && !str_contains($audit, 'ari@buyer.example'), 'the audit holds no address or email');

// ======================= B. This node as the BUYER =======================
$profileId = (int) $db->query('SELECT id FROM profiles')->fetchColumn();
$db->prepare('INSERT INTO remote_nodes (public_id, domain) VALUES (?, ?)')->execute([Uuid::v4(), 'shop.example']);
$db->prepare('INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name) VALUES (?, ?, ?, ?, ?)')
    ->execute([Uuid::v4(), (int) $db->lastInsertId(), 'https://shop.example/@toko', '@toko@shop.example', 'Toko Sebelah']);
$sellerActorRow = (int) $db->lastInsertId();
$db->prepare('INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status) VALUES (?, ?, ?, "FOLLOWING")')->execute([Uuid::v4(), $profileId, $sellerActorRow]);
$remoteProductId = Uuid::v4();
$feedPost = static function (string $title, array $productData) use ($db, $sellerActorRow): string {
    $id = Uuid::v4();
    $db->prepare('INSERT INTO federated_posts (public_id, remote_actor_id, object_uri, title, content, attachments, product_data, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')
        ->execute([$id, $sellerActorRow, 'https://shop.example/shop/' . Uuid::v4(), $title, '<p>Kopi <b>enak</b></p>', json_encode([['media_type' => 'IMAGE', 'url' => 'https://shop.example/media/kopi.jpg']]), json_encode($productData)]);

    return $id;
};
$orderablePost = $feedPost('Kopi Arabika', ['price' => '60000.00', 'currency' => 'IDR', 'product_type' => 'PHYSICAL', 'checkout_url' => 'https://shop.example/shop/x', 'product_id' => $remoteProductId, 'order_endpoint' => 'https://shop.example/api/v1/federation/orders']);
$linkOnlyPost = $feedPost('Teh', ['price' => '20000.00', 'currency' => 'IDR', 'product_type' => 'PHYSICAL', 'checkout_url' => 'https://shop.example/shop/y']);

$keys = new NodeKeyService(new NodeKeyRepository($db));
$keys->generateKeypair($nodeId);
$seller = new FakeSellerNode($keys->getPublicKeyPem($nodeId));
$purchases = new FederatedPurchaseService(
    new FederatedPostRepository($db),
    new FederatedPurchaseRepository($db),
    new SignedRequestSigner($keys, new ProfileRepository($db)),
    $seller,
);
$context = ['user' => ['id' => 1, 'email' => 'owner@test.local'], 'node' => ['id' => $nodeId, 'domain' => 'test.local'], 'profile' => ['id' => $profileId]];

// ---- B1. The shop list: products from followed accounts ----
$shop = $purchases->shop($context);
$byTitle = array_column($shop, null, 'title');
fo_assert(count($shop) === 2 && $byTitle['Kopi Arabika']['orderable'] === true && $byTitle['Teh']['orderable'] === false, 'orderable only with an order endpoint: ' . json_encode($shop));
fo_assert($byTitle['Kopi Arabika']['excerpt'] === 'Kopi enak' && $byTitle['Kopi Arabika']['image_url'] === 'https://shop.example/media/kopi.jpg' && $byTitle['Kopi Arabika']['seller']['domain'] === 'shop.example', 'product card data');

// ---- B2. Placing an order: signed request, seller's figures, payment page ----
$remoteOrderId = Uuid::v4();
$seller->replies[] = ['status' => 201, 'body' => ['data' => ['order' => [
    'id' => $remoteOrderId, 'status' => 'PENDING', 'total_amount' => '125000.00', 'currency' => 'IDR',
    'items' => [['product_id' => $remoteProductId, 'title' => 'Kopi Arabika', 'product_type' => 'PHYSICAL', 'quantity' => 2, 'unit_price' => '62500.00', 'subtotal' => '125000.00']],
    'downloads' => [], 'payment' => ['status' => 'PENDING', 'payment_url' => 'https://pay.gateway.example/checkout/123'],
]]]];
$placed = $purchases->place($context, ['post_id' => $orderablePost, 'quantity' => 2, 'buyer_name' => 'Owner', 'shipping_address' => 'Jl. Kenanga 2', 'notes' => 'Giling halus']);
$sent = $seller->requests[0];
fo_assert($sent['verified'] === true && $sent['method'] === 'POST' && $sent['url'] === 'https://shop.example/api/v1/federation/orders', 'the order is POSTed, signed with this node key: ' . json_encode($sent['headers']));
$sentBody = json_decode((string) $sent['body'], true);
fo_assert($sentBody['type'] === 'fpdp:OrderRequest' && $sentBody['actor'] === 'https://test.local/@owner' && $sentBody['clientReference'] === $placed['id'], 'request identifies the owner and the purchase: ' . $sent['body']);
fo_assert($sentBody['items'] === [['productId' => $remoteProductId, 'quantity' => 2]] && $sentBody['buyer'] === ['name' => 'Owner', 'email' => 'owner@test.local'], 'items and buyer (email defaults to the owner): ' . $sent['body']);
fo_assert($sentBody['returnUrl'] === 'https://test.local/dashboard/purchases?ref=' . $placed['id'], 'the seller sends the owner back here');
fo_assert($placed['status'] === 'PENDING' && $placed['total_amount'] === '125000.00' && $placed['items'][0]['unit_price'] === '62500.00' && $placed['payment_url'] === 'https://pay.gateway.example/checkout/123', "the seller's figures are kept: " . json_encode($placed));

// ---- B3. Refresh: signed GET; paid digital downloads appear; payment link gone ----
$seller->replies[] = ['status' => 200, 'body' => ['data' => [
    'id' => $remoteOrderId, 'status' => 'COMPLETED', 'total_amount' => '125000.00', 'currency' => 'IDR', 'items' => [],
    'downloads' => [['title' => 'Kopi', 'label' => 'resep.pdf', 'url' => 'https://shop.example/dl?t=1', 'expires_at' => '2026-09-27T10:00:00+00:00'], ['title' => 'x', 'url' => 'javascript:alert(1)']],
]]];
$refreshed = $purchases->refresh($context, $placed['id']);
$get = $seller->requests[1];
fo_assert($get['verified'] === true && $get['method'] === 'GET' && $get['url'] === "https://shop.example/api/v1/federation/orders/{$remoteOrderId}", 'status is a signed GET: ' . json_encode($get));
fo_assert($refreshed['status'] === 'COMPLETED' && $refreshed['payment_url'] === null && count($refreshed['downloads']) === 1 && $refreshed['items'][0]['title'] === 'Kopi Arabika', 'status updated, only https downloads kept, items kept: ' . json_encode($refreshed));

// ---- B4. Seller unreachable: kept, and re-sent with the same reference ----
$seller->replies[] = new RuntimeException('connection timed out');
$pending = $purchases->place($context, ['post_id' => $orderablePost, 'quantity' => 1, 'buyer_name' => 'Owner', 'shipping_address' => 'Jl. Kenanga 2']);
fo_assert($pending['status'] === 'SUBMITTING' && str_contains((string) $pending['last_error'], 'timed out'), 'an unreachable seller leaves it SUBMITTING: ' . json_encode($pending));
$secondId = Uuid::v4();
$seller->replies[] = ['status' => 200, 'body' => ['data' => ['order' => ['id' => $secondId, 'status' => 'PENDING', 'total_amount' => '60000.00', 'currency' => 'IDR', 'items' => [], 'downloads' => []]]]];
$db->exec("UPDATE federated_purchases SET last_synced_at = '2000-01-01 00:00:00' WHERE public_id = '{$pending['id']}'");
$sync = $purchases->syncOpen('test.local');
$resent = json_decode((string) end($seller->requests)['body'], true);
fo_assert($resent['clientReference'] === $pending['id'] && end($seller->requests)['verified'], 'the retry re-sends the same reference, signed');
fo_assert($sync['synced'] >= 1 && (new FederatedPurchaseRepository($db))->findByPublicId($pending['id'])['remote_order_id'] === $secondId, 'the cron picks it up: ' . json_encode($sync));

// ---- B5. Refused by the seller: nothing kept, the reason shown ----
$seller->replies[] = ['status' => 422, 'body' => ['error' => ['message' => 'The request is invalid.', 'details' => [['field' => 'items.0.product_id', 'reason' => 'not_available']]]]];
$before = (int) $db->query('SELECT COUNT(*) FROM federated_purchases')->fetchColumn();
try {
    $purchases->place($context, ['post_id' => $orderablePost, 'quantity' => 1, 'buyer_name' => 'Owner', 'shipping_address' => 'x']);
    fo_assert(false, 'a refused order should throw');
} catch (\App\Core\Exceptions\ConflictException $e) {
    fo_assert(str_contains($e->getMessage(), 'not_available'), 'the seller reason is shown: ' . $e->getMessage());
}
fo_assert((int) $db->query('SELECT COUNT(*) FROM federated_purchases')->fetchColumn() === $before, 'a refused order leaves no purchase behind');

// ---- B6. Local checks before anything is sent ----
$sentCount = count($seller->requests);
foreach ([
    [['post_id' => $linkOnlyPost, 'buyer_name' => 'Owner', 'shipping_address' => 'x'], 'link-only product'],
    [['post_id' => Uuid::v4(), 'buyer_name' => 'Owner', 'shipping_address' => 'x'], 'unknown post'],
    [['post_id' => $orderablePost, 'buyer_name' => 'Owner'], 'missing address'],
    [['post_id' => $orderablePost, 'buyer_name' => 'Owner', 'shipping_address' => 'x', 'quantity' => 0], 'bad quantity'],
] as [$input, $label]) {
    try {
        $purchases->place($context, $input);
        fo_assert(false, "{$label} should be refused");
    } catch (\App\Core\Exceptions\HttpException $e) {
        // expected
    }
}
fo_assert(count($seller->requests) === $sentCount, 'nothing is sent for a locally invalid order');

// ---- B7. A malformed seller answer is not taken ----
$seller->replies[] = ['status' => 201, 'body' => ['data' => ['order' => ['id' => 'not-a-uuid', 'status' => 'PAID!', 'total_amount' => 'x', 'currency' => 'idr']]]];
$odd = $purchases->place($context, ['post_id' => $orderablePost, 'quantity' => 1, 'buyer_name' => 'Owner', 'shipping_address' => 'x']);
fo_assert($odd['status'] === 'SUBMITTING' && $odd['remote_order_id'] === null && $odd['last_error'] !== null, 'a malformed answer is not applied: ' . json_encode($odd));

// ---- B8. Owner API wiring ----
fo_assert($call('GET', '/api/v1/me/purchases')['status'] === 401, 'purchases need the owner login');
$apiList = $call('GET', '/api/v1/me/purchases', null, $auth);
fo_assert($apiList['status'] === 200 && count($apiList['body']['data']['items']) === 3, 'the owner lists purchases: ' . $apiList['raw']);
$apiShop = $call('GET', '/api/v1/me/fediverse-shop', null, $auth);
fo_assert($apiShop['status'] === 200 && count($apiShop['body']['data']['items']) === 2, 'the owner lists the fediverse shop');
fo_assert($call('POST', '/api/v1/me/purchases/' . Uuid::v4() . '/refresh', null, $auth)['status'] === 404, 'unknown purchase is a 404');

@unlink($storageFile);
@unlink($dbPath);
@unlink($envPath);
fwrite(STDOUT, "FederatedOrderTest passed\n");
