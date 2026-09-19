<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Contracts\GoogleOAuthClientInterface;
use App\Controllers\VisitorAuthController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;
use App\Repositories\ProfileRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\OAuthStateSigner;
use App\Services\Visitor\VisitorAuthService;

final class FakeGoogleOAuthClient implements GoogleOAuthClientInterface
{
    /**
     * @param array{sub: string, email: string, name: ?string, picture: ?string} $profile
     */
    public function __construct(private readonly array $profile)
    {
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/fake-consent?' . http_build_query([
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);
    }

    public function resolveVisitorProfile(string $code, string $redirectUri): array
    {
        if ($code === 'invalid-code') {
            throw new \RuntimeException('Invalid authorization code.');
        }

        return $this->profile;
    }
}

function assert_that(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-visitor-auth-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-visitor-auth-test-' . uniqid() . '.env';

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
APP_KEY=test-signing-secret
VISITOR_TOKEN_TTL=3600
ENV);

Config::load($envPath);
Database::reset();
$connection = Database::connection();

$connection->exec('
    CREATE TABLE nodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        domain TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "ACTIVE",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
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
$connection->exec('
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
$connection->exec('
    CREATE TABLE visitor_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL,
        google_sub TEXT NOT NULL,
        email TEXT NOT NULL,
        display_name TEXT,
        avatar_url TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (node_id, google_sub)
    )
');
$connection->exec('
    CREATE TABLE visitor_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visitor_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TIMESTAMP NOT NULL,
        revoked_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');

// Seed one node/user/profile ("alice") to visit, bypassing AuthService since
// this test only exercises the visitor-auth surface.
$connection->exec("INSERT INTO nodes (public_id, domain, name) VALUES ('" . Uuid::v4() . "', 'alice.test.local', 'Alice Node')");
$nodeId = (int) $connection->lastInsertId();
$connection->exec("INSERT INTO users (public_id, node_id, email, password_hash) VALUES ('" . Uuid::v4() . "', {$nodeId}, 'alice@example.com', 'hash')");
$userId = (int) $connection->lastInsertId();
$connection->exec("INSERT INTO profiles (public_id, user_id, handle, display_name) VALUES ('" . Uuid::v4() . "', {$userId}, 'alice', 'Alice Owner')");

$fakeProfile = ['sub' => 'google-sub-123', 'email' => 'visitor@example.com', 'name' => 'Visitor Name', 'picture' => null];

$visitorAuthService = new VisitorAuthService(
    new FakeGoogleOAuthClient($fakeProfile),
    new VisitorRepository($connection),
    new VisitorTokenRepository($connection),
);
$profileService = new ProfileService(new ProfileRepository($connection));
$stateSigner = new OAuthStateSigner(Config::get('APP_KEY'));
$controller = new VisitorAuthController($profileService, $visitorAuthService, $stateSigner);

$router = new Router();
$router->get('/api/v1/profiles/{handle}/visitor-auth/google/redirect', fn (Request $r, array $p) => $controller->redirect($r, $p));
$router->get('/api/v1/profiles/{handle}/visitor-auth/google/callback', fn (Request $r, array $p) => $controller->callback($r, $p));

// 1. Redirect for an unknown handle fails fast with 404, never touching Google.
$missing = $router->dispatch(new Request('GET', '/api/v1/profiles/nobody/visitor-auth/google/redirect'));
assert_that($missing->status === 404, "Redirect for unknown handle did not return 404, got {$missing->status}");

// 2. Redirect for a real handle returns a 302 to Google carrying a signed state.
$redirect = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/visitor-auth/google/redirect'));
assert_that($redirect->status === 302, "Redirect did not return 302, got {$redirect->status}");
$location = $redirect->headers['Location'] ?? '';
assert_that(str_starts_with($location, 'https://accounts.google.com/fake-consent?'), 'Redirect Location did not point at Google');

parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
$state = (string) $query['state'];
$redirectUri = (string) $query['redirect_uri'];

// 3. Callback with a valid code and matching state creates a visitor and issues a token.
$callback = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/visitor-auth/google/callback', ['code' => 'good-code', 'state' => $state]));
assert_that($callback->status === 200, "Callback did not return 200, got {$callback->status}: {$callback->body}");
$decoded = json_decode($callback->body, true);
assert_that($decoded['data']['visitor']['email'] === 'visitor@example.com', 'Callback did not return the resolved visitor email');
$visitorToken = $decoded['data']['token']['access_token'];
assert_that(is_string($visitorToken) && $visitorToken !== '', 'Callback did not return a visitor access token');

$visitorCount = (int) $connection->query('SELECT COUNT(*) FROM visitor_accounts')->fetchColumn();
assert_that($visitorCount === 1, "Expected exactly one visitor account, found {$visitorCount}");

// 4. A second callback for the same Google identity reuses the visitor row (no duplicate).
$secondState = $stateSigner->sign('alice', $redirectUri);
$secondCallback = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/visitor-auth/google/callback', ['code' => 'good-code', 'state' => $secondState]));
assert_that($secondCallback->status === 200, 'Second callback for the same Google identity failed');
$visitorCountAfter = (int) $connection->query('SELECT COUNT(*) FROM visitor_accounts')->fetchColumn();
assert_that($visitorCountAfter === 1, "Expected the visitor account to be reused, found {$visitorCountAfter} rows");

// 5. A state signed for a different handle is rejected even if otherwise valid.
$wrongHandleState = $stateSigner->sign('someone-else', $redirectUri);
$mismatched = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/visitor-auth/google/callback', ['code' => 'good-code', 'state' => $wrongHandleState]));
assert_that($mismatched->status === 401, "Mismatched-handle state was not rejected, got {$mismatched->status}");

// 6. A tampered state is rejected.
$tamperedCallback = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/visitor-auth/google/callback', ['code' => 'good-code', 'state' => $state . 'x']));
assert_that($tamperedCallback->status === 401, "Tampered state was not rejected, got {$tamperedCallback->status}");

// 7. authenticate() accepts the issued token and rejects it once revoked.
$context = $visitorAuthService->authenticate($visitorToken);
assert_that($context['visitor']['email'] === 'visitor@example.com', 'authenticate() did not resolve the expected visitor');

$visitorAuthService->logout($visitorToken);
$revokedRejected = false;
try {
    $visitorAuthService->authenticate($visitorToken);
} catch (\App\Core\Exceptions\UnauthorizedException $e) {
    $revokedRejected = true;
}
assert_that($revokedRejected, 'A revoked visitor token was still accepted');

unset($router, $controller, $visitorAuthService, $profileService, $connection);
Database::reset();
gc_collect_cycles();
unlink($envPath);
unlink($dbPath);

fwrite(STDOUT, "Visitor auth endpoints test passed\n");
