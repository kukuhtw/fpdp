<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\Payment\PaywuzGateway;

function paywuz_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$envPath = sys_get_temp_dir() . '/fpdp-paywuz-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nPAYWUZ_API_KEY=test-secret-key\n");
Config::load($envPath);

// ---- Test 1: createPayment sends the documented request shape and parses the response ----
$capturedRequest = null;
$fakeHttp = function (string $method, string $url, array $headers, ?string $body) use (&$capturedRequest): array {
    $capturedRequest = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

    return [
        'status' => 201,
        'body' => json_encode(['data' => ['paymentUrl' => 'https://checkout.paywuz.id/abc123', 'transactionId' => 'TRX-1']]),
    ];
};

$gateway = new PaywuzGateway(['http_requester' => $fakeHttp]);
$result = $gateway->createPayment([
    'order_id' => 'ORD-TEST-1',
    'amount' => 50000,
    'redirect_url' => 'https://example.test/return',
    'metadata' => ['purpose' => 'cv_access', 'document_id' => 1, 'visitor_id' => 2],
]);

paywuz_assert($capturedRequest['method'] === 'POST', 'createPayment should POST');
paywuz_assert($capturedRequest['url'] === 'https://api.paywuz.id/v1/transactions', 'createPayment should hit /transactions on the default base URL');
paywuz_assert($capturedRequest['headers']['Authorization'] === 'Bearer test-secret-key', 'createPayment should send a Bearer token built from PAYWUZ_API_KEY');
$sentPayload = json_decode((string) $capturedRequest['body'], true);
paywuz_assert($sentPayload['orderId'] === 'ORD-TEST-1', 'Sent payload should carry the order id');
paywuz_assert($sentPayload['amount'] === 50000, 'Sent payload should carry the integer amount');
paywuz_assert($sentPayload['paymentMethod'] === 'ALL', 'Sent payload should default paymentMethod to ALL');
paywuz_assert($sentPayload['metadata']['purpose'] === 'cv_access', 'Sent payload should carry metadata through');

paywuz_assert($result['gateway'] === 'PAYWUZ', 'Result should report gateway PAYWUZ');
paywuz_assert($result['status'] === 'PENDING', 'A freshly created Paywuz payment should be PENDING');
paywuz_assert($result['payment_url'] === 'https://checkout.paywuz.id/abc123', 'Result should surface the paymentUrl from the response');
paywuz_assert($result['order_id'] === 'ORD-TEST-1', 'Result should echo back the order id');

// ---- Test 2: order_id validation (empty and over 64 chars) ----
$rejectedEmpty = false;
try {
    (new PaywuzGateway(['http_requester' => $fakeHttp]))->createPayment(['order_id' => '', 'amount' => 1000]);
} catch (RuntimeException) {
    $rejectedEmpty = true;
}
paywuz_assert($rejectedEmpty, 'Empty order_id should be rejected before any HTTP call');

$rejectedTooLong = false;
try {
    (new PaywuzGateway(['http_requester' => $fakeHttp]))->createPayment(['order_id' => str_repeat('x', 65), 'amount' => 1000]);
} catch (RuntimeException) {
    $rejectedTooLong = true;
}
paywuz_assert($rejectedTooLong, 'order_id longer than 64 chars should be rejected');

// ---- Test 3: a non-2xx response is surfaced as a RuntimeException ----
$failingHttp = fn (string $m, string $u, array $h, ?string $b): array => ['status' => 500, 'body' => json_encode(['message' => 'boom'])];
$httpFailed = false;
try {
    (new PaywuzGateway(['http_requester' => $failingHttp]))->createPayment(['order_id' => 'ORD-2', 'amount' => 1000]);
} catch (RuntimeException $e) {
    $httpFailed = str_contains($e->getMessage(), 'boom');
}
paywuz_assert($httpFailed, 'A failing HTTP response should raise a RuntimeException carrying the gateway message');

// ---- Test 4: verifyWebhook accepts a correctly signed payload and rejects tampering ----
$gateway = new PaywuzGateway();
$rawBody = json_encode(['event' => 'transaction.paid', 'data' => ['orderId' => 'ORD-TEST-1', 'status' => 'success']]);
$validSignature = 'sha256=' . hash_hmac('sha256', $rawBody, 'test-secret-key');

paywuz_assert($gateway->verifyWebhook(['x-paywuz-signature' => $validSignature], $rawBody) === true, 'A correctly signed webhook should verify');
paywuz_assert($gateway->verifyWebhook(['x-paywuz-signature' => 'sha256=deadbeef'], $rawBody) === false, 'A tampered/wrong signature should be rejected');
paywuz_assert($gateway->verifyWebhook([], $rawBody) === false, 'A missing signature header should be rejected');

// ---- Test 5: handleWebhook normalizes paid/failed/cancelled/unknown events ----
$paidEvent = $gateway->handleWebhook(['x-paywuz-delivery' => 'dlv-1'], $rawBody);
paywuz_assert($paidEvent['status'] === 'PAID', 'transaction.paid + status=success should normalize to PAID');
paywuz_assert($paidEvent['order_id'] === 'ORD-TEST-1', 'handleWebhook should surface the order id');
paywuz_assert($paidEvent['event_id'] === 'dlv-1', 'handleWebhook should use X-Paywuz-Delivery as the event id when present');

$failedBody = json_encode(['event' => 'transaction.failed', 'data' => ['orderId' => 'ORD-TEST-1']]);
$failedEvent = $gateway->handleWebhook([], $failedBody);
paywuz_assert($failedEvent['status'] === 'FAILED', 'transaction.failed should normalize to FAILED');
paywuz_assert($failedEvent['event_id'] === hash('sha256', $failedBody), 'handleWebhook should fall back to a body hash when no delivery header is present');

$cancelledBody = json_encode(['event' => 'transaction.cancelled', 'data' => ['orderId' => 'ORD-TEST-1']]);
paywuz_assert($gateway->handleWebhook([], $cancelledBody)['status'] === 'CANCELLED', 'transaction.cancelled should normalize to CANCELLED');

$unknownBody = json_encode(['event' => 'something.else', 'data' => ['orderId' => 'ORD-TEST-1']]);
paywuz_assert($gateway->handleWebhook([], $unknownBody)['status'] === 'UNKNOWN', 'An unrecognized event should normalize to UNKNOWN, not throw');

// ---- Test 6: undocumented operations fail loudly instead of guessing an endpoint ----
foreach (['getPaymentStatus', 'cancelPayment', 'refundPayment'] as $method) {
    $threw = false;
    try {
        $method === 'refundPayment' ? $gateway->refundPayment('TRX-1', 100.0) : $gateway->{$method}('TRX-1');
    } catch (RuntimeException) {
        $threw = true;
    }
    paywuz_assert($threw, "{$method}() should throw rather than guess an undocumented Paywuz endpoint");
}

unlink($envPath);
fwrite(STDOUT, "Paywuz gateway test passed\n");
