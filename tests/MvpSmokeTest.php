<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\External\ExternalConnectorFactory;
use App\Services\Payment\PaymentGatewayFactory;

$paymentGateway = PaymentGatewayFactory::create('DUMMY', ['environment' => 'sandbox']);
$payment = $paymentGateway->createPayment([
    'order_id' => 'ORD-1001',
    'amount' => 250000,
    'currency' => 'IDR',
    'description' => 'Demo purchase'
]);

if (($payment['status'] ?? null) !== 'PENDING') {
    fwrite(STDERR, "Payment creation failed\n");
    exit(1);
}

if (($payment['payment_url'] ?? '') === '') {
    fwrite(STDERR, "Payment URL missing\n");
    exit(1);
}

$connector = ExternalConnectorFactory::create('RSS', ['source_url' => 'https://example.com/feed.xml']);
$feed = $connector->fetchPosts(['source_url' => 'https://example.com/feed.xml']);

if (!is_array($feed) || !isset($feed['items']) || !is_array($feed['items'])) {
    fwrite(STDERR, "RSS feed parsing failed\n");
    exit(1);
}

fwrite(STDOUT, "Smoke test passed\n");
