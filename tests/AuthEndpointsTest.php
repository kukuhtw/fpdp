<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

function assert_that(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-auth-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-auth-test-' . uniqid() . '.env';

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
NODE_DOMAIN=test.local
AUTH_TOKEN_TTL=3600
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

function dispatch(Router $router, string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $headers));
    $decoded = $response->body === '' ? null : json_decode($response->body, true);

    return ['status' => $response->status, 'body' => $decoded];
}

// 1. Register a new owner.
$register = dispatch($router, 'POST', '/api/v1/auth/register', [
    'email' => 'Alice@Example.com',
    'password' => 'correct horse battery',
    'handle' => 'alice',
    'display_name' => 'Alice Owner',
    'locale' => 'en',
]);
assert_that($register['status'] === 201, 'Register did not return 201: ' . json_encode($register));
assert_that($register['body']['data']['user']['role'] === 'OWNER', 'Registered user is not OWNER');
assert_that($register['body']['data']['node']['domain'] === 'alice.test.local', 'Node domain not derived from handle');
assert_that($register['body']['data']['token']['token_type'] === 'Bearer', 'Token type is not Bearer');
$registerToken = $register['body']['data']['token']['access_token'];
assert_that(is_string($registerToken) && $registerToken !== '', 'Register did not return an access token');

// 2. Duplicate email is rejected with 409.
$duplicate = dispatch($router, 'POST', '/api/v1/auth/register', [
    'email' => 'alice@example.com',
    'password' => 'another long password',
    'handle' => 'alice2',
    'display_name' => 'Alice Again',
]);
assert_that($duplicate['status'] === 409, 'Duplicate email registration was not rejected with 409');

// 3. Weak password fails validation with 422 and a field-level detail.
$weak = dispatch($router, 'POST', '/api/v1/auth/register', [
    'email' => 'bob@example.com',
    'password' => 'short',
    'handle' => 'bob',
    'display_name' => 'Bob',
]);
assert_that($weak['status'] === 422, 'Weak password was not rejected with 422');
assert_that($weak['body']['error']['details'][0]['field'] === 'password', 'Validation details did not name the password field');

// 4. Login with correct credentials issues a fresh token.
$login = dispatch($router, 'POST', '/api/v1/auth/login', [
    'email' => 'alice@example.com',
    'password' => 'correct horse battery',
]);
assert_that($login['status'] === 200, 'Login with correct credentials failed');
$loginToken = $login['body']['data']['token']['access_token'];
assert_that($loginToken !== $registerToken, 'Login did not issue a new token');

// 5. Login with wrong password is rejected with 401.
$badLogin = dispatch($router, 'POST', '/api/v1/auth/login', [
    'email' => 'alice@example.com',
    'password' => 'totally wrong password',
]);
assert_that($badLogin['status'] === 401, 'Wrong password did not return 401');

// 6. /me requires a valid bearer token.
$meUnauthenticated = dispatch($router, 'GET', '/api/v1/me');
assert_that($meUnauthenticated['status'] === 401, '/me without a token did not return 401');

$me = dispatch($router, 'GET', '/api/v1/me', null, $loginToken);
assert_that($me['status'] === 200, '/me with a valid token failed');
assert_that($me['body']['data']['user']['email'] === 'alice@example.com', '/me returned the wrong user');
assert_that($me['body']['data']['profile']['handle'] === 'alice', '/me returned the wrong profile');

// 7. Public profile read works without authentication.
$publicProfile = dispatch($router, 'GET', '/api/v1/profiles/alice');
assert_that($publicProfile['status'] === 200, 'Public profile read failed');
assert_that($publicProfile['body']['data']['canonical_url'] === 'https://alice.test.local/@alice', 'Canonical URL was not built correctly');

$missingProfile = dispatch($router, 'GET', '/api/v1/profiles/does-not-exist');
assert_that($missingProfile['status'] === 404, 'Unknown handle did not return 404');

// 8. Owner can update their own profile; unknown fields are rejected.
$update = dispatch($router, 'PATCH', '/api/v1/me/profile', [
    'display_name' => 'Alice Updated',
    'bio' => 'Hello from FPDP',
], $loginToken);
assert_that($update['status'] === 200, 'Profile update failed');
assert_that($update['body']['data']['display_name'] === 'Alice Updated', 'Profile update did not persist');

$updateUnauthenticated = dispatch($router, 'PATCH', '/api/v1/me/profile', ['display_name' => 'Nope']);
assert_that($updateUnauthenticated['status'] === 401, 'Unauthenticated profile update did not return 401');

$updateInvalidField = dispatch($router, 'PATCH', '/api/v1/me/profile', ['not_a_field' => 'x'], $loginToken);
assert_that($updateInvalidField['status'] === 422, 'Unknown profile field was not rejected with 422');

// 9. Logout revokes the token used to authenticate the request.
$logout = dispatch($router, 'POST', '/api/v1/auth/logout', null, $loginToken);
assert_that($logout['status'] === 204, 'Logout did not return 204');

$meAfterLogout = dispatch($router, 'GET', '/api/v1/me', null, $loginToken);
assert_that($meAfterLogout['status'] === 401, 'Token still worked after logout');

Database::reset();
unlink($envPath);
unlink($dbPath);

fwrite(STDOUT, "Auth endpoints test passed\n");
