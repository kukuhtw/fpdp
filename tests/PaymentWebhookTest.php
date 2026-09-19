<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Repositories\PaymentRepository;

function pwh_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-paywebhook-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-paywebhook-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nPAYWUZ_API_KEY=test-secret-key\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE cv_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER UNIQUE, title TEXT, storage_key TEXT, content_type TEXT DEFAULT "application/pdf", price_amount TEXT DEFAULT "0.00", price_currency TEXT DEFAULT "IDR", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE cv_access_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, cv_document_id INTEGER NOT NULL, visitor_id INTEGER NOT NULL, payment_reference TEXT, granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (cv_document_id, visitor_id))',
    'CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, order_id TEXT, gateway_code TEXT, external_transaction_id TEXT, payment_method TEXT, currency TEXT DEFAULT "IDR", amount REAL DEFAULT 0, fee REAL DEFAULT 0, status TEXT DEFAULT "PENDING", payment_url TEXT, metadata TEXT, expired_at TIMESTAMP, paid_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER NOT NULL, provider TEXT, external_id TEXT, event_type TEXT, status TEXT, payload TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (provider, external_id))',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$dispatch = static function (string $method, string $path, ?string $rawBody, array $headers = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, [], $rawBody, $headers));

    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

// Seed a node, a priced CV document, and a visitor (visitor rows aren't modeled
// here since confirmPayment() only needs a numeric visitor id).
$db->exec("INSERT INTO nodes (public_id, domain, name, default_locale, timezone) VALUES ('n1', 'owner.test.local', 'Owner', 'id', 'Asia/Jakarta')");
$nodeId = (int) $db->lastInsertId();
$db->exec("INSERT INTO cv_documents (public_id, node_id, title, storage_key, price_amount, price_currency) VALUES ('cv1', {$nodeId}, 'Resume', 'resume.pdf', '50000.00', 'IDR')");
$documentId = (int) $db->lastInsertId();
$visitorId = 42;

// Seed a PENDING payment exactly as CvAccessService/PaymentService::createPayment()
// would have left it after calling PaywuzGateway::createPayment() (no network here —
// PaymentRepository is a pure DB write, so this exercises the real persistence code).
$paymentRepo = new PaymentRepository($db);
$orderId = sprintf('CV-%d-%d-%d', $documentId, $visitorId, time());
$paymentRepo->create(
    Uuid::v4(),
    $orderId,
    'PAYWUZ',
    $orderId,
    'ALL',
    'IDR',
    50000.0,
    'PENDING',
    'https://checkout.paywuz.id/abc123',
    null,
    ['purpose' => 'cv_access', 'document_id' => $documentId, 'visitor_id' => $visitorId],
);

function pwh_signed_body(array $data): array
{
    $raw = json_encode($data);
    $signature = 'sha256=' . hash_hmac('sha256', $raw, 'test-secret-key');

    return [$raw, $signature];
}

// ---- Test 1: a webhook with an invalid signature is rejected (401), no state change ----
[$rawBody, ] = pwh_signed_body(['event' => 'transaction.paid', 'data' => ['orderId' => $orderId, 'status' => 'success']]);
$badSig = $dispatch('POST', '/api/v1/payments/webhook/paywuz', $rawBody, ['x-paywuz-signature' => 'sha256=deadbeef']);
pwh_assert($badSig['status'] === 401, 'Invalid webhook signature should return 401, got ' . $badSig['status']);

$stillPending = $paymentRepo->findByOrderId($orderId);
pwh_assert($stillPending['status'] === 'PENDING', 'Payment should remain PENDING after a rejected signature');

// ---- Test 2: a correctly signed transaction.paid webhook confirms the payment and grants CV access ----
[$rawBody, $signature] = pwh_signed_body(['event' => 'transaction.paid', 'data' => ['orderId' => $orderId, 'status' => 'success']]);
$paid = $dispatch('POST', '/api/v1/payments/webhook/paywuz', $rawBody, ['x-paywuz-signature' => $signature, 'x-paywuz-delivery' => 'dlv-1']);
pwh_assert($paid['status'] === 200, 'Valid transaction.paid webhook should return 200: ' . json_encode($paid));
pwh_assert($paid['body']['data']['duplicate'] === false, 'First delivery should not be reported as duplicate');

$nowPaid = $paymentRepo->findByOrderId($orderId);
pwh_assert($nowPaid['status'] === 'PAID', 'Payment should become PAID after the webhook');
pwh_assert($nowPaid['paid_at'] !== null, 'paid_at should be set once PAID');

$grantRepo = new CvAccessGrantRepository($db);
$grant = $grantRepo->find($documentId, $visitorId);
pwh_assert($grant !== null, 'CV access should be granted once the payment is confirmed PAID');
pwh_assert($grant['payment_reference'] === $orderId, 'Grant should reference the order id');

// ---- Test 3: a retried delivery (same X-Paywuz-Delivery) is a no-op, reported as duplicate ----
$retry = $dispatch('POST', '/api/v1/payments/webhook/paywuz', $rawBody, ['x-paywuz-signature' => $signature, 'x-paywuz-delivery' => 'dlv-1']);
pwh_assert($retry['status'] === 200 && $retry['body']['data']['duplicate'] === true, 'Retried delivery should be reported as duplicate: ' . json_encode($retry));

$grantCountStmt = $db->query("SELECT COUNT(*) c FROM cv_access_grants WHERE cv_document_id={$documentId} AND visitor_id={$visitorId}");
pwh_assert((int) $grantCountStmt->fetch()['c'] === 1, 'Retried delivery must not create a second grant row');

// ---- Test 4: a webhook for an order we have no local payment for is accepted but causes no state change ----
[$unknownBody, $unknownSig] = pwh_signed_body(['event' => 'transaction.paid', 'data' => ['orderId' => 'NEVER-SEEN', 'status' => 'success']]);
$unknownOrder = $dispatch('POST', '/api/v1/payments/webhook/paywuz', $unknownBody, ['x-paywuz-signature' => $unknownSig]);
pwh_assert($unknownOrder['status'] === 200, 'A webhook for an unknown order should still be acknowledged, not error');

// Cleanup
Database::reset();
unset($paymentRepo, $grantRepo, $db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Payment webhook test passed\n");
