#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FPDP Payment Reconciliation — CLI entry point for cron.
 *
 * Asks each gateway for the real status of payments still PENDING locally
 * (the safety net for a webhook that never arrived), applies any terminal
 * status and runs fulfillment for newly PAID ones. A payment cancelled or
 * failed locally that the provider says was actually paid is moved to PAID
 * and fulfilled too (money was taken), and reported as a MISMATCH so the
 * owner can refund it from the dashboard if the sale is unwanted.
 *
 * Usage:
 *   php scripts/reconcile-payments.php                  # PENDING older than 15 min, max 100
 *   php scripts/reconcile-payments.php --min-age=30 --max=50
 *
 * Add to crontab every 15 minutes (remove the space between stars):
 *   star/15 * * * * /usr/bin/php /var/www/fpdp/scripts/reconcile-payments.php
 *
 * Exit code 1 when any mismatch or gateway error was found, so cron/monitoring
 * can alert on it.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\PaymentController;
use App\Core\Config;
use App\Core\Database;
use App\Repositories\AuditEventRepository;
use App\Repositories\AuthTokenRepository;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Repositories\NodeRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentGatewayConfigRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorWalletRepository;
use App\Services\Auth\AuthService;
use App\Services\Chatbot\VisitorWalletService;
use App\Services\Cv\CvAccessService;
use App\Services\Marketplace\MarketplaceService;
use App\Services\Payment\PaymentService;
use App\Services\Security\AuditService;

$options = getopt('', ['min-age::', 'max::']);
$minAgeMinutes = isset($options['min-age']) ? max(0, (int) $options['min-age']) : 15;
$maxPayments = isset($options['max']) ? max(1, (int) $options['max']) : 100;

Config::load(dirname(__DIR__) . '/.env');
$connection = Database::connection();

$nodes = new NodeRepository($connection);
$audit = new AuditService(new AuditEventRepository($connection));
$payments = new PaymentService(
    payments: new PaymentRepository($connection),
    gatewayConfigs: new PaymentGatewayConfigRepository($connection),
    nodes: $nodes,
);
$controller = new PaymentController(
    new AuthService($nodes, new UserRepository($connection), new ProfileRepository($connection), new AuthTokenRepository($connection), $audit),
    $payments,
    new CvAccessService(
        new CvDocumentRepository($connection),
        new CvAccessGrantRepository($connection),
        $payments,
        $nodes,
        dirname(__DIR__) . '/storage/cv',
    ),
    new MarketplaceService(
        new ProductRepository($connection),
        new OrderRepository($connection),
        new OrderItemRepository($connection),
        $payments,
        $nodes,
    ),
    new VisitorWalletService(new VisitorWalletRepository($connection), new VisitorRepository($connection), $payments, $nodes),
    $audit,
);

// No acting user: the audit entry is attributed to the node (actor null).
$node = $nodes->findFirst();
$report = $controller->reconcileAndFulfill($minAgeMinutes, $maxPayments, $node !== null ? ['node' => $node] : null);
$timestamp = gmdate('Y-m-d H:i:s');

fwrite(STDOUT, sprintf(
    "[reconcile-payments] %s checked=%d updated=%d unchanged=%d errors=%d mismatches=%d\n",
    $timestamp,
    $report['checked'],
    count($report['updated']),
    $report['unchanged'],
    count($report['errors']),
    count($report['mismatches']),
));
foreach ($report['updated'] as $payment) {
    fwrite(STDOUT, "[reconcile-payments] {$payment['uuid']} ({$payment['gateway_code']}) -> {$payment['status']}\n");
}
foreach ($report['errors'] as $error) {
    fwrite(STDERR, "[reconcile-payments] error {$error['uuid']}: {$error['message']}\n");
}
foreach ($report['mismatches'] as $mismatch) {
    fwrite(STDERR, sprintf(
        "[reconcile-payments] MISMATCH %s (%s, %s %s): was %s locally but %s at the provider — marked PAID and fulfilled; refund it from the dashboard if the sale is unwanted\n",
        $mismatch['uuid'],
        $mismatch['gateway_code'],
        $mismatch['currency'],
        number_format((float) $mismatch['amount'], 2, '.', ''),
        $mismatch['local_status'],
        $mismatch['provider_status'],
    ));
}

exit($report['errors'] === [] && $report['mismatches'] === [] ? 0 : 1);
