#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FPDP analytics diagnostics — runs each query behind the Analytics page
 * (GET /api/v1/me/analytics) one by one and prints which step fails and
 * why, for when the page returns a 500 and the server log is out of reach.
 * Prints only step names, row counts and error messages — no visitor data.
 *
 * Usage:  php scripts/diagnose-analytics.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AnalyticsEventRepository;
use App\Repositories\NodeRepository;
use App\Services\Analytics\AnalyticsService;

Config::load(dirname(__DIR__) . '/.env');

$step = static function (string $name, callable $run): mixed {
    try {
        $result = $run();
        fwrite(STDOUT, sprintf("OK    %-22s %s\n", $name, is_array($result) ? count($result) . ' row(s)' : var_export($result, true)));

        return $result;
    } catch (Throwable $e) {
        fwrite(STDOUT, sprintf("FAIL  %-22s %s: %s (%s:%d)\n", $name, get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()));

        return null;
    }
};

$db = $step('connect', static fn (): string => Database::connection()->getAttribute(PDO::ATTR_SERVER_VERSION));
if ($db === null) {
    exit(1);
}
$connection = Database::connection();
$step('collation_connection', static fn (): string => (string) $connection->query('SELECT @@collation_connection')->fetchColumn());
$step('sql_mode', static fn (): string => (string) $connection->query('SELECT @@sql_mode')->fetchColumn());
foreach ($connection->query("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('analytics_events', 'posts', 'products')")->fetchAll() as $r) {
    fwrite(STDOUT, "      {$r['TABLE_NAME']} = {$r['TABLE_COLLATION']}\n");
}
$step('referrer_host column', static fn (): bool => $connection->query("SHOW COLUMNS FROM analytics_events LIKE 'referrer_host'")->fetch() !== false);

$node = $step('first node', static fn () => (new NodeRepository($connection))->findFirst());
$nodeId = is_array($node) ? (int) $node['id'] : 1;

$events = new AnalyticsEventRepository($connection);
$since = gmdate('Y-m-d', strtotime('-29 days'));
$until = gmdate('Y-m-d');
$step('dailyTraffic', static fn (): array => $events->dailyTraffic($nodeId, $since, $until));
$step('countByType', static fn (): int => $events->countByType($nodeId, 'OUTBOUND_CLICK', $since, $until));
$step('topPages', static fn (): array => $events->topPages($nodeId, $since, $until, 10));
$step('topReferrers', static fn (): array => $events->topReferrers($nodeId, $since, $until, 10));
$step('topOutbound', static fn (): array => $events->topOutbound($nodeId, $since, $until, 10));
$step('getReport(30)', static fn (): array => (new AnalyticsService($events))->getReport($nodeId, 30));
$step('json_encode report', static fn (): int => strlen(json_encode((new AnalyticsService($events))->getReport($nodeId, 30), JSON_THROW_ON_ERROR)));
