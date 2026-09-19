<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;

Config::load(__DIR__ . '/does-not-exist.env');
if (Config::get('APP_NAME') !== 'FPDP' || Config::get('APP_ENV') !== 'production') {
    fwrite(STDERR, "Default config values failed\n");
    exit(1);
}

$envPath = sys_get_temp_dir() . '/fpdp-config-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_NAME=TestApp\nAPP_ENV=local\nAPP_DEBUG=true\n");
Config::load($envPath);

if (Config::get('APP_NAME') !== 'TestApp' || Config::getBool('APP_DEBUG') !== true) {
    unlink($envPath);
    fwrite(STDERR, "Env file override failed\n");
    exit(1);
}
unlink($envPath);

$badEnvPath = sys_get_temp_dir() . '/fpdp-config-test-bad-' . uniqid() . '.env';
file_put_contents($badEnvPath, "APP_NAME=FPDP\nAPP_ENV=not-a-real-env\n");

$rejected = false;
try {
    Config::load($badEnvPath);
} catch (\RuntimeException $e) {
    $rejected = true;
}
unlink($badEnvPath);

if (!$rejected) {
    fwrite(STDERR, "Invalid APP_ENV was not rejected\n");
    exit(1);
}

fwrite(STDOUT, "Config test passed\n");
