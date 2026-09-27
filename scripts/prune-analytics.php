#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FPDP analytics retention — deletes analytics events older than
 * ANALYTICS_RETENTION_DAYS (default 400: a bit over a year, enough for a
 * year-on-year look; minimum 30). Events hold no IP and no cross-day
 * identifier, but keeping them forever still has no purpose.
 *
 * Usage:  php scripts/prune-analytics.php
 * Cron:   daily (see deploy/ubuntu/fpdp.cron); Dokploy: the scheduler service.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AnalyticsEventRepository;
use App\Services\Analytics\AnalyticsService;

Config::load(dirname(__DIR__) . '/.env');
$keepDays = max(30, (int) Config::get('ANALYTICS_RETENTION_DAYS', '400'));

$deleted = (new AnalyticsService(new AnalyticsEventRepository(Database::connection())))->pruneOlderThan($keepDays);

fwrite(STDOUT, sprintf("[prune-analytics] %s deleted %d event(s) older than %d days\n", gmdate('Y-m-d H:i:s'), $deleted, $keepDays));
