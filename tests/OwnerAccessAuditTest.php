<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\LlmController;
use App\Controllers\PaymentController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Http\Request;
use App\Core\Router;
use App\Repositories\AuditEventRepository;
use App\Repositories\AuthTokenRepository;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Repositories\LlmConfigRepository;
use App\Repositories\NodeRepository;
use App\Repositories\PaymentGatewayConfigRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\Content\MediaUploadService;
use App\Services\Cv\CvAccessService;
use App\Services\Llm\LLMConfigService;
use App\Services\Payment\PaymentService;
use App\Services\Security\AuditService;

function oaa_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-owner-audit-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-owner-audit-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, domain TEXT NOT NULL UNIQUE, name TEXT NOT NULL, default_locale TEXT NOT NULL DEFAULT "id", timezone TEXT NOT NULL DEFAULT "Asia/Jakarta", status TEXT NOT NULL DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, node_id INTEGER NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "OWNER", status TEXT NOT NULL DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL UNIQUE, handle TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL, bio TEXT, avatar_url TEXT, visibility TEXT NOT NULL DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, token_type TEXT NOT NULL DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, actor_user_id INTEGER, action TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, adapter_class TEXT, description TEXT, status TEXT DEFAULT "ACTIVE", is_plugin INTEGER DEFAULT 0, config_keys_json TEXT, supports_refund INTEGER DEFAULT 1, supports_recurring INTEGER DEFAULT 0, supports_qris INTEGER DEFAULT 0, supports_va INTEGER DEFAULT 1, supports_credit_card INTEGER DEFAULT 0, supports_ewallet INTEGER DEFAULT 0)',
    'CREATE TABLE payment_gateway_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway_id INTEGER NOT NULL, config_key TEXT NOT NULL, encrypted_value TEXT NOT NULL, environment TEXT DEFAULT "SANDBOX", is_active INTEGER DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (gateway_id, config_key, environment))',
    'CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE, order_id TEXT NOT NULL, gateway_code TEXT NOT NULL, external_transaction_id TEXT, payment_method TEXT, currency TEXT NOT NULL DEFAULT "IDR", amount REAL NOT NULL DEFAULT 0, fee REAL NOT NULL DEFAULT 0, refunded_amount REAL NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT "PENDING", payment_url TEXT, metadata TEXT, expired_at TIMESTAMP, paid_at TIMESTAMP, refunded_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER NOT NULL, provider TEXT NOT NULL, external_id TEXT, event_type TEXT NOT NULL, status TEXT NOT NULL, payload TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (provider, external_id))',
    'CREATE TABLE cv_access_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, cv_document_id INTEGER NOT NULL, visitor_id INTEGER NOT NULL, payment_reference TEXT, granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (cv_document_id, visitor_id))',
    'CREATE TABLE llm_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER UNIQUE, provider_code TEXT, model TEXT, encrypted_api_key TEXT, supports_vision INTEGER DEFAULT 0, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}
$db->exec("INSERT INTO payment_gateways (code, name) VALUES ('DUMMY', 'Dummy'), ('PAYWUZ', 'Paywuz')");

$audit = new AuditService(new AuditEventRepository($db));
$nodes = new NodeRepository($db);
$auth = new AuthService($nodes, new UserRepository($db), new ProfileRepository($db), new AuthTokenRepository($db), $audit);

$auditRows = static fn (string $action): array => $db->query("SELECT * FROM audit_events WHERE action = '{$action}' ORDER BY id")->fetchAll();
$threw = static function (callable $fn, string $class): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
};

// ---- 1. Registration, login, and a failed login are audited ----
$registered = $auth->register(['email' => 'owner@example.com', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner']);
$ownerToken = $registered['token']['access_token'];
$ownerId = (int) $registered['user']['id'];
oaa_assert(count($auditRows('user.registered')) === 1, 'registration should be audited');

$auth->login(['email' => 'owner@example.com', 'password' => 'correct horse battery']);
oaa_assert(count($auditRows('user.login')) === 1, 'a login should be audited');
oaa_assert($threw(fn () => $auth->login(['email' => 'owner@example.com', 'password' => 'wrong']), UnauthorizedException::class), 'a wrong password should be rejected');
$failed = $auditRows('user.login_failed');
oaa_assert(count($failed) === 1 && (int) $failed[0]['actor_user_id'] === $ownerId, 'a failed login for a known account should be audited against that account');
oaa_assert(!str_contains((string) $failed[0]['metadata'], 'wrong'), 'the attempted password must never reach the audit trail');

// ---- 2. Only an ACTIVE OWNER gets in; others lose access even with an existing token ----
$db->exec("UPDATE users SET status = 'SUSPENDED' WHERE id = {$ownerId}");
oaa_assert($threw(fn () => $auth->authenticate($ownerToken), ForbiddenException::class), 'a suspended owner should be refused even with a valid token');
oaa_assert($threw(fn () => $auth->login(['email' => 'owner@example.com', 'password' => 'correct horse battery']), ForbiddenException::class), 'a suspended owner should not be able to log in');

$db->exec("UPDATE users SET status = 'ACTIVE', role = 'ADMIN' WHERE id = {$ownerId}");
oaa_assert($threw(fn () => $auth->authenticate($ownerToken), ForbiddenException::class), 'FPDP is owner-only: any other role should be refused');

$denied = $auditRows('access.denied');
oaa_assert(count($denied) === 3, 'every refusal should be audited, got ' . count($denied));
oaa_assert(str_contains((string) $denied[0]['metadata'], 'SUSPENDED') && str_contains((string) $denied[2]['metadata'], 'ADMIN'), 'the refusal should record why');

$db->exec("UPDATE users SET role = 'OWNER' WHERE id = {$ownerId}");
oaa_assert((int) $auth->authenticate($ownerToken)['user']['id'] === $ownerId, 'a restored ACTIVE OWNER should get back in');

// ---- 3. Sensitive payment actions are audited, without secrets or buyer data ----
$payments = new PaymentService(payments: new PaymentRepository($db), gatewayConfigs: new PaymentGatewayConfigRepository($db), nodes: $nodes);
$cvAccess = new CvAccessService(new CvDocumentRepository($db), new CvAccessGrantRepository($db), $payments, $nodes, sys_get_temp_dir());
$paymentController = new PaymentController($auth, $payments, $cvAccess, null, null, $audit);
$llmController = new LlmController(
    $auth,
    new LLMConfigService(new LlmConfigRepository($db), new MediaUploadService(sys_get_temp_dir())),
    $audit,
);

$router = new Router();
$router->patch('/api/v1/me/payment-gateways/{code}', fn (Request $r, array $p) => $paymentController->updateGateway($r, $p));
$router->put('/api/v1/me/payment-gateways/{code}/activate', fn (Request $r, array $p) => $paymentController->activateGateway($r, $p));
$router->post('/api/v1/me/payments/{uuid}/confirm', fn (Request $r, array $p) => $paymentController->confirmPayment($r, $p));
$router->post('/api/v1/me/payments/{uuid}/cancel', fn (Request $r, array $p) => $paymentController->cancelPayment($r, $p));
$router->post('/api/v1/me/payments/{uuid}/refund', fn (Request $r, array $p) => $paymentController->refundPayment($r, $p));
$router->patch('/api/v1/me/llm-config', fn (Request $r, array $p) => $llmController->updateSettings($r));

$call = static function (string $method, string $path, ?array $body = null) use ($router, $ownerToken): array {
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), ['authorization' => 'Bearer ' . $ownerToken]));

    return ['status' => $response->status, 'body' => json_decode($response->body, true)];
};

$secretKey = 'pk_sand_super-secret-value-123';
oaa_assert($call('PATCH', '/api/v1/me/payment-gateways/PAYWUZ', ['environment' => 'SANDBOX', 'config' => ['api_key' => $secretKey, 'api_url' => 'https://api.paywuz.id/v1']])['status'] === 200, 'configuring Paywuz should succeed');
$configured = $auditRows('payment_gateway.configured');
oaa_assert(count($configured) === 1, 'gateway configuration should be audited');
oaa_assert(str_contains((string) $configured[0]['metadata'], 'api_key') && !str_contains((string) $configured[0]['metadata'], $secretKey), 'the audit entry should name the configured keys but never their values');

oaa_assert($call('PUT', '/api/v1/me/payment-gateways/PAYWUZ/activate')['status'] === 200, 'activating Paywuz should succeed');
oaa_assert(str_contains((string) ($auditRows('payment_gateway.activated')[0]['metadata'] ?? ''), 'PAYWUZ'), 'gateway activation should be audited with the gateway code');

$buyerMeta = json_encode(['purpose' => 'cv_access', 'document_id' => 1, 'visitor_id' => 9, 'buyer_name' => 'Siti Buyer', 'buyer_phone' => '081299990000', 'buyer_email' => 'siti@example.com']);
$insert = $db->prepare("INSERT INTO payments (uuid, order_id, gateway_code, external_transaction_id, amount, status, metadata) VALUES (:uuid, :order, 'DUMMY', :order, 50000, :status, :meta)");
$insert->execute(['uuid' => '11111111-1111-4111-8111-111111111111', 'order' => 'ORD-A', 'status' => 'PENDING', 'meta' => $buyerMeta]);
$insert->execute(['uuid' => '22222222-2222-4222-8222-222222222222', 'order' => 'ORD-B', 'status' => 'PENDING', 'meta' => $buyerMeta]);

oaa_assert($call('POST', '/api/v1/me/payments/11111111-1111-4111-8111-111111111111/confirm')['status'] === 200, 'manual confirmation should succeed');
oaa_assert($call('POST', '/api/v1/me/payments/11111111-1111-4111-8111-111111111111/confirm')['status'] === 200, 'a repeated confirmation is a no-op');
$confirmed = $auditRows('payment.confirmed_manually');
oaa_assert(count($confirmed) === 1, 'only the confirmation that changed something should be audited, got ' . count($confirmed));
oaa_assert($confirmed[0]['subject_public_id'] === '11111111-1111-4111-8111-111111111111' && (int) $confirmed[0]['actor_user_id'] === $ownerId, 'the entry should name the payment and the acting owner');

oaa_assert($call('POST', '/api/v1/me/payments/11111111-1111-4111-8111-111111111111/refund', ['amount' => 20000])['status'] === 200, 'a partial refund should succeed');
$refunded = $auditRows('payment.refunded');
oaa_assert(count($refunded) === 1 && str_contains((string) $refunded[0]['metadata'], '20000'), 'the refund should be audited with its amount');

oaa_assert($call('POST', '/api/v1/me/payments/22222222-2222-4222-8222-222222222222/cancel')['status'] === 200, 'cancelling should succeed');
oaa_assert(count($auditRows('payment.cancelled')) === 1, 'the cancellation should be audited');

$allPaymentAudit = implode(' ', array_column($db->query("SELECT metadata FROM audit_events WHERE action LIKE 'payment.%'")->fetchAll(), 'metadata'));
foreach (['Siti Buyer', '081299990000', 'siti@example.com'] as $pii) {
    oaa_assert(!str_contains($allPaymentAudit, $pii), "buyer personal data ({$pii}) must not be copied into the audit trail");
}

// ---- 4. LLM configuration is audited without the API key ----
$llmKey = 'sk-test-very-secret-llm-key';
$llm = $call('PATCH', '/api/v1/me/llm-config', ['provider_code' => 'OPENAI', 'model' => 'gpt-test', 'api_key' => $llmKey]);
oaa_assert($llm['status'] === 200, 'configuring the LLM should succeed: ' . json_encode($llm));
$llmAudit = $auditRows('llm.configured');
oaa_assert(count($llmAudit) === 1 && str_contains((string) $llmAudit[0]['metadata'], 'gpt-test'), 'LLM configuration should be audited with provider and model');
oaa_assert(!str_contains((string) $llmAudit[0]['metadata'], $llmKey), 'the LLM API key must never reach the audit trail');

// ---- 5. A broken audit table never blocks the action being audited ----
$db->exec('DROP TABLE audit_events');
oaa_assert($threw(fn () => $auth->login(['email' => 'owner@example.com', 'password' => 'correct horse battery']), Throwable::class) === false, 'login must still work when the audit write fails');

unset($router, $llmController, $paymentController, $cvAccess, $payments, $auth, $audit, $nodes, $db);
Database::reset();
gc_collect_cycles();
unlink($envPath);
@unlink($dbPath);

fwrite(STDOUT, "Owner access and audit test passed\n");
