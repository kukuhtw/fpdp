<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Repositories\PaymentGatewayConfigRepository;

function pgs_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-pgs-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-pgs-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", capabilities TEXT, active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, adapter_class TEXT, status TEXT DEFAULT "ACTIVE")',
    'CREATE TABLE payment_gateway_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway_id INTEGER NOT NULL, config_key TEXT NOT NULL, encrypted_value TEXT NOT NULL, environment TEXT DEFAULT "SANDBOX", is_active INTEGER DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (gateway_id, config_key, environment))',
] as $sql) {
    $db->exec($sql);
}
$db->exec("INSERT INTO payment_gateways (code, name) VALUES ('DUMMY', 'Dummy'), ('PAYWUZ', 'Paywuz'), ('MIDTRANS', 'Midtrans'), ('PAYPAL', 'PayPal')");

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$dispatch = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($router): array {
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $headers));

    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

// ---- Test 1: Crypto round-trips and rejects tampering ----
$secret = 'sk_live_super-secret-123';
$encrypted = Crypto::encrypt($secret);
pgs_assert(Crypto::decrypt($encrypted) === $secret, 'Crypto should decrypt back to the original plaintext');
pgs_assert($encrypted !== $secret, 'Encrypted value should not equal the plaintext');

$tamperFailed = false;
try {
    Crypto::decrypt(substr($encrypted, 0, -4) . 'XXXX');
} catch (RuntimeException) {
    $tamperFailed = true;
}
pgs_assert($tamperFailed, 'Decrypting a tampered ciphertext should throw');

$oldRotationKey = str_repeat('a', 64);
$newRotationKey = str_repeat('b', 64);
$rotationCiphertext = Crypto::encryptWithKey('rotating-secret', $oldRotationKey);
$rotatedCiphertext = Crypto::encryptWithKey(Crypto::decryptWithKey($rotationCiphertext, $oldRotationKey), $newRotationKey);
pgs_assert(Crypto::decryptWithKey($rotatedCiphertext, $newRotationKey) === 'rotating-secret', 'Explicit-key re-encryption should preserve plaintext');

// ---- Test 2: PaymentGatewayConfigRepository setConfig/getActiveConfig round trip and environment switching ----
$configs = new PaymentGatewayConfigRepository($db);
$paywuz = $configs->findGatewayByCode('PAYWUZ');
pgs_assert($paywuz !== null, 'PAYWUZ should exist from the seed data');
$paywuzId = (int) $paywuz['id'];

$configs->setConfig($paywuzId, 'SANDBOX', ['api_key' => 'sandbox-key-1']);
$active = $configs->getActiveConfig($paywuzId);
pgs_assert($active === ['api_key' => 'sandbox-key-1'], 'Active config should decrypt to the stored value: ' . json_encode($active));
pgs_assert($configs->getActiveEnvironment($paywuzId) === 'SANDBOX', 'SANDBOX should be the active environment');

$configs->setConfig($paywuzId, 'LIVE', ['api_key' => 'live-key-1']);
pgs_assert($configs->getActiveEnvironment($paywuzId) === 'LIVE', 'Switching to LIVE should make it the active environment');
pgs_assert($configs->getActiveConfig($paywuzId) === ['api_key' => 'live-key-1'], 'Active config should now be the LIVE value');

$midtrans = $configs->findGatewayByCode('MIDTRANS');
$configs->setConfig((int) $midtrans['id'], 'LIVE', ['server_key' => 'Mid-server-key']);
$resolved = $configs->resolveConfiguration('MIDTRANS');
pgs_assert($resolved['server_key'] === 'Mid-server-key', 'resolveConfiguration should surface the decrypted server_key');
pgs_assert($resolved['environment'] === 'PRODUCTION', 'A LIVE Midtrans config should resolve to environment=PRODUCTION for the gateway adapter');

$paypal = $configs->findGatewayByCode('PAYPAL');
$configs->setConfig((int) $paypal['id'], 'LIVE', ['client_id' => 'live-id', 'client_secret' => 'live-secret', 'webhook_id' => 'live-webhook']);
$paypalResolved = $configs->resolveConfiguration('PAYPAL');
pgs_assert($paypalResolved['environment'] === 'PRODUCTION', 'A LIVE PayPal config should resolve to environment=PRODUCTION');

// Register an owner for the HTTP-level tests below.
$register = $dispatch('POST', '/api/v1/auth/register', [
    'email' => 'owner@test.local',
    'password' => 'correct horse battery',
    'handle' => 'owner',
    'display_name' => 'Owner',
]);
pgs_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$ownerToken = $register['body']['data']['token']['access_token'];

$incompleteActivation = $dispatch('PUT', '/api/v1/me/payment-gateways/PAYWUZ/activate', null, $ownerToken);
pgs_assert($incompleteActivation['status'] === 422, 'Activating an incompletely configured gateway should 422');

// ---- Test 3: listing gateways requires auth and never exposes decrypted secrets ----
$unauthList = $dispatch('GET', '/api/v1/me/payment-gateways');
pgs_assert($unauthList['status'] === 401, 'Listing gateways should require auth');

$list = $dispatch('GET', '/api/v1/me/payment-gateways', null, $ownerToken);
pgs_assert($list['status'] === 200, 'Listing gateways should succeed for an authenticated owner: ' . json_encode($list));
$paywuzEntry = current(array_filter($list['body']['data']['gateways'], fn ($g) => $g['code'] === 'PAYWUZ'));
pgs_assert($paywuzEntry !== false, 'Gateway list should include PAYWUZ');
$liveEnv = current(array_filter($paywuzEntry['environments'], fn ($e) => $e['environment'] === 'LIVE'));
pgs_assert($liveEnv !== false && $liveEnv['is_active'] === true, 'PAYWUZ LIVE should be reported as the active environment');
pgs_assert(in_array('api_key', $liveEnv['configured_keys'], true), 'PAYWUZ LIVE should report api_key as configured');
pgs_assert(str_contains(json_encode($list['body']), 'live-key-1') === false, 'The raw secret value must never appear in the API response');

// ---- Test 4: updating a gateway via the API encrypts and stores it, and rejects invalid input ----
$unauthUpdate = $dispatch('PATCH', '/api/v1/me/payment-gateways/paywuz', ['environment' => 'SANDBOX', 'config' => ['api_key' => 'x']]);
pgs_assert($unauthUpdate['status'] === 401, 'Updating gateway config should require auth');

$update = $dispatch('PATCH', '/api/v1/me/payment-gateways/paywuz', ['environment' => 'sandbox', 'config' => ['api_key' => 'sandbox-key-2', 'api_url' => 'https://api.paywuz.id/v1']], $ownerToken);
pgs_assert($update['status'] === 200, 'Updating PAYWUZ sandbox config should succeed: ' . json_encode($update));

$afterUpdate = $configs->getActiveConfig($paywuzId);
pgs_assert($afterUpdate === ['api_key' => 'sandbox-key-2', 'api_url' => 'https://api.paywuz.id/v1'], 'The complete SANDBOX config should now be active and decrypt correctly');

$activation = $dispatch('PUT', '/api/v1/me/payment-gateways/PAYWUZ/activate', null, $ownerToken);
pgs_assert($activation['status'] === 200, 'A completely configured gateway should be activatable');

$partial = $dispatch('PATCH', '/api/v1/me/payment-gateways/paywuz', ['environment' => 'SANDBOX', 'config' => ['api_key' => 'partial']], $ownerToken);
pgs_assert($partial['status'] === 422, 'A partial gateway configuration should 422');

$unknownGateway = $dispatch('PATCH', '/api/v1/me/payment-gateways/stripe', ['environment' => 'SANDBOX', 'config' => ['api_key' => 'x']], $ownerToken);
pgs_assert($unknownGateway['status'] === 422, 'An unsupported gateway code should 422');

$unknownKey = $dispatch('PATCH', '/api/v1/me/payment-gateways/paywuz', ['environment' => 'SANDBOX', 'config' => ['not_a_real_key' => 'x']], $ownerToken);
pgs_assert($unknownKey['status'] === 422, 'A config key the gateway does not read should 422: ' . json_encode($unknownKey));

$badEnv = $dispatch('PATCH', '/api/v1/me/payment-gateways/paywuz', ['environment' => 'STAGING', 'config' => ['api_key' => 'x']], $ownerToken);
pgs_assert($badEnv['status'] === 422, 'An invalid environment should 422');

$emptyConfig = $dispatch('PATCH', '/api/v1/me/payment-gateways/paywuz', ['environment' => 'SANDBOX', 'config' => []], $ownerToken);
pgs_assert($emptyConfig['status'] === 422, 'An empty config body should 422');

// Cleanup
Database::reset();
unset($configs, $db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Payment gateway settings test passed\n");
