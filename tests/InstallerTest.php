<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Installer\EnvWriter;
use App\Core\Installer\InstallLock;
use App\Core\Installer\RequirementsChecker;

function assert_that(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

// --- RequirementsChecker ---

$fixturesRoot = sys_get_temp_dir() . '/fpdp-installer-fixture-' . uniqid();
mkdir($fixturesRoot);

$checks = RequirementsChecker::check($fixturesRoot);
assert_that($checks !== [], 'RequirementsChecker returned no checks');

$labels = array_column($checks, 'label');
assert_that(
    array_filter($labels, static fn (string $label): bool => str_starts_with($label, 'PHP version')) !== [],
    'RequirementsChecker did not report a PHP version check',
);
assert_that(
    array_filter($labels, static fn (string $label): bool => $label === 'Extension: pdo_mysql') !== [],
    'RequirementsChecker did not check for the pdo_mysql extension',
);
assert_that(
    RequirementsChecker::allPassed($checks) === (array_filter($checks, static fn (array $c): bool => !$c['passed']) === []),
    'allPassed() disagreed with the individual check results',
);
assert_that(
    RequirementsChecker::allPassed([['label' => 'x', 'passed' => false, 'detail' => '']]) === false,
    'allPassed() returned true despite a failing check',
);

// --- EnvWriter: render + round-trip through Config::load ---

$envPath = $fixturesRoot . '/.env';
EnvWriter::write($envPath, [
    'APP_NAME' => 'Test Node',
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $fixturesRoot . '/db.sqlite',
    'NODE_DOMAIN' => 'installer-test.local',
    'NODE_NAME' => 'Installer Test Node',
]);

assert_that(is_file($envPath), 'EnvWriter::write() did not create the .env file');

$rendered = file_get_contents($envPath);
assert_that(str_contains($rendered, 'APP_NAME=Test Node'), '.env is missing the APP_NAME value that was passed in');
assert_that(str_contains($rendered, 'DB_CONNECTION=sqlite'), '.env did not keep the requested DB_CONNECTION');
assert_that((bool) preg_match('/^APP_KEY=[0-9a-f]{64}$/m', $rendered), 'EnvWriter did not generate a 64-char hex APP_KEY');

Config::load($envPath);
assert_that(Config::get('APP_NAME') === 'Test Node', 'Config::load() did not read back the APP_NAME EnvWriter wrote');
assert_that(Config::get('NODE_DOMAIN') === 'installer-test.local', 'Config::load() did not read back NODE_DOMAIN correctly');
assert_that(Config::get('DB_DATABASE') === $fixturesRoot . '/db.sqlite', 'Config::load() did not read back DB_DATABASE correctly');

// Re-running write() with the same APP_KEY value must not rotate it.
$existingKey = Config::get('APP_KEY');
EnvWriter::write($envPath, [
    'APP_NAME' => 'Test Node',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $fixturesRoot . '/db.sqlite',
    'NODE_DOMAIN' => 'installer-test.local',
    'APP_KEY' => $existingKey,
]);
Config::load($envPath);
assert_that(Config::get('APP_KEY') === $existingKey, 'EnvWriter rotated an APP_KEY that was explicitly supplied');

// --- EnvWriter::testConnection() failure path (no live MySQL server needed) ---

$connectionError = EnvWriter::testConnection([
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '1',
    'DB_DATABASE' => 'does_not_matter',
    'DB_USERNAME' => 'nobody',
    'DB_PASSWORD' => '',
    'DB_CHARSET' => 'utf8mb4',
]);
assert_that($connectionError !== null, 'testConnection() did not report an error for an unreachable database');

// --- InstallLock ---

assert_that(InstallLock::isInstalled($fixturesRoot) === false, 'InstallLock reported installed before lock() was ever called');
InstallLock::lock($fixturesRoot);
assert_that(InstallLock::isInstalled($fixturesRoot) === true, 'InstallLock did not report installed after lock()');
assert_that(is_file($fixturesRoot . '/storage/installed.lock'), 'InstallLock did not write storage/installed.lock');

// --- cleanup ---

array_map('unlink', glob($fixturesRoot . '/storage/*') ?: []);
@rmdir($fixturesRoot . '/storage');
@unlink($envPath);
@unlink($fixturesRoot . '/db.sqlite');
@rmdir($fixturesRoot);

fwrite(STDOUT, "Installer test passed\n");
