#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FPDP Federation Delivery Worker — CLI entry point for cron.
 *
 * Processes pending outgoing federation activities:
 * signs the payload, sends to remote inbox, updates status.
 *
 * Usage:
 *   php deliver-federation.php                       # process all pending (default: 10)
 *   php deliver-federation.php --max=5               # process max 5 activities
 *
 * Add to crontab every 5 minutes (remove the space between stars):
 *   star/5 * * * * /usr/bin/php /var/www/fpdp/deliver-federation.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\HttpClient;
use App\Repositories\FederationActivityRepository;
use App\Repositories\NodeKeyRepository;
use App\Services\Federation\NodeKeyService;

$options = getopt('', ['max::']);
$maxActivities = isset($options['max']) ? (int) $options['max'] : 10;

Config::load(__DIR__ . '/.env');
$connection = Database::connection();
$activityRepo = new FederationActivityRepository($connection);
$keyRepo = new NodeKeyRepository($connection);
$keyService = new NodeKeyService($keyRepo);
$http = new HttpClient();

$pending = $activityRepo->findPendingOutgoing($maxActivities);
$timestamp = date('Y-m-d H:i:s');
$delivered = 0;
$failed = 0;

foreach ($pending as $activity) {
    $targetDomain = $activity['target_node_domain'] ?? '';
    if ($targetDomain === '') {
        $activityRepo->markFailed((int) $activity['id'], 'No target domain');
        $failed++;
        continue;
    }

    $payload = json_decode($activity['payload'], true);
    if ($payload === null) {
        $activityRepo->markFailed((int) $activity['id'], 'Invalid payload JSON');
        $failed++;
        continue;
    }

    // Sign the payload
    try {
        $payloadToSign = json_encode($payload);
        $signature = $keyService->sign((int) $activity['node_id'], $payloadToSign);
        $payload['signature'] = $signature;
    } catch (\Throwable $e) {
        $activityRepo->markFailed((int) $activity['id'], 'Signing error: ' . $e->getMessage());
        $failed++;
        continue;
    }

    // Deliver to remote inbox
    $inboxUrl = "https://{$targetDomain}/api/v1/federation/inbox";
    try {
        $response = $http->request('POST', $inboxUrl, [
            'Content-Type' => 'application/json',
        ], json_encode($payload));

        if ($response['status'] >= 200 && $response['status'] < 300) {
            $activityRepo->markDelivered((int) $activity['id']);
            $delivered++;
        } else {
            $activityRepo->markFailed((int) $activity['id'], "HTTP {$response['status']}");
            $failed++;
        }
    } catch (\Throwable $e) {
        $activityRepo->markFailed((int) $activity['id'], 'Delivery error: ' . $e->getMessage());
        $failed++;
    }
}

fwrite(STDOUT, "[{$timestamp}] Federation delivery: {$delivered} delivered, {$failed} failed\n");