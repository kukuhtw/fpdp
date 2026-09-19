<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\MigrationRunner;

$fixturesPath = sys_get_temp_dir() . '/fpdp-migrations-fixture-' . uniqid();
mkdir($fixturesPath);
file_put_contents(
    $fixturesPath . '/0001_create_widgets.sql',
    'CREATE TABLE IF NOT EXISTS widgets (id INTEGER PRIMARY KEY, name TEXT)',
);
file_put_contents(
    $fixturesPath . '/0002_create_gadgets.sql',
    'CREATE TABLE IF NOT EXISTS gadgets (id INTEGER PRIMARY KEY, label TEXT)',
);

$connection = new PDO('sqlite::memory:');
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$runner = new MigrationRunner($connection, $fixturesPath);

$firstRun = $runner->run();
if ($firstRun !== ['0001_create_widgets.sql', '0002_create_gadgets.sql']) {
    fwrite(STDERR, "First run did not apply migrations in filename order\n");
    exit(1);
}

$secondRun = $runner->run();
if ($secondRun !== []) {
    fwrite(STDERR, "Second run re-applied already-applied migrations\n");
    exit(1);
}

$tables = $connection
    ->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('widgets', $tables, true) || !in_array('gadgets', $tables, true) || !in_array('migrations', $tables, true)) {
    fwrite(STDERR, "Expected tables were not created\n");
    exit(1);
}

unlink($fixturesPath . '/0001_create_widgets.sql');
unlink($fixturesPath . '/0002_create_gadgets.sql');
rmdir($fixturesPath);

fwrite(STDOUT, "Migration runner test passed\n");
