<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

$dbPath = sys_get_temp_dir() . '/fpdp-database-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-database-test-' . uniqid() . '.env';

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
ENV);

Config::load($envPath);
Database::reset();

$connection = Database::connection();
$connection->exec('CREATE TABLE ping (id INTEGER PRIMARY KEY)');
$connection->exec('INSERT INTO ping (id) VALUES (1)');

$value = $connection->query('SELECT id FROM ping')->fetchColumn();
if ((int) $value !== 1) {
    fwrite(STDERR, "Database connection did not read back the expected value\n");
    exit(1);
}

if (Database::connection() !== $connection) {
    fwrite(STDERR, "Database::connection() did not reuse the cached connection\n");
    exit(1);
}

$connection = null;
Database::reset();
unlink($envPath);
unlink($dbPath);

fwrite(STDOUT, "Database test passed\n");
