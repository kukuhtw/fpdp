<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Exceptions\UnsupportedProviderException;
use App\Services\External\ExternalConnectorFactory;
use App\Services\Payment\PaymentGatewayFactory;

$payment = PaymentGatewayFactory::create('DUMMY', ['environment' => 'sandbox']);
if (!$payment instanceof \App\Contracts\PaymentGatewayInterface) {
    fwrite(STDERR, "Supported payment gateway code did not resolve\n");
    exit(1);
}

$connector = ExternalConnectorFactory::create('RSS', ['source_url' => 'https://example.com/feed.xml']);
if (!$connector instanceof \App\Contracts\ExternalContentProviderInterface) {
    fwrite(STDERR, "Supported connector code did not resolve\n");
    exit(1);
}

$rejectedReservedGateway = false;
try {
    PaymentGatewayFactory::create('MIDTRANS');
} catch (UnsupportedProviderException $e) {
    $rejectedReservedGateway = true;
}
if (!$rejectedReservedGateway) {
    fwrite(STDERR, "Reserved but unimplemented gateway code was not rejected\n");
    exit(1);
}

$rejectedUnknownGateway = false;
try {
    PaymentGatewayFactory::create('totally-unknown');
} catch (UnsupportedProviderException $e) {
    $rejectedUnknownGateway = true;
}
if (!$rejectedUnknownGateway) {
    fwrite(STDERR, "Unknown payment gateway code was not rejected\n");
    exit(1);
}

$rejectedUnknownConnector = false;
try {
    ExternalConnectorFactory::create('OAUTH_TWITTER');
} catch (UnsupportedProviderException $e) {
    $rejectedUnknownConnector = true;
}
if (!$rejectedUnknownConnector) {
    fwrite(STDERR, "Unknown connector code was not rejected\n");
    exit(1);
}

fwrite(STDOUT, "Factory fallback test passed\n");
