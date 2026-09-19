#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FPDP External Content Sync — CLI entry point for cron jobs.
 *
 * Usage:
 *   php sync-external.php                        # sync all due sources (default: 10)
 *   php sync-external.php --max=5                # sync max 5 sources
 *   php sync-external.php --source-id=3          # sync a specific source by ID
 *
 * Add to crontab to run every 15 minutes (remove the space between stars):
 *   star/15 * * * * /usr/bin/php /var/www/fpdp/sync-external.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\ExternalFeedSourceRepository;
use App\Repositories\ExternalPostRepository;
use App\Services\External\SyncWorker;

// Parse CLI arguments
$options = getopt('', ['max::', 'source-id::']);
$maxSources = isset($options['max']) ? (int) $options['max'] : 10;
$specificSourceId = isset($options['source-id']) ? (int) $options['source-id'] : null;

Config::load(__DIR__ . '/.env');

$connection = Database::connection();
$feedSources = new ExternalFeedSourceRepository($connection);
$externalPosts = new ExternalPostRepository($connection);
$worker = new SyncWorker($feedSources, $externalPosts);

if ($specificSourceId !== null) {
    $source = $feedSources->findById($specificSourceId);
    if ($source === null) {
        fwrite(STDERR, "Source ID {$specificSourceId} not found.\n");
        exit(1);
    }
    $result = $worker->syncSource($source);
} else {
    $result = $worker->run($maxSources);
}

$timestamp = date('Y-m-d H:i:s');

if ($result['success'] ?? true) {
    if ($specificSourceId !== null) {
        $inserted = $result['inserted'] ?? 0;
        fwrite(STDOUT, "[{$timestamp}] Synced source {$specificSourceId}: {$inserted} inserted, error: " . ($result['error'] ?? 'none') . "\n");
    } else {
        fwrite(STDOUT, "[{$timestamp}] Sync complete: {$result['processed']} processed, {$result['inserted']} inserted, {$result['errors']} errors\n");
    }
} else {
    fwrite(STDERR, "[{$timestamp}] Sync failed for source {$specificSourceId}: {$result['error']}\n");
    exit(1);
}