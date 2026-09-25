<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../gateways/ipaymu/Gateway.php';

use App\Core\Config;
use FpdpGatewayPlugins\Ipaymu\Gateway as IpaymuGateway;

function ipaymu_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$envPath = sys_get_temp_dir() . '/fpdp-ipaymu-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nNODE_DOMAIN=kukuhtw.test\n");
Config::load($envPath);

$config = ['va' => '1179000899', 'api_key' => 'test-api-key'];

// ---- Test 1: createPayment signs the request per iPaymu's documented algorithm and parses Data.Url/SessionID ----
$captured = null;
$fakeHttp = function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
    $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

    return ['status' => 200, 'body' => json_encode(['Status' => 200, 'Message' => 'Success', 'Data' => ['SessionID' => 'sess-1', 'Url' => 'https://sandbox.ipaymu.com/payment/sess-1']])];
};

$gateway = new IpaymuGateway($config + ['http_requester' => $fakeHttp]);
$result = $gateway->createPayment([
    'order_id' => 'ORD-IP-1',
    'amount' => 98000,
    'description' => 'Produk A',
    'payer_email' => 'buyer@example.test',
]);

ipaymu_assert($captured['method'] === 'POST', 'createPayment should POST');
ipaymu_assert($captured['url'] === 'https://sandbox.ipaymu.com/api/v2/payment', 'createPayment should default to the sandbox Redirect Payment endpoint');
ipaymu_assert($captured['headers']['va'] === '1179000899', 'Request should carry the va header');
ipaymu_assert($captured['headers']['timestamp'] !== '' && preg_match('/^\d{14}$/', $captured['headers']['timestamp']) === 1, 'timestamp header should be YmdHis');

$sentBody = (string) $captured['body'];
$expectedHash = strtolower(hash('sha256', $sentBody));
$expectedStringToSign = 'POST:1179000899:' . $expectedHash . ':test-api-key';
$expectedSignature = hash_hmac('sha256', $expectedStringToSign, 'test-api-key');
ipaymu_assert($captured['headers']['signature'] === $expectedSignature, 'signature header should be hex HMAC-SHA256(METHOD:va:sha256(body):apiKey) keyed by apiKey');

$sentPayload = json_decode($sentBody, true);
ipaymu_assert($sentPayload['product'] === ['Produk A'], 'Sent payload should carry the product line');
ipaymu_assert($sentPayload['price'] == [98000], 'Sent payload should carry the price as a single-item array');
ipaymu_assert($sentPayload['referenceId'] === 'ORD-IP-1', 'Sent payload should carry referenceId from order_id');
ipaymu_assert($sentPayload['notifyUrl'] === 'https://kukuhtw.test/api/v1/payments/webhook/IPAYMU', 'notifyUrl should point at this node\'s webhook endpoint');
ipaymu_assert($sentPayload['buyerEmail'] === 'buyer@example.test', 'Sent payload should carry the payer email');

ipaymu_assert($result['gateway'] === 'IPAYMU', 'Result should report gateway IPAYMU');
ipaymu_assert($result['status'] === 'PENDING', 'A freshly created iPaymu payment should be PENDING');
ipaymu_assert($result['payment_url'] === 'https://sandbox.ipaymu.com/payment/sess-1', 'Result should surface Data.Url as payment_url');
ipaymu_assert($result['session_id'] === 'sess-1', 'Result should surface the raw SessionID too');

// ---- Test 2: order_id and amount validation ----
$rejectedEmpty = false;
try {
    (new IpaymuGateway($config + ['http_requester' => $fakeHttp]))->createPayment(['order_id' => '', 'amount' => 1000]);
} catch (RuntimeException) {
    $rejectedEmpty = true;
}
ipaymu_assert($rejectedEmpty, 'Empty order_id should be rejected before any HTTP call');

$rejectedZero = false;
try {
    (new IpaymuGateway($config + ['http_requester' => $fakeHttp]))->createPayment(['order_id' => 'ORD-2', 'amount' => 0]);
} catch (RuntimeException) {
    $rejectedZero = true;
}
ipaymu_assert($rejectedZero, 'Zero/negative amount should be rejected before any HTTP call');

// ---- Test 3: a non-2xx / failing envelope response is surfaced as a RuntimeException ----
$failingHttp = fn (string $m, string $u, array $h, ?string $b): array => ['status' => 200, 'body' => json_encode(['Status' => 400, 'Message' => 'Va Number tidak ditemukan'])];
$httpFailed = false;
try {
    (new IpaymuGateway($config + ['http_requester' => $failingHttp]))->createPayment(['order_id' => 'ORD-3', 'amount' => 1000]);
} catch (RuntimeException $e) {
    $httpFailed = str_contains($e->getMessage(), 'Va Number tidak ditemukan');
}
ipaymu_assert($httpFailed, 'A failing iPaymu envelope (Status >= 400) should raise a RuntimeException carrying the gateway message');

// ---- Test 4: getPaymentStatus hits POST /transaction with {transactionId} and normalizes the result ----
$statusHttp = function (string $method, string $url, array $headers, ?string $body): array {
    ipaymu_assert($method === 'POST', 'getPaymentStatus should POST');
    ipaymu_assert($url === 'https://sandbox.ipaymu.com/api/v2/transaction', 'getPaymentStatus should hit the transaction endpoint');
    $sent = json_decode((string) $body, true);
    ipaymu_assert($sent['transactionId'] === 'sess-1', 'getPaymentStatus should send transactionId in the body');

    return ['status' => 200, 'body' => json_encode(['Status' => 200, 'Data' => ['status_code' => 1, 'amount' => 98000]])];
};
$statusResult = (new IpaymuGateway($config + ['http_requester' => $statusHttp]))->getPaymentStatus('sess-1');
ipaymu_assert($statusResult['status'] === 'PAID', 'status_code=1 should normalize to PAID');

// ---- Test 5: cancelPayment/refundPayment throw rather than fabricate success ----
$threwOnCancel = false;
try {
    (new IpaymuGateway($config))->cancelPayment('sess-1');
} catch (RuntimeException) {
    $threwOnCancel = true;
}
ipaymu_assert($threwOnCancel, 'cancelPayment should throw — iPaymu v2 has no cancel endpoint');

$threwOnRefund = false;
try {
    (new IpaymuGateway($config))->refundPayment('sess-1', 1000.0);
} catch (RuntimeException) {
    $threwOnRefund = true;
}
ipaymu_assert($threwOnRefund, 'refundPayment should throw — iPaymu v2 has no refund endpoint');

// ---- Test 6: verifyWebhook recomputes HMAC-SHA256 keyed by va, over ascending-sorted, type-normalized fields ----
$gateway = new IpaymuGateway($config);
$callback = [
    'trx_id' => '12345',
    'reference_id' => 'ORD-IP-1',
    'status' => 'berhasil',
    'status_code' => '1',
    'amount' => '98000',
    'sid' => 'sess-1',
];
$canonical = $callback;
$canonical['trx_id'] = (int) $canonical['trx_id'];
$canonical['status_code'] = (int) $canonical['status_code'];
ksort($canonical, SORT_STRING);
$validSignature = hash_hmac('sha256', (string) json_encode($canonical), '1179000899');
$formBody = http_build_query($callback);

ipaymu_assert($gateway->verifyWebhook(['x-signature' => $validSignature], $formBody) === true, 'A correctly signed form-urlencoded callback should verify');
ipaymu_assert($gateway->verifyWebhook(['x-signature' => 'deadbeef'], $formBody) === false, 'A wrong signature should be rejected');
ipaymu_assert($gateway->verifyWebhook([], $formBody) === false, 'A missing X-Signature header should be rejected');

$jsonBody = (string) json_encode($callback);
$canonicalFromJson = $callback;
$canonicalFromJson['trx_id'] = (int) $canonicalFromJson['trx_id'];
$canonicalFromJson['status_code'] = (int) $canonicalFromJson['status_code'];
ksort($canonicalFromJson, SORT_STRING);
$validJsonSignature = hash_hmac('sha256', (string) json_encode($canonicalFromJson), '1179000899');
ipaymu_assert($gateway->verifyWebhook(['x-signature' => $validJsonSignature], $jsonBody) === true, 'A JSON-body callback (the documented alternative content type) should also verify');

// ---- Test 7: handleWebhook normalizes status_code and surfaces order_id/external_transaction_id ----
$event = $gateway->handleWebhook([], $formBody);
ipaymu_assert($event['order_id'] === 'ORD-IP-1', 'handleWebhook should surface reference_id as order_id');
ipaymu_assert($event['external_transaction_id'] === 'sess-1', 'handleWebhook should prefer sid as external_transaction_id');
ipaymu_assert($event['status'] === 'PAID', 'status_code=1 should normalize to PAID');
ipaymu_assert($event['amount'] === 98000.0, 'handleWebhook should surface the numeric amount');

foreach ([['0', 'PENDING'], ['-2', 'FAILED']] as [$rawCode, $expected]) {
    $n = $callback;
    $n['status_code'] = $rawCode;
    $ev = $gateway->handleWebhook([], http_build_query($n));
    ipaymu_assert($ev['status'] === $expected, "status_code={$rawCode} should normalize to {$expected}, got {$ev['status']}");
}

unlink($envPath);
fwrite(STDOUT, "iPaymu gateway plugin test passed\n");
