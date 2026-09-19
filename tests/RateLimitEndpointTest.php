<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

$dbPath = sys_get_temp_dir() . '/fpdp-ratelimit-endpoint-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-ratelimit-endpoint-test-' . uniqid() . '.env';

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
NODE_DOMAIN=test.local
AUTH_TOKEN_TTL=3600
RATE_LIMIT_LOGIN_MAX=2
RATE_LIMIT_LOGIN_WINDOW=60
ENV);

Config::load($envPath);
Database::reset();

Database::connection()->exec('
    CREATE TABLE nodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        domain TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        default_locale TEXT NOT NULL DEFAULT "id",
        timezone TEXT NOT NULL DEFAULT "Asia/Jakarta",
        status TEXT NOT NULL DEFAULT "ACTIVE",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
Database::connection()->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT "OWNER",
        status TEXT NOT NULL DEFAULT "ACTIVE",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
Database::connection()->exec('
    CREATE TABLE profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        user_id INTEGER NOT NULL UNIQUE,
        handle TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        bio TEXT,
        avatar_url TEXT,
        visibility TEXT NOT NULL DEFAULT "PUBLIC",
        links TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
Database::connection()->exec('
    CREATE TABLE auth_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        token_type TEXT NOT NULL DEFAULT "ACCESS",
        scopes TEXT,
        expires_at TIMESTAMP NOT NULL,
        revoked_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
Database::connection()->exec('
    CREATE TABLE rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rate_key TEXT NOT NULL UNIQUE,
        attempts INTEGER NOT NULL DEFAULT 1,
        window_started_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

function login_attempt(Router $router): int
{
    $body = json_encode(['email' => 'nobody@example.com', 'password' => 'wrong password entirely']);

    return $router->dispatch(new Request('POST', '/api/v1/auth/login', [], $body))->status;
}

// RATE_LIMIT_LOGIN_MAX=2: the first two attempts are evaluated normally (and fail
// authentication with 401 since the account does not exist); the third must be
// rejected by the rate limiter before authentication logic even runs.
$first = login_attempt($router);
$second = login_attempt($router);
$third = login_attempt($router);

if ($first !== 401 || $second !== 401) {
    fwrite(STDERR, "Attempts within the limit did not return 401 as expected: {$first}, {$second}\n");
    exit(1);
}

if ($third !== 429) {
    fwrite(STDERR, "Attempt beyond the limit did not return 429, got {$third}\n");
    exit(1);
}

Database::reset();
unlink($envPath);
unlink($dbPath);

fwrite(STDOUT, "Rate limit endpoint test passed\n");
