<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
use App\Repositories\VisitorWalletRepository;
use App\Services\Auth\AuthService;
use App\Services\Chatbot\VisitorWalletService;
use App\Services\Cv\CvAccessService;
use App\Services\Payment\PaymentService;

function prc_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-refund-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-refund-test-' . uniqid() . '.env';
$midtransKey = 'midtrans-test-server-key';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nAPP_KEY=" . bin2hex(random_bytes(32))
    . "\nPAYWUZ_API_KEY=pk_sand_test\nMIDTRANS_SERVER_KEY={$midtransKey}\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, domain TEXT NOT NULL UNIQUE, name TEXT NOT NULL, default_locale TEXT NOT NULL DEFAULT "id", timezone TEXT NOT NULL DEFAULT "Asia/Jakarta", status TEXT NOT NULL DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, node_id INTEGER NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "OWNER", status TEXT NOT NULL DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL UNIQUE, handle TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL, bio TEXT, avatar_url TEXT, visibility TEXT NOT NULL DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, token_type TEXT NOT NULL DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, adapter_class TEXT, description TEXT, status TEXT DEFAULT "ACTIVE", is_plugin INTEGER DEFAULT 0, config_keys_json TEXT, supports_refund INTEGER DEFAULT 1, supports_recurring INTEGER DEFAULT 0, supports_qris INTEGER DEFAULT 0, supports_va INTEGER DEFAULT 1, supports_credit_card INTEGER DEFAULT 0, supports_ewallet INTEGER DEFAULT 0)',
    'CREATE TABLE payment_gateway_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway_id INTEGER NOT NULL, config_key TEXT NOT NULL, encrypted_value TEXT NOT NULL, environment TEXT DEFAULT "SANDBOX", is_active INTEGER DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (gateway_id, config_key, environment))',
    'CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE, order_id TEXT NOT NULL, gateway_code TEXT NOT NULL, external_transaction_id TEXT, payment_method TEXT, currency TEXT NOT NULL DEFAULT "IDR", amount REAL NOT NULL DEFAULT 0, fee REAL NOT NULL DEFAULT 0, refunded_amount REAL NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT "PENDING", payment_url TEXT, metadata TEXT, expired_at TIMESTAMP, paid_at TIMESTAMP, refunded_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER NOT NULL, provider TEXT NOT NULL, external_id TEXT, event_type TEXT NOT NULL, status TEXT NOT NULL, payload TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (provider, external_id))',
    'CREATE TABLE cv_access_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, cv_document_id INTEGER NOT NULL, visitor_id INTEGER NOT NULL, payment_reference TEXT, granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (cv_document_id, visitor_id))',
    'CREATE TABLE visitor_wallets (id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id INTEGER UNIQUE, balance_amount NUMERIC DEFAULT 0, currency TEXT DEFAULT "IDR", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE visitor_wallet_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, wallet_id INTEGER, type TEXT, amount NUMERIC, payment_id INTEGER, note TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}
$db->exec("INSERT INTO payment_gateways (code, name) VALUES ('DUMMY', 'Dummy'), ('PAYWUZ', 'Paywuz'), ('MIDTRANS', 'Midtrans'), ('MANUAL_TRANSFER', 'Manual Transfer')");

// Fake Paywuz: per-orderId provider status, a cancel that fails for one
// order, a status call that errors for another. Every request is logged.
$providerStatus = [];
$providerRequests = [];
$fakeHttp = function (string $method, string $url, array $headers, ?string $body) use (&$providerStatus, &$providerRequests): array {
    $providerRequests[] = "{$method} {$url}";
    if (!preg_match('#/transactions/([^/]+)(/cancel)?$#', $url, $m)) {
        return ['status' => 404, 'body' => json_encode(['error' => 'not_found', 'message' => 'Unknown endpoint'])];
    }
    $orderId = rawurldecode($m[1]);
    if (($m[2] ?? '') === '/cancel') {
        if ($orderId === 'ORD-CANCEL-FAILS') {
            return ['status' => 400, 'body' => json_encode(['error' => 'invalid_request', 'message' => 'Transaction is not pending'])];
        }

        return ['status' => 200, 'body' => json_encode(['data' => ['orderId' => $orderId, 'status' => 'cancelled']])];
    }
    if ($orderId === 'ORD-STATUS-ERRORS') {
        return ['status' => 502, 'body' => json_encode(['error' => 'gateway_error', 'message' => 'Upstream error'])];
    }

    return ['status' => 200, 'body' => json_encode(['data' => ['orderId' => $orderId, 'status' => $providerStatus[$orderId] ?? 'pending', 'amount' => 50000]])];
};

$nodes = new NodeRepository($db);
$auth = new AuthService($nodes, new UserRepository($db), new ProfileRepository($db), new AuthTokenRepository($db));
$ownerToken = $auth->register(['email' => 'owner@example.com', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner'])['token']['access_token'];

$payments = new PaymentService(
    payments: new PaymentRepository($db),
    gatewayConfigs: new PaymentGatewayConfigRepository($db),
    nodes: $nodes,
    httpRequester: $fakeHttp,
);
$cvAccess = new CvAccessService(new CvDocumentRepository($db), new CvAccessGrantRepository($db), $payments, $nodes, sys_get_temp_dir());
$wallets = new VisitorWalletRepository($db);
$walletService = new VisitorWalletService($wallets, new VisitorRepository($db), $payments, $nodes);
$controller = new PaymentController($auth, $payments, $cvAccess, null, $walletService);

$router = new Router();
$router->post('/api/v1/me/payments/{uuid}/cancel', fn (Request $r, array $p) => $controller->cancelPayment($r, $p));
$router->post('/api/v1/me/payments/{uuid}/refund', fn (Request $r, array $p) => $controller->refundPayment($r, $p));
$router->post('/api/v1/me/payments/reconcile', fn (Request $r, array $p) => $controller->reconcile($r));
$router->post('/api/v1/payments/webhook/{gateway}', fn (Request $r, array $p) => $controller->webhook($r, $p));
$router->get('/api/v1/me/dashboard/payments', fn (Request $r, array $p) => $controller->summary($r));

$call = static function (string $method, string $path, ?array $body = null, bool $asOwner = true, array $headers = []) use ($router, $ownerToken): array {
    if ($asOwner) {
        $headers['authorization'] = 'Bearer ' . $ownerToken;
    }
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $headers));

    return ['status' => $response->status, 'body' => json_decode($response->body, true)];
};

$seq = 0;
$insertPayment = static function (string $orderId, string $gateway, string $status, float $amount, array $metadata, string $createdAt = '2000-01-01 00:00:00') use ($db, &$seq): string {
    $uuid = sprintf('00000000-0000-4000-8000-%012d', ++$seq);
    $statement = $db->prepare('INSERT INTO payments (uuid, order_id, gateway_code, external_transaction_id, amount, status, metadata, created_at, paid_at)
                               VALUES (:uuid, :order_id, :gateway, :external, :amount, :status, :metadata, :created_at, :paid_at)');
    $statement->execute([
        'uuid' => $uuid, 'order_id' => $orderId, 'gateway' => $gateway, 'external' => $orderId, 'amount' => $amount,
        'status' => $status, 'metadata' => json_encode($metadata), 'created_at' => $createdAt,
        'paid_at' => $status === 'PAID' ? $createdAt : null,
    ]);

    return $uuid;
};
$paymentRow = static fn (string $uuid): array => $db->query("SELECT * FROM payments WHERE uuid = '{$uuid}'")->fetch();
$hasGrant = static fn (int $documentId, int $visitorId): bool => (int) $db->query("SELECT COUNT(*) FROM cv_access_grants WHERE cv_document_id = {$documentId} AND visitor_id = {$visitorId}")->fetchColumn() === 1;
$cvMeta = static fn (int $visitorId): array => ['purpose' => 'cv_access', 'document_id' => 1, 'visitor_id' => $visitorId];

// ---- 1. Owner-only endpoints ----
prc_assert($call('POST', '/api/v1/me/payments/x/cancel', null, false)['status'] === 401, 'cancel should require owner auth');
prc_assert($call('POST', '/api/v1/me/payments/x/refund', null, false)['status'] === 401, 'refund should require owner auth');
prc_assert($call('POST', '/api/v1/me/payments/reconcile', null, false)['status'] === 401, 'reconcile should require owner auth');
prc_assert($call('POST', '/api/v1/me/payments/does-not-exist/cancel')['status'] === 404, 'cancelling an unknown payment should 404');

// ---- 2. Cancel: provider cancel succeeds, is idempotent, and a cancelled payment cannot be refunded ----
$pendingUuid = $insertPayment('ORD-CANCEL-OK', 'PAYWUZ', 'PENDING', 50000, $cvMeta(10));
$cancel = $call('POST', "/api/v1/me/payments/{$pendingUuid}/cancel");
prc_assert($cancel['status'] === 200, 'cancelling a pending payment should succeed: ' . json_encode($cancel));
prc_assert($cancel['body']['data']['provider_cancelled'] === true, 'Paywuz has a cancel API, so the provider cancel should be reported');
prc_assert($cancel['body']['data']['payment']['status'] === 'CANCELLED', 'the payment should become CANCELLED');
prc_assert(in_array('POST https://api.paywuz.id/v1/transactions/ORD-CANCEL-OK/cancel', $providerRequests, true), 'cancel should call the Paywuz cancel endpoint');
prc_assert($call('POST', "/api/v1/me/payments/{$pendingUuid}/cancel")['status'] === 200, 'cancelling twice should be a harmless no-op');
prc_assert($call('POST', "/api/v1/me/payments/{$pendingUuid}/refund", ['manual' => true])['status'] === 409, 'a cancelled payment cannot be refunded');

// ---- 3. Cancel when the provider refuses: still cancelled locally, with the provider message ----
$failingCancelUuid = $insertPayment('ORD-CANCEL-FAILS', 'PAYWUZ', 'PENDING', 50000, $cvMeta(11));
$localCancel = $call('POST', "/api/v1/me/payments/{$failingCancelUuid}/cancel");
prc_assert($localCancel['status'] === 200 && $localCancel['body']['data']['provider_cancelled'] === false, 'a failed provider cancel should still cancel locally');
prc_assert(str_contains((string) $localCancel['body']['data']['provider_message'], 'not pending'), 'the provider error should be surfaced to the owner');

// ---- 4. Refund a paid CV purchase: gateway without refund API, partial, over-refund, then full ----
$paidCvUuid = $insertPayment('ORD-CV-PAID', 'PAYWUZ', 'PAID', 50000, $cvMeta(20));
$db->exec("INSERT INTO cv_access_grants (cv_document_id, visitor_id, payment_reference) VALUES (1, 20, 'ORD-CV-PAID')");

$noApi = $call('POST', "/api/v1/me/payments/{$paidCvUuid}/refund", ['amount' => 10000]);
prc_assert($noApi['status'] === 422, 'a refund through a gateway with no refund API should be a validation error pointing at manual: ' . json_encode($noApi));
prc_assert($paymentRow($paidCvUuid)['status'] === 'PAID', 'a rejected refund must not change the payment');

$partial = $call('POST', "/api/v1/me/payments/{$paidCvUuid}/refund", ['amount' => 10000, 'manual' => true]);
prc_assert($partial['status'] === 200 && $partial['body']['data']['fully_refunded'] === false, 'a partial manual refund should succeed');
prc_assert($partial['body']['data']['payment']['status'] === 'PARTIALLY_REFUNDED', 'a partial refund should mark PARTIALLY_REFUNDED');
prc_assert($hasGrant(1, 20), 'a partial refund keeps the CV access');

prc_assert($call('POST', "/api/v1/me/payments/{$paidCvUuid}/refund", ['amount' => 40001, 'manual' => true])['status'] === 422, 'refunding more than what is left should be rejected');
prc_assert($call('POST', "/api/v1/me/payments/{$paidCvUuid}/refund", ['amount' => 'lots', 'manual' => true])['status'] === 422, 'a non-numeric amount should be rejected');

$full = $call('POST', "/api/v1/me/payments/{$paidCvUuid}/refund", ['manual' => true]);
prc_assert($full['status'] === 200 && $full['body']['data']['fully_refunded'] === true, 'refunding the rest should complete the refund');
prc_assert((float) $full['body']['data']['refunded_now'] === 40000.0, 'the remaining 40000 should be refunded');
$refundedRow = $paymentRow($paidCvUuid);
prc_assert($refundedRow['status'] === 'REFUNDED' && (float) $refundedRow['refunded_amount'] === 50000.0, 'the payment should be REFUNDED for the full amount');
prc_assert(!$hasGrant(1, 20), 'a full refund should revoke CV access');
prc_assert($call('POST', "/api/v1/me/payments/{$paidCvUuid}/refund", ['manual' => true])['status'] === 409, 'a fully refunded payment cannot be refunded again');

// ---- 5. Wallet top-up: refused while the visitor has spent part of it, allowed once the balance covers it ----
$wallet = $wallets->getOrCreate(30);
$walletId = (int) $wallet['id'];
$db->exec("UPDATE visitor_wallets SET balance_amount = 5000 WHERE id = {$walletId}");
$topupUuid = $insertPayment('ORD-TOPUP', 'DUMMY', 'PAID', 20000, ['purpose' => 'wallet_topup', 'wallet_id' => $walletId]);

$spent = $call('POST', "/api/v1/me/payments/{$topupUuid}/refund", ['manual' => true]);
prc_assert($spent['status'] === 409, 'a full refund of a partly spent top-up should be refused: ' . json_encode($spent));
prc_assert($paymentRow($topupUuid)['status'] === 'PAID', 'the refused refund must leave the payment PAID');

$db->exec("UPDATE visitor_wallets SET balance_amount = 20000 WHERE id = {$walletId}");
$topupRefund = $call('POST', "/api/v1/me/payments/{$topupUuid}/refund", []);
prc_assert($topupRefund['status'] === 200 && $topupRefund['body']['data']['payment']['status'] === 'REFUNDED', 'DUMMY refunds through its API: ' . json_encode($topupRefund));
prc_assert((float) $wallets->findById($walletId)['balance_amount'] === 0.0, 'the refunded top-up should be taken back out of the wallet');
prc_assert((int) $db->query("SELECT COUNT(*) FROM visitor_wallet_transactions WHERE wallet_id = {$walletId} AND type = 'TOPUP_REFUND'")->fetchColumn() === 1, 'the wallet should record a TOPUP_REFUND transaction');

// ---- 6. Midtrans refund webhook after settlement is applied, not deduplicated as a repeat ----
$midtransUuid = $insertPayment('ORD-MIDTRANS', 'MIDTRANS', 'PENDING', 75000, $cvMeta(40));
$midtransWebhook = static function (string $transactionStatus) use ($call, $midtransKey): array {
    $body = ['order_id' => 'ORD-MIDTRANS', 'status_code' => '200', 'gross_amount' => '75000.00', 'transaction_id' => 'mt-trx-1', 'transaction_status' => $transactionStatus];
    $body['signature_key'] = hash('sha512', $body['order_id'] . $body['status_code'] . $body['gross_amount'] . $midtransKey);

    return $call('POST', '/api/v1/payments/webhook/midtrans', $body, false);
};
prc_assert($midtransWebhook('settlement')['status'] === 200, 'the settlement webhook should be accepted');
prc_assert($paymentRow($midtransUuid)['status'] === 'PAID' && $hasGrant(1, 40), 'settlement should mark PAID and grant access');
$refundHook = $midtransWebhook('refund');
prc_assert($refundHook['status'] === 200 && $refundHook['body']['data']['duplicate'] === false, 'the refund notification must not be deduplicated against the settlement (same transaction_id)');
prc_assert($paymentRow($midtransUuid)['status'] === 'REFUNDED' && (float) $paymentRow($midtransUuid)['refunded_amount'] === 75000.0, 'a refund webhook after PAID should mark REFUNDED');
prc_assert(!$hasGrant(1, 40), 'a refund webhook should revoke access');
prc_assert($midtransWebhook('refund')['body']['data']['duplicate'] === true, 'a retried refund notification should still be deduplicated');

// ---- 7. Reconcile: applies provider status to old PENDING, leaves fresh ones alone, fixes mismatches ----
$oldPendingUuid = $insertPayment('ORD-OLD-PENDING', 'PAYWUZ', 'PENDING', 50000, $cvMeta(50));
$providerStatus['ORD-OLD-PENDING'] = 'success';
$freshUuid = $insertPayment('ORD-FRESH', 'PAYWUZ', 'PENDING', 50000, $cvMeta(51), gmdate('Y-m-d H:i:s'));
$providerStatus['ORD-FRESH'] = 'success';
$manualUuid = $insertPayment('ORD-MANUAL', 'MANUAL_TRANSFER', 'PENDING', 50000, $cvMeta(52));
$stillPendingUuid = $insertPayment('ORD-STILL-PENDING', 'PAYWUZ', 'PENDING', 50000, $cvMeta(53));
$erroringUuid = $insertPayment('ORD-STATUS-ERRORS', 'PAYWUZ', 'PENDING', 50000, $cvMeta(54));
$mismatchUuid = $insertPayment('ORD-PAID-AFTER-CANCEL', 'PAYWUZ', 'CANCELLED', 50000, $cvMeta(55), gmdate('Y-m-d H:i:s', time() - 3600));
$providerStatus['ORD-PAID-AFTER-CANCEL'] = 'success';
// ORD-CANCEL-OK / ORD-CANCEL-FAILS from step 2-3 are CANCELLED but created in 2000, outside the mismatch window.

$providerRequests = [];
$reconcile = $call('POST', '/api/v1/me/payments/reconcile');
prc_assert($reconcile['status'] === 200, 'reconcile should succeed: ' . json_encode($reconcile));
$report = $reconcile['body']['data'];

prc_assert($paymentRow($oldPendingUuid)['status'] === 'PAID' && $hasGrant(1, 50), 'an old PENDING payment the provider reports paid should become PAID and be fulfilled');
prc_assert($paymentRow($freshUuid)['status'] === 'PENDING', 'a payment younger than the minimum age should be left alone');
prc_assert($paymentRow($manualUuid)['status'] === 'PENDING', 'MANUAL_TRANSFER has no status API and should be skipped');
prc_assert(!in_array('GET https://api.paywuz.id/v1/transactions/ORD-FRESH', $providerRequests, true), 'the fresh payment should not even be queried');
prc_assert($paymentRow($stillPendingUuid)['status'] === 'PENDING' && $report['unchanged'] === 1, 'a payment still pending at the provider should stay PENDING');
prc_assert(count($report['errors']) === 1 && $report['errors'][0]['uuid'] === $erroringUuid, 'a failing status call should be reported as an error: ' . json_encode($report['errors']));
prc_assert(count($report['mismatches']) === 1 && $report['mismatches'][0]['uuid'] === $mismatchUuid, 'a locally cancelled payment the provider says was paid should be reported: ' . json_encode($report['mismatches']));
prc_assert($paymentRow($mismatchUuid)['status'] === 'PAID' && $hasGrant(1, 55), 'the mismatch should be moved to PAID and fulfilled, since the money was taken');
prc_assert(count($report['updated']) === 2, 'exactly the old pending payment and the mismatch should be updated');

// ---- 8. Dashboard summary accounts for refunds ----
$summary = $call('GET', '/api/v1/me/dashboard/payments')['body']['data'];
prc_assert((float) $summary['refunded_amount'] === 145000.0, 'refunded total should be 50000 (CV) + 20000 (top-up) + 75000 (Midtrans), got ' . $summary['refunded_amount']);
prc_assert((int) $summary['refunded_count'] === 3, 'three payments were refunded');
prc_assert((float) $summary['available_balance'] === 100000.0, 'balance should only count the two paid-and-kept payments, got ' . $summary['available_balance']);

unset($router, $controller, $walletService, $wallets, $cvAccess, $payments, $auth, $nodes, $db);
Database::reset();
gc_collect_cycles();
unlink($envPath);
@unlink($dbPath);

fwrite(STDOUT, "Payment refund/cancel/reconcile test passed\n");
