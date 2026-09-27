<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Services\Auth\Totp;

function tfa_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-2fa-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-2fa-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nRATE_LIMIT_LOGIN_MAX=50\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", totp_secret TEXT, totp_pending_secret TEXT, totp_enabled_at TIMESTAMP, totp_last_step INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, user_agent TEXT, ip_hint TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, last_used_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, actor_user_id INTEGER, action TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE user_recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL, used_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE mfa_challenges (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, attempts INTEGER NOT NULL DEFAULT 0, expires_at TIMESTAMP NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$call = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($router): array {
    $headers = ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0 Safari/537.36'];
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $headers, '203.0.113.45'));

    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};
$password = 'correct horse battery';
$credentials = ['email' => 'owner@test.local', 'password' => $password];
$auditActions = static fn (): array => $db->query('SELECT action FROM audit_events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
// Each accepted code is spent (replay protection); let the tests move on as
// if the next 30-second window had come, without sleeping.
$nextWindow = static fn () => $db->exec('UPDATE users SET totp_last_step = totp_last_step - 5');

// ---- 1. Off by default: login gives a session straight away ----
$register = $call('POST', '/api/v1/auth/register', $credentials + ['handle' => 'owner', 'display_name' => 'Owner']);
tfa_assert($register['status'] === 201, 'registration failed: ' . json_encode($register));
$token = $register['body']['data']['token']['access_token'];
$other = $call('POST', '/api/v1/auth/login', $credentials)['body']['data']['token']['access_token'];
$status = $call('GET', '/api/v1/me/2fa', null, $token);
tfa_assert($status['status'] === 200 && $status['body']['data'] === ['enabled' => false, 'enabled_at' => null, 'recovery_codes_remaining' => 0], 'off by default: ' . json_encode($status));
tfa_assert($call('GET', '/api/v1/me/2fa')['status'] === 401, 'the 2FA status needs a login');

// ---- 2. Setup: a secret to scan, stored encrypted, not yet active ----
$setup = $call('POST', '/api/v1/me/2fa/setup', null, $token);
tfa_assert($setup['status'] === 200, 'setup failed: ' . json_encode($setup));
$secret = $setup['body']['data']['secret'];
tfa_assert(preg_match('/^[A-Z2-7]{32}$/', $secret) === 1, 'secret is base32');
tfa_assert(str_starts_with($setup['body']['data']['otpauth_uri'], 'otpauth://totp/FPDP%20test.local:owner%40test.local?secret=' . $secret), 'otpauth URI: ' . $setup['body']['data']['otpauth_uri']);
$row = $db->query('SELECT * FROM users')->fetch();
tfa_assert($row['totp_pending_secret'] !== null && !str_contains((string) $row['totp_pending_secret'], $secret) && Crypto::decrypt((string) $row['totp_pending_secret']) === $secret, 'the pending secret is stored encrypted');
tfa_assert($row['totp_enabled_at'] === null && $row['totp_secret'] === null, 'not active until confirmed');
tfa_assert(isset($call('POST', '/api/v1/auth/login', $credentials)['body']['data']['token']), 'an unconfirmed setup does not change login');

// ---- 3. Confirm: wrong code refused; right code turns it on ----
$wrong = $call('POST', '/api/v1/me/2fa/confirm', ['code' => Totp::code($secret, Totp::step() - 10)], $token);
tfa_assert($wrong['status'] === 422, 'a wrong code must not turn 2FA on: ' . json_encode($wrong));
$confirm = $call('POST', '/api/v1/me/2fa/confirm', ['code' => Totp::code($secret, Totp::step())], $token);
tfa_assert($confirm['status'] === 200, 'confirm failed: ' . json_encode($confirm));
$codes = $confirm['body']['data']['recovery_codes'];
tfa_assert(count($codes) === 10 && count(array_unique($codes)) === 10, 'ten distinct recovery codes');
foreach ($codes as $code) {
    tfa_assert(preg_match('/^[a-hjkmnp-z2-9]{5}-[a-hjkmnp-z2-9]{5}$/', $code) === 1, "recovery code format: {$code}");
}
tfa_assert($confirm['body']['data']['other_sessions_revoked'] >= 1, 'turning 2FA on logs other devices out');
tfa_assert($call('GET', '/api/v1/me', null, $other)['status'] === 401, 'the other device is logged out');
tfa_assert($call('GET', '/api/v1/me', null, $token)['status'] === 200, 'this device stays logged in');
$row = $db->query('SELECT * FROM users')->fetch();
tfa_assert($row['totp_pending_secret'] === null && Crypto::decrypt((string) $row['totp_secret']) === $secret && $row['totp_enabled_at'] !== null, 'the secret moves to totp_secret, encrypted');
$stored = $db->query('SELECT code_hash FROM user_recovery_codes')->fetchAll(PDO::FETCH_COLUMN);
tfa_assert(count($stored) === 10 && !in_array(str_replace('-', '', $codes[0]), $stored, true) && password_verify(str_replace('-', '', $codes[0]), $stored[0]), 'recovery codes are stored only as password hashes');
tfa_assert($call('POST', '/api/v1/me/2fa/setup', null, $token)['status'] === 409, 'setup again while on is a conflict');
$status = $call('GET', '/api/v1/me/2fa', null, $token)['body']['data'];
tfa_assert($status['enabled'] === true && $status['recovery_codes_remaining'] === 10, 'status shows on with 10 codes: ' . json_encode($status));

// ---- 4. Login now asks for the code; the password alone gives no session ----
$login = $call('POST', '/api/v1/auth/login', $credentials);
tfa_assert($login['status'] === 200 && $login['body']['data']['mfa_required'] === true && !isset($login['body']['data']['token']), 'a correct password only gives a challenge: ' . json_encode($login));
$challenge = $login['body']['data']['mfa_token'];
tfa_assert((int) $db->query('SELECT COUNT(*) FROM mfa_challenges')->fetchColumn() === 1 && $db->query('SELECT token_hash FROM mfa_challenges')->fetchColumn() === hash('sha256', $challenge), 'only the challenge hash is stored');
tfa_assert($call('GET', '/api/v1/me', null, $challenge)['status'] === 401, 'the challenge is not a session token');
$bad = $call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => '000000']);
tfa_assert($bad['status'] === 422, 'a wrong code is refused: ' . json_encode($bad));
$replay = $call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => Totp::code($secret, (int) $row['totp_last_step'])]);
tfa_assert($replay['status'] === 422, 'the code already used to confirm cannot be used again');
$nextWindow();
$verify = $call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => Totp::code($secret, Totp::step())]);
tfa_assert($verify['status'] === 200 && isset($verify['body']['data']['token']['access_token'], $verify['body']['data']['user']['email']), 'a right code gives the normal login response: ' . json_encode($verify));
tfa_assert($call('GET', '/api/v1/me', null, $verify['body']['data']['token']['access_token'])['status'] === 200, 'the new session works');
tfa_assert($call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => Totp::code($secret, Totp::step() + 1)])['status'] === 401, 'a challenge works only once');
tfa_assert($call('POST', '/api/v1/auth/login/verify', ['mfa_token' => 'forged', 'code' => '123456'])['status'] === 401, 'an unknown challenge is refused');

// ---- 5. Five wrong codes end the challenge ----
$challenge = $call('POST', '/api/v1/auth/login', $credentials)['body']['data']['mfa_token'];
for ($i = 1; $i <= 4; $i++) {
    tfa_assert($call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => '000000'])['status'] === 422, "wrong code {$i} is a 422");
}
tfa_assert($call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => '000000'])['status'] === 401, 'the fifth wrong code ends the challenge');
$nextWindow();
tfa_assert($call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => Totp::code($secret, Totp::step())])['status'] === 401, 'even a right code no longer works on that challenge');

// ---- 6. An expired challenge is refused ----
$challenge = $call('POST', '/api/v1/auth/login', $credentials)['body']['data']['mfa_token'];
$db->exec("UPDATE mfa_challenges SET expires_at = '2000-01-01 00:00:00'");
$nextWindow();
tfa_assert($call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => Totp::code($secret, Totp::step())])['status'] === 401, 'an expired challenge is refused');

// ---- 7. A recovery code works once, in any format ----
$challenge = $call('POST', '/api/v1/auth/login', $credentials)['body']['data']['mfa_token'];
$recovery = $call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => ' ' . strtoupper($codes[0]) . ' ']);
tfa_assert($recovery['status'] === 200 && isset($recovery['body']['data']['token']), 'a recovery code signs in: ' . json_encode($recovery));
$challenge = $call('POST', '/api/v1/auth/login', $credentials)['body']['data']['mfa_token'];
tfa_assert($call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => $codes[0]])['status'] === 422, 'a used recovery code is refused');
tfa_assert($call('GET', '/api/v1/me/2fa', null, $token)['body']['data']['recovery_codes_remaining'] === 9, 'nine recovery codes left');

// ---- 8. New recovery codes: needs an app code (not a recovery code); old ones stop working ----
tfa_assert($call('POST', '/api/v1/me/2fa/recovery-codes', ['code' => $codes[1]], $token)['status'] === 422, 'a recovery code cannot make new recovery codes');
$nextWindow();
$regenerated = $call('POST', '/api/v1/me/2fa/recovery-codes', ['code' => Totp::code($secret, Totp::step())], $token);
tfa_assert($regenerated['status'] === 200 && count($regenerated['body']['data']['recovery_codes']) === 10, 'regenerate gives ten new codes: ' . json_encode($regenerated));
$newCodes = $regenerated['body']['data']['recovery_codes'];
$challenge = $call('POST', '/api/v1/auth/login', $credentials)['body']['data']['mfa_token'];
tfa_assert($call('POST', '/api/v1/auth/login/verify', ['mfa_token' => $challenge, 'code' => $codes[1]])['status'] === 422, 'an old recovery code no longer works');

// ---- 9. Disable: needs the password and a code ----
$nextWindow();
tfa_assert($call('POST', '/api/v1/me/2fa/disable', ['password' => 'wrong password!', 'code' => Totp::code($secret, Totp::step())], $token)['status'] === 422, 'disable needs the right password');
tfa_assert($call('POST', '/api/v1/me/2fa/disable', ['password' => $password, 'code' => '000000'], $token)['status'] === 422, 'disable needs a right code');
$disable = $call('POST', '/api/v1/me/2fa/disable', ['password' => $password, 'code' => $newCodes[0]], $token);
tfa_assert($disable['status'] === 200 && $disable['body']['data']['enabled'] === false, 'disable with password + recovery code: ' . json_encode($disable));
$row = $db->query('SELECT * FROM users')->fetch();
tfa_assert($row['totp_secret'] === null && $row['totp_enabled_at'] === null && $row['totp_last_step'] === null, 'the secret is removed');
tfa_assert((int) $db->query('SELECT COUNT(*) FROM user_recovery_codes')->fetchColumn() === 0 && (int) $db->query('SELECT COUNT(*) FROM mfa_challenges')->fetchColumn() === 0, 'recovery codes and challenges are removed');
tfa_assert(isset($call('POST', '/api/v1/auth/login', $credentials)['body']['data']['token']), 'login is password-only again');
tfa_assert($call('POST', '/api/v1/me/2fa/disable', ['password' => $password, 'code' => '123456'], $token)['status'] === 409, 'disabling twice is a conflict');

// ---- 10. Audit: every step recorded, no secret or code in it ----
$actions = $auditActions();
foreach (['user.2fa_enabled', 'user.2fa_failed', 'user.2fa_recovery_code_used', 'user.2fa_recovery_codes_regenerated', 'user.2fa_disable_failed', 'user.2fa_disabled'] as $action) {
    tfa_assert(in_array($action, $actions, true), "audit should record {$action}: " . json_encode($actions));
}
$secondFactorLogins = $db->query("SELECT COUNT(*) FROM audit_events WHERE action = 'user.login' AND metadata LIKE '%\"second_factor\":true%'")->fetchColumn();
tfa_assert((int) $secondFactorLogins === 2, 'logins through 2FA are marked second_factor: ' . $secondFactorLogins);
$metadata = implode("\n", $db->query('SELECT COALESCE(metadata, "") FROM audit_events')->fetchAll(PDO::FETCH_COLUMN));
tfa_assert(!str_contains($metadata, $secret) && !str_contains($metadata, $codes[0]) && !str_contains($metadata, $newCodes[0]), 'no secret or recovery code in the audit trail');

@unlink($dbPath);
@unlink($envPath);
fwrite(STDOUT, "TwoFactorTest passed\n");
