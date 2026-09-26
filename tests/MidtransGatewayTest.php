<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\Payment\MidtransGateway;

function midtrans_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$envPath = sys_get_temp_dir() . '/fpdp-midtrans-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nMIDTRANS_SERVER_KEY=test-server-key\n");
Config::load($envPath);

// ---- Test 1: createPayment sends the documented Snap request and parses token/redirect_url ----
$capturedRequest = null;
$fakeHttp = function (string $method, string $url, array $headers, ?string $body) use (&$capturedRequest): array {
    $capturedRequest = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

    return ['status' => 201, 'body' => json_encode(['token' => 'snap-token-1', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-1'])];
};

$gateway = new MidtransGateway(['http_requester' => $fakeHttp]);
$result = $gateway->createPayment([
    'order_id' => 'ORD-MT-1',
    'amount' => 75000,
    'payer_email' => 'buyer@example.test',
    'payer_name' => 'Buyer',
]);

midtrans_assert($capturedRequest['method'] === 'POST', 'createPayment should POST');
midtrans_assert($capturedRequest['url'] === 'https://app.sandbox.midtrans.com/snap/v1/transactions', 'createPayment should default to the sandbox Snap URL');
midtrans_assert($capturedRequest['headers']['Authorization'] === 'Basic ' . base64_encode('test-server-key:'), 'createPayment should send HTTP Basic auth built from the server key');
$sentPayload = json_decode((string) $capturedRequest['body'], true);
midtrans_assert($sentPayload['transaction_details']['order_id'] === 'ORD-MT-1', 'Sent payload should carry the order id');
midtrans_assert($sentPayload['transaction_details']['gross_amount'] === 75000, 'Sent payload should carry the integer gross_amount');
midtrans_assert($sentPayload['customer_details']['email'] === 'buyer@example.test', 'Sent payload should carry the payer email');

midtrans_assert($result['gateway'] === 'MIDTRANS', 'Result should report gateway MIDTRANS');
midtrans_assert($result['status'] === 'PENDING', 'A freshly created Midtrans payment should be PENDING');
midtrans_assert($result['payment_url'] === 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-1', 'Result should surface redirect_url as payment_url');
midtrans_assert($result['snap_token'] === 'snap-token-1', 'Result should surface the raw Snap token too');

// ---- Test 2: order_id validation (empty and over 50 chars) ----
$rejectedEmpty = false;
try {
    (new MidtransGateway(['http_requester' => $fakeHttp]))->createPayment(['order_id' => '', 'amount' => 1000]);
} catch (RuntimeException) {
    $rejectedEmpty = true;
}
midtrans_assert($rejectedEmpty, 'Empty order_id should be rejected before any HTTP call');

$rejectedTooLong = false;
try {
    (new MidtransGateway(['http_requester' => $fakeHttp]))->createPayment(['order_id' => str_repeat('x', 51), 'amount' => 1000]);
} catch (RuntimeException) {
    $rejectedTooLong = true;
}
midtrans_assert($rejectedTooLong, 'order_id longer than 50 chars should be rejected');

// ---- Test 3: a non-2xx response is surfaced as a RuntimeException ----
$failingHttp = fn (string $m, string $u, array $h, ?string $b): array => ['status' => 400, 'body' => json_encode(['error_messages' => ['gross_amount is required']])];
$httpFailed = false;
try {
    (new MidtransGateway(['http_requester' => $failingHttp]))->createPayment(['order_id' => 'ORD-2', 'amount' => 1000]);
} catch (RuntimeException $e) {
    $httpFailed = str_contains($e->getMessage(), 'gross_amount is required');
}
midtrans_assert($httpFailed, 'A failing HTTP response should raise a RuntimeException carrying the gateway message');

// ---- Test 4: getPaymentStatus hits the Core API status endpoint and normalizes the result ----
$statusHttp = function (string $method, string $url, array $headers, ?string $body): array {
    midtrans_assert($method === 'GET', 'getPaymentStatus should GET');
    midtrans_assert($url === 'https://api.sandbox.midtrans.com/v2/ORD-MT-1/status', 'getPaymentStatus should hit the Core API status endpoint for the given id');

    return ['status' => 200, 'body' => json_encode(['transaction_status' => 'settlement', 'gross_amount' => '75000.00', 'currency' => 'IDR'])];
};
$statusResult = (new MidtransGateway(['http_requester' => $statusHttp]))->getPaymentStatus('ORD-MT-1');
midtrans_assert($statusResult['status'] === 'PAID', 'settlement should normalize to PAID');

// ---- Test 5: cancelPayment and refundPayment hit the documented Core API endpoints ----
$cancelHttp = function (string $method, string $url, array $headers, ?string $body): array {
    midtrans_assert($method === 'POST' && str_ends_with($url, '/ORD-MT-1/cancel'), 'cancelPayment should POST to the cancel endpoint');

    return ['status' => 200, 'body' => json_encode(['transaction_status' => 'cancel'])];
};
midtrans_assert((new MidtransGateway(['http_requester' => $cancelHttp]))->cancelPayment('ORD-MT-1')['status'] === 'CANCELLED', 'cancelPayment should report CANCELLED');

$refundHttp = function (string $method, string $url, array $headers, ?string $body): array {
    midtrans_assert($method === 'POST' && str_ends_with($url, '/ORD-MT-1/refund'), 'refundPayment should POST to the refund endpoint');
    $sent = json_decode((string) $body, true);
    midtrans_assert($sent['amount'] === 25000, 'refundPayment should send the rounded refund amount');

    return ['status' => 200, 'body' => json_encode(['transaction_status' => 'refund'])];
};
midtrans_assert((new MidtransGateway(['http_requester' => $refundHttp]))->refundPayment('ORD-MT-1', 25000.0)['status'] === 'REFUNDED', 'refundPayment should report REFUNDED');

// ---- Test 6: verifyWebhook recomputes SHA512(order_id+status_code+gross_amount+ServerKey) ----
$gateway = new MidtransGateway();
$validNotification = [
    'order_id' => 'ORD-MT-1',
    'status_code' => '200',
    'gross_amount' => '75000.00',
    'transaction_status' => 'settlement',
];
$validNotification['signature_key'] = hash('sha512', 'ORD-MT-1' . '200' . '75000.00' . 'test-server-key');
$validBody = json_encode($validNotification);

midtrans_assert($gateway->verifyWebhook([], $validBody) === true, 'A correctly signed Midtrans notification should verify');

$tampered = $validNotification;
$tampered['gross_amount'] = '1.00';
midtrans_assert($gateway->verifyWebhook([], json_encode($tampered)) === false, 'A tampered gross_amount should fail signature verification');

$missingSignature = $validNotification;
unset($missingSignature['signature_key']);
midtrans_assert($gateway->verifyWebhook([], json_encode($missingSignature)) === false, 'A missing signature_key should be rejected');

// ---- Test 7: handleWebhook normalizes every transaction_status Midtrans documents ----
$capture = $validNotification;
$capture['transaction_status'] = 'capture';
$capture['fraud_status'] = 'accept';
midtrans_assert($gateway->handleWebhook([], json_encode($capture))['status'] === 'PAID', 'capture+accept should normalize to PAID');

$capturePending = $validNotification;
$capturePending['transaction_status'] = 'capture';
$capturePending['fraud_status'] = 'challenge';
midtrans_assert($gateway->handleWebhook([], json_encode($capturePending))['status'] === 'PENDING', 'capture+challenge should normalize to PENDING, not auto-PAID');

midtrans_assert($gateway->handleWebhook([], $validBody)['status'] === 'PAID', 'settlement should normalize to PAID');

foreach ([['pending', 'PENDING'], ['deny', 'FAILED'], ['cancel', 'CANCELLED'], ['expire', 'FAILED'], ['refund', 'REFUNDED'], ['partial_refund', 'PARTIALLY_REFUNDED'], ['something_else', 'UNKNOWN']] as [$raw, $expected]) {
    $n = $validNotification;
    $n['transaction_status'] = $raw;
    $event = $gateway->handleWebhook([], json_encode($n));
    midtrans_assert($event['status'] === $expected, "transaction_status={$raw} should normalize to {$expected}, got {$event['status']}");
}

unlink($envPath);
fwrite(STDOUT, "Midtrans gateway test passed\n");
