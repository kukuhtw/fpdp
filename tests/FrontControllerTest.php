<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

$dbPath = sys_get_temp_dir() . '/fpdp-front-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-front-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();
foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY, public_id TEXT, domain TEXT, name TEXT, default_locale TEXT, timezone TEXT, status TEXT, created_at TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, public_id TEXT, node_id INTEGER, email TEXT, password_hash TEXT, role TEXT, status TEXT, created_at TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY, public_id TEXT, user_id INTEGER, handle TEXT, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT, links TEXT, created_at TIMESTAMP, updated_at TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$home = $router->dispatch(new Request('GET', '/'));
if ($home->status !== 200 || !str_contains($home->body, 'Personal Digital Home')) {
    fwrite(STDERR, "Home route failed\n");
    exit(1);
}

$health = $router->dispatch(new Request('GET', '/api/v1/health'));
$decoded = json_decode($health->body, true);
if ($health->status !== 200
    || ($decoded['data']['status'] ?? null) !== 'OK'
    || !isset($decoded['meta']['request_id'])
) {
    fwrite(STDERR, "Health route did not return the documented envelope\n");
    exit(1);
}

$missing = $router->dispatch(new Request('GET', '/does-not-exist'));
if ($missing->status !== 404) {
    fwrite(STDERR, "Unknown route did not return 404\n");
    exit(1);
}

Database::reset();
unset($db);
unlink($envPath);
unlink($dbPath);

fwrite(STDOUT, "Front controller route test passed\n");
