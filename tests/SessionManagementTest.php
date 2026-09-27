<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Services\Auth\AuthService;

function sess_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-session-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-session-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nRATE_LIMIT_LOGIN_MAX=50\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, user_agent TEXT, ip_hint TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, last_used_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, actor_user_id INTEGER, action TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$call = static function (string $method, string $path, ?array $body = null, ?string $token = null, array $headers = [], string $ip = '203.0.113.45') use ($router): array {
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $headers, $ip));

    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$password = 'correct horse battery';

// ---- 1. Each login is a session with its device and a truncated IP ----
$register = $call('POST', '/api/v1/auth/register', ['email' => 'owner@test.local', 'password' => $password, 'handle' => 'owner', 'display_name' => 'Owner'], null, ['user-agent' => $chrome]);
sess_assert($register['status'] === 201, 'registration failed: ' . json_encode($register));
$laptop = $register['body']['data']['token']['access_token'];
$phone = $call('POST', '/api/v1/auth/login', ['email' => 'owner@test.local', 'password' => $password], null, ['user-agent' => $iphone], '2001:db8:abcd:12::99')['body']['data']['token']['access_token'];
$third = $call('POST', '/api/v1/auth/login', ['email' => 'owner@test.local', 'password' => $password], null, ['user-agent' => 'curl/8.4'])['body']['data']['token']['access_token'];

$list = $call('GET', '/api/v1/me/sessions', null, $laptop);
sess_assert($list['status'] === 200 && count($list['body']['data']['sessions']) === 3, 'three sessions expected: ' . json_encode($list));
$sessions = $list['body']['data']['sessions'];
$byDevice = array_column($sessions, null, 'device');
sess_assert(isset($byDevice['Chrome on Windows'], $byDevice['Safari on iOS'], $byDevice['curl']), 'devices should be labelled: ' . json_encode(array_keys($byDevice)));
sess_assert($byDevice['Chrome on Windows']['current'] === true && $byDevice['Safari on iOS']['current'] === false, 'the requesting session should be marked current');
sess_assert($byDevice['Chrome on Windows']['ip_hint'] === '203.0.113.x', 'IPv4 is kept only to its /24: ' . $byDevice['Chrome on Windows']['ip_hint']);
sess_assert($byDevice['Safari on iOS']['ip_hint'] === '2001:db8:abcd::/48', 'IPv6 is kept only to its /48: ' . $byDevice['Safari on iOS']['ip_hint']);
$raw = json_encode($list['body']);
sess_assert(!str_contains($raw, hash('sha256', $laptop)) && !str_contains($raw, 'token_hash') && !str_contains($raw, '203.0.113.45'), 'the list must expose neither token hashes nor full IPs');
sess_assert((int) $db->query("SELECT COUNT(*) FROM auth_tokens WHERE ip_hint LIKE '%45%' OR ip_hint LIKE '%::99%'")->fetchColumn() === 0, 'full IPs must never be stored');

// ---- 2. Log one other device out; it stops working at once ----
$phoneId = $byDevice['Safari on iOS']['id'];
sess_assert($call('DELETE', "/api/v1/me/sessions/{$phoneId}", null, $laptop)['status'] === 204, 'revoking the phone session should succeed');
sess_assert($call('GET', '/api/v1/me', null, $phone)['status'] === 401, 'the revoked phone token must stop working');
sess_assert($call('DELETE', "/api/v1/me/sessions/{$phoneId}", null, $laptop)['status'] === 404, 'revoking it twice is a 404');
$current = $call('DELETE', '/api/v1/me/sessions/' . $byDevice['Chrome on Windows']['id'], null, $laptop);
sess_assert($current['status'] === 422, 'the current session cannot be revoked from the list (log out instead): ' . json_encode($current));
sess_assert($call('GET', '/api/v1/me', null, $laptop)['status'] === 200, 'the laptop is still logged in');

// ---- 3. Another user's session id is unreachable ----
$db->exec("INSERT INTO users (public_id, node_id, email, password_hash) VALUES ('u2', 1, 'other@test.local', 'x')");
$db->exec("INSERT INTO auth_tokens (public_id, user_id, token_hash, expires_at) VALUES ('other-session', 2, 'hash-other', '2999-01-01 00:00:00')");
sess_assert($call('DELETE', '/api/v1/me/sessions/other-session', null, $laptop)['status'] === 404, "another user's session must not be revocable");
sess_assert($db->query("SELECT revoked_at FROM auth_tokens WHERE public_id = 'other-session'")->fetchColumn() === null, "another user's session must stay untouched");

// ---- 4. Log out all other devices ----
$fourth = $call('POST', '/api/v1/auth/login', ['email' => 'owner@test.local', 'password' => $password])['body']['data']['token']['access_token'];
$others = $call('POST', '/api/v1/me/sessions/revoke-others', null, $laptop);
sess_assert($others['status'] === 200 && $others['body']['data']['revoked'] === 2, 'curl and the fourth session should be revoked (the phone already was): ' . json_encode($others));
sess_assert($call('GET', '/api/v1/me', null, $third)['status'] === 401 && $call('GET', '/api/v1/me', null, $fourth)['status'] === 401, 'other devices must be logged out');
sess_assert(count($call('GET', '/api/v1/me/sessions', null, $laptop)['body']['data']['sessions']) === 1, 'only the current session remains');

// ---- 5. Change password: needs the current one, follows the rules, logs other devices out ----
$fifth = $call('POST', '/api/v1/auth/login', ['email' => 'owner@test.local', 'password' => $password])['body']['data']['token']['access_token'];
sess_assert($call('POST', '/api/v1/me/password', ['current_password' => 'wrong wrong wrong', 'new_password' => 'a brand new passphrase'], $laptop)['status'] === 422, 'a wrong current password must be refused');
sess_assert($call('POST', '/api/v1/me/password', ['current_password' => $password, 'new_password' => 'short'], $laptop)['status'] === 422, 'a too-short new password must be refused');
sess_assert($call('POST', '/api/v1/me/password', ['current_password' => $password, 'new_password' => $password], $laptop)['status'] === 422, 'the same password must be refused');
sess_assert($call('GET', '/api/v1/me', null, $fifth)['status'] === 200, 'failed attempts must not log anyone out');

$changed = $call('POST', '/api/v1/me/password', ['current_password' => $password, 'new_password' => 'a brand new passphrase'], $laptop);
sess_assert($changed['status'] === 200 && $changed['body']['data']['other_sessions_revoked'] === 1, 'the password change should succeed and log the other session out: ' . json_encode($changed));
sess_assert($call('GET', '/api/v1/me', null, $fifth)['status'] === 401, 'the other device must be logged out after a password change');
sess_assert($call('GET', '/api/v1/me', null, $laptop)['status'] === 200, 'the device that changed it stays logged in');
sess_assert($call('POST', '/api/v1/auth/login', ['email' => 'owner@test.local', 'password' => $password])['status'] === 401, 'the old password must no longer work');
sess_assert($call('POST', '/api/v1/auth/login', ['email' => 'owner@test.local', 'password' => 'a brand new passphrase'])['status'] === 200, 'the new password works');
sess_assert($call('GET', '/api/v1/me/sessions', null, null)['status'] === 401, 'the session list requires login');

// ---- 6. All of it is audited, without secrets ----
$actions = $db->query("SELECT action FROM audit_events ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach (['session.revoked', 'session.revoked_others', 'user.password_change_failed', 'user.password_changed'] as $action) {
    sess_assert(in_array($action, $actions, true), "{$action} should be audited: " . json_encode($actions));
}
$auditText = implode(' ', $db->query('SELECT COALESCE(metadata, "") FROM audit_events')->fetchAll(PDO::FETCH_COLUMN));
sess_assert(!str_contains($auditText, 'brand new passphrase') && !str_contains($auditText, 'correct horse') && !str_contains($auditText, '203.0.113.45'), 'passwords and full IPs must never reach the audit trail');

// ---- 7. IP hints ----
sess_assert(AuthService::ipHint('198.51.100.7') === '198.51.100.x' && AuthService::ipHint('::1') === '0:0:0::/48' && AuthService::ipHint('nonsense') === null, 'ipHint should truncate or reject');

unset($router, $call, $db);
Database::reset();
gc_collect_cycles();
@unlink($envPath);
@unlink($dbPath);

fwrite(STDOUT, "Session management test passed\n");
