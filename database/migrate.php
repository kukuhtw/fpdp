<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\MigrationRunner;

Config::load(__DIR__ . '/../.env');

$runner = new MigrationRunner(Database::connection(), __DIR__ . '/migrations');
$applied = $runner->run();

if ($applied === []) {
    fwrite(STDOUT, "No pending migrations.\n");
    exit(0);
}

foreach ($applied as $migration) {
    fwrite(STDOUT, "Applied: {$migration}\n");
}
