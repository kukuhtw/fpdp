#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Refreshes this node owner's open purchases from other FPDP nodes
 * (node-to-node orders): re-reads each order's status from the seller with a
 * signed GET, and re-sends orders that never got through (same client
 * reference, so the seller never makes a second order).
 *
 * Usage:  php scripts/sync-purchases.php
 * Cron:   every 15 minutes (see deploy/ubuntu/fpdp.cron); Dokploy: the scheduler service.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\HttpClient;
use App\Repositories\FederatedPostRepository;
use App\Repositories\FederatedPurchaseRepository;
use App\Repositories\NodeKeyRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Services\Federation\NodeKeyService;
use App\Services\Federation\SignedRequestSigner;
use App\Services\Marketplace\FederatedPurchaseService;

Config::load(dirname(__DIR__) . '/.env');
$connection = Database::connection();
$node = (new NodeRepository($connection))->findFirst();
if ($node === null) {
    fwrite(STDOUT, "[sync-purchases] no node yet\n");
    exit(0);
}

$service = new FederatedPurchaseService(
    new FederatedPostRepository($connection),
    new FederatedPurchaseRepository($connection),
    new SignedRequestSigner(new NodeKeyService(new NodeKeyRepository($connection)), new ProfileRepository($connection)),
    new HttpClient(),
);
$result = $service->syncOpen((string) $node['domain']);

fwrite(STDOUT, sprintf("[sync-purchases] %s synced %d, failed %d\n", date('Y-m-d H:i:s'), $result['synced'], $result['failed']));
