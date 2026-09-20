<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\Payment\PayPalGateway;

function paypal_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$envPath = sys_get_temp_dir() . '/fpdp-paypal-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nPAYPAL_CLIENT_ID=test-client-id\nPAYPAL_CLIENT_SECRET=test-client-secret\nPAYPAL_WEBHOOK_ID=test-webhook-id\n");
Config::load($envPath);

/**
 * A single fake transport that routes by URL/method, since every
 * PayPalGateway call (including createPayment/getPaymentStatus/etc.) first
 * fetches a fresh OAuth2 token through the same http_requester seam.
 *
 * @param array<string, callable> $routes keyed by "METHOD path-substring"
 */
function paypal_fake_http(array $routes, ?array &$captured = null): Closure
{
    return function (string $method, string $url, array $headers, ?string $body) use ($routes, &$captured): array {
        if ($captured !== null) {
            $captured[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        }
        if (str_contains($url, '/v1/oauth2/token')) {
            paypal_assert($headers['Authorization'] === 'Basic ' . base64_encode('test-client-id:test-client-secret'), 'oauth2 token request should send Basic auth built from client id/secret');
            paypal_assert($body === 'grant_type=client_credentials', 'oauth2 token request should send the client_credentials grant body');

            return ['status' => 200, 'body' => json_encode(['access_token' => 'fake-access-token', 'token_type' => 'Bearer', 'expires_in' => 32400])];
        }
        foreach ($routes as $key => $handler) {
            [$routeMethod, $needle] = explode(' ', $key, 2);
            if ($method === $routeMethod && str_contains($url, $needle)) {
                return $handler($url, $headers, $body);
            }
        }

        throw new RuntimeException("Unrouted fake PayPal request: {$method} {$url}");
    };
}

// ---- Test 1: createPayment sends the documented Orders v2 request and parses the approve link ----
$captured = [];
$fakeHttp = paypal_fake_http([
    'POST /v2/checkout/orders' => function (string $url, array $headers, ?string $body): array {
        paypal_assert($headers['Authorization'] === 'Bearer fake-access-token', 'create-order should send the OAuth Bearer token');
        $sent = json_decode((string) $body, true);
        paypal_assert($sent['intent'] === 'CAPTURE', 'create-order should use intent CAPTURE');
        paypal_assert($sent['purchase_units'][0]['reference_id'] === 'ORD-PP-1', 'create-order should carry the order id as reference_id');
        paypal_assert($sent['purchase_units'][0]['amount']['currency_code'] === 'USD', 'create-order should default currency to USD');
        paypal_assert($sent['purchase_units'][0]['amount']['value'] === '19.99', 'create-order should format a 2-decimal amount');

        return ['status' => 201, 'body' => json_encode([
            'id' => 'PAYPAL-ORDER-1',
            'status' => 'CREATED',
            'links' => [['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-ORDER-1'], ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1']],
        ])];
    },
], $captured);

$result = (new PayPalGateway(['http_requester' => $fakeHttp]))->createPayment(['order_id' => 'ORD-PP-1', 'amount' => 19.99]);
paypal_assert($result['gateway'] === 'PAYPAL', 'Result should report gateway PAYPAL');
paypal_assert($result['status'] === 'PENDING', 'A freshly created PayPal order should be PENDING');
paypal_assert($result['external_transaction_id'] === 'PAYPAL-ORDER-1', 'Result should surface the PayPal order id');
paypal_assert($result['payment_url'] === 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-1', 'Result should surface the approve link as payment_url');
paypal_assert($result['currency'] === 'USD', 'Result should report the resolved currency');

// ---- Test 2: IDR (and any unsupported currency) is rejected before any HTTP call ----
$rejectedIdr = false;
try {
    (new PayPalGateway(['http_requester' => fn () => paypal_assert(false, 'IDR must be rejected before any HTTP call')]))
        ->createPayment(['order_id' => 'ORD-PP-2', 'amount' => 100000, 'currency' => 'IDR']);
} catch (RuntimeException $e) {
    $rejectedIdr = str_contains($e->getMessage(), 'IDR');
}
paypal_assert($rejectedIdr, 'PayPal does not support IDR and should reject it with a clear message');

// ---- Test 3: order_id validation ----
$rejectedEmpty = false;
try {
    (new PayPalGateway(['http_requester' => $fakeHttp]))->createPayment(['order_id' => '', 'amount' => 10]);
} catch (RuntimeException) {
    $rejectedEmpty = true;
}
paypal_assert($rejectedEmpty, 'Empty order_id should be rejected before any HTTP call');

// ---- Test 4: getPaymentStatus captures an APPROVED order lazily and reports PAID ----
$captureCalled = false;
$statusHttp = paypal_fake_http([
    'GET /v2/checkout/orders/PAYPAL-ORDER-1' => function () use (&$captureCalled): array {
        return ['status' => 200, 'body' => json_encode(['id' => 'PAYPAL-ORDER-1', 'status' => $captureCalled ? 'COMPLETED' : 'APPROVED', 'purchase_units' => [['amount' => ['currency_code' => 'USD', 'value' => '19.99']]]])];
    },
    'POST /v2/checkout/orders/PAYPAL-ORDER-1/capture' => function (string $url, array $headers, ?string $body) use (&$captureCalled): array {
        $captureCalled = true;

        return ['status' => 201, 'body' => json_encode(['id' => 'PAYPAL-ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['amount' => ['currency_code' => 'USD', 'value' => '19.99']]]])];
    },
]);
$statusResult = (new PayPalGateway(['http_requester' => $statusHttp]))->getPaymentStatus('PAYPAL-ORDER-1');
paypal_assert($captureCalled, 'getPaymentStatus should capture an APPROVED order rather than just report PENDING');
paypal_assert($statusResult['status'] === 'PAID', 'A captured order should report PAID');
paypal_assert($statusResult['amount'] === 19.99, 'getPaymentStatus should surface the order amount');

// ---- Test 5: getPaymentStatus maps every order status PayPal documents ----
foreach ([['CREATED', 'PENDING'], ['SAVED', 'PENDING'], ['PAYER_ACTION_REQUIRED', 'PENDING'], ['VOIDED', 'CANCELLED'], ['COMPLETED', 'PAID']] as [$raw, $expected]) {
    $http = paypal_fake_http([
        'GET /v2/checkout/orders/ORD-STATUS' => fn (): array => ['status' => 200, 'body' => json_encode(['id' => 'ORD-STATUS', 'status' => $raw, 'purchase_units' => [['amount' => ['currency_code' => 'USD', 'value' => '5.00']]]])],
    ]);
    $mapped = (new PayPalGateway(['http_requester' => $http]))->getPaymentStatus('ORD-STATUS')['status'];
    paypal_assert($mapped === $expected, "order status={$raw} should normalize to {$expected}, got {$mapped}");
}

// ---- Test 6: cancelPayment always throws (no such endpoint exists for a CAPTURE-intent order) ----
$cancelThrew = false;
try {
    (new PayPalGateway(['http_requester' => fn () => paypal_assert(false, 'cancelPayment must not make any HTTP call')]))->cancelPayment('PAYPAL-ORDER-1');
} catch (RuntimeException) {
    $cancelThrew = true;
}
paypal_assert($cancelThrew, 'cancelPayment should throw rather than guess at a non-existent endpoint');

// ---- Test 7: refundPayment looks up the capture id from the order, then refunds it ----
$refundHttp = paypal_fake_http([
    'GET /v2/checkout/orders/PAYPAL-ORDER-1' => fn (): array => ['status' => 200, 'body' => json_encode([
        'id' => 'PAYPAL-ORDER-1', 'status' => 'COMPLETED',
        'purchase_units' => [['amount' => ['currency_code' => 'USD', 'value' => '19.99'], 'payments' => ['captures' => [['id' => 'CAPTURE-1']]]]],
    ])],
    'POST /v2/payments/captures/CAPTURE-1/refund' => function (string $url, array $headers, ?string $body): array {
        $sent = json_decode((string) $body, true);
        paypal_assert($sent['amount']['value'] === '10.00', 'refund should send the requested amount formatted to 2 decimals');
        paypal_assert($sent['amount']['currency_code'] === 'USD', 'refund should reuse the order currency');

        return ['status' => 201, 'body' => json_encode(['id' => 'REFUND-1', 'status' => 'COMPLETED'])];
    },
]);
$refundResult = (new PayPalGateway(['http_requester' => $refundHttp]))->refundPayment('PAYPAL-ORDER-1', 10.0);
paypal_assert($refundResult['status'] === 'REFUNDED', 'refundPayment should report REFUNDED');

// ---- Test 8: verifyWebhook rejects a request missing the paypal-* headers before any HTTP call ----
$gateway = new PayPalGateway(['http_requester' => fn () => paypal_assert(false, 'verifyWebhook must not call the API when headers are missing')]);
paypal_assert($gateway->verifyWebhook([], json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])) === false, 'Missing paypal-* headers should fail verification locally, with no API call');

// ---- Test 9: verifyWebhook calls the verify-webhook-signature API and trusts its verdict ----
$paypalHeaders = [
    'paypal-auth-algo' => 'SHA256withRSA',
    'paypal-cert-url' => 'https://api.sandbox.paypal.com/cert.pem',
    'paypal-transmission-id' => 'txn-1',
    'paypal-transmission-sig' => 'sig-1',
    'paypal-transmission-time' => '2026-09-20T00:00:00Z',
];
$verifiedHttp = paypal_fake_http([
    'POST /v1/notifications/verify-webhook-signature' => function (string $url, array $headers, ?string $body): array {
        $sent = json_decode((string) $body, true);
        paypal_assert($sent['webhook_id'] === 'test-webhook-id', 'verify-webhook-signature should send the configured webhook_id');
        paypal_assert($sent['transmission_id'] === 'txn-1', 'verify-webhook-signature should forward the paypal-transmission-id header');

        return ['status' => 200, 'body' => json_encode(['verification_status' => 'SUCCESS'])];
    },
]);
paypal_assert((new PayPalGateway(['http_requester' => $verifiedHttp]))->verifyWebhook($paypalHeaders, json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])) === true, 'A SUCCESS verification_status should verify true');

$failedHttp = paypal_fake_http([
    'POST /v1/notifications/verify-webhook-signature' => fn (): array => ['status' => 200, 'body' => json_encode(['verification_status' => 'FAILURE'])],
]);
paypal_assert((new PayPalGateway(['http_requester' => $failedHttp]))->verifyWebhook($paypalHeaders, json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])) === false, 'A FAILURE verification_status should verify false');

// ---- Test 10: handleWebhook normalizes CHECKOUT.ORDER.* and PAYMENT.CAPTURE.* events, extracting order_id correctly for each shape ----
$approved = (new PayPalGateway())->handleWebhook([], json_encode([
    'id' => 'WH-EVT-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'create_time' => '2026-09-20T00:00:00Z',
    'resource' => ['id' => 'PAYPAL-ORDER-1'],
]));
paypal_assert($approved['order_id'] === 'PAYPAL-ORDER-1', 'CHECKOUT.ORDER.APPROVED should read the order id from resource.id');
paypal_assert($approved['status'] === 'PENDING', 'CHECKOUT.ORDER.APPROVED should normalize to PENDING');

$captured1 = (new PayPalGateway())->handleWebhook([], json_encode([
    'id' => 'WH-EVT-2', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
    'resource' => ['id' => 'CAPTURE-1', 'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-ORDER-1']]],
]));
paypal_assert($captured1['order_id'] === 'PAYPAL-ORDER-1', 'PAYMENT.CAPTURE.COMPLETED should read the order id from resource.supplementary_data.related_ids.order_id');
paypal_assert($captured1['status'] === 'PAID', 'PAYMENT.CAPTURE.COMPLETED should normalize to PAID');
paypal_assert($captured1['event_id'] === 'WH-EVT-2', 'handleWebhook should surface PayPal\'s own event id');

foreach ([['PAYMENT.CAPTURE.DENIED', 'FAILED'], ['PAYMENT.CAPTURE.REFUNDED', 'REFUNDED'], ['PAYMENT.CAPTURE.PENDING', 'PENDING'], ['SOMETHING.ELSE', 'UNKNOWN']] as [$rawEvent, $expected]) {
    $event = (new PayPalGateway())->handleWebhook([], json_encode([
        'id' => 'WH-EVT-X', 'event_type' => $rawEvent,
        'resource' => ['id' => 'CAPTURE-X', 'supplementary_data' => ['related_ids' => ['order_id' => 'PAYPAL-ORDER-1']]],
    ]));
    paypal_assert($event['status'] === $expected, "event_type={$rawEvent} should normalize to {$expected}, got {$event['status']}");
}

unlink($envPath);
fwrite(STDOUT, "PayPal gateway test passed\n");
