<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Contracts\GoogleOAuthClientInterface;
use App\Controllers\CvController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Repositories\AuthTokenRepository;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use App\Services\Auth\AuthService;
use App\Services\Cv\CvAccessService;
use App\Services\Cv\CvDocumentService;
use App\Services\Payment\PaymentService;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\VisitorAuthService;

final class FakeGoogleOAuthClientForCv implements GoogleOAuthClientInterface
{
    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/fake?state=' . urlencode($state);
    }

    public function resolveVisitorProfile(string $code, string $redirectUri): array
    {
        return ['sub' => 'google-sub-cv-test', 'email' => 'visitor@example.com', 'name' => 'Visitor', 'picture' => null];
    }
}

function assert_that(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-cv-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-cv-test-' . uniqid() . '.env';
$storageDir = sys_get_temp_dir() . '/fpdp-cv-storage-' . uniqid();

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
NODE_DOMAIN=test.local
AUTH_TOKEN_TTL=3600
VISITOR_TOKEN_TTL=3600
CV_MAX_FILE_SIZE_BYTES=1048576
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
        default_locale TEXT NOT NULL DEFAULT "id",
        timezone TEXT NOT NULL DEFAULT "Asia/Jakarta",
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
$connection->exec('
    CREATE TABLE cv_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL UNIQUE,
        title TEXT NOT NULL,
        storage_key TEXT NOT NULL,
        content_type TEXT NOT NULL DEFAULT "application/pdf",
        price_amount TEXT NOT NULL DEFAULT "0.00",
        price_currency TEXT NOT NULL DEFAULT "IDR",
        status TEXT NOT NULL DEFAULT "ACTIVE",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE cv_access_grants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cv_document_id INTEGER NOT NULL,
        visitor_id INTEGER NOT NULL,
        payment_reference TEXT,
        granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (cv_document_id, visitor_id)
    )
');
$connection->exec('
    CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL UNIQUE,
        order_id TEXT NOT NULL,
        gateway_code TEXT NOT NULL,
        external_transaction_id TEXT,
        payment_method TEXT,
        currency TEXT NOT NULL DEFAULT "IDR",
        amount REAL NOT NULL DEFAULT 0,
        fee REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT "PENDING",
        payment_url TEXT,
        metadata TEXT,
        expired_at TIMESTAMP,
        paid_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE payment_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payment_id INTEGER NOT NULL,
        provider TEXT NOT NULL,
        external_id TEXT,
        event_type TEXT NOT NULL,
        status TEXT NOT NULL,
        payload TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (provider, external_id)
    )
');

// Register a real owner via the real AuthService (exercises Phase 1 code, not a shortcut).
$authService = new AuthService(
    new NodeRepository($connection),
    new UserRepository($connection),
    new ProfileRepository($connection),
    new AuthTokenRepository($connection),
);
$registered = $authService->register([
    'email' => 'alice@example.com',
    'password' => 'correct horse battery',
    'handle' => 'alice',
    'display_name' => 'Alice Owner',
]);
$ownerToken = $registered['token']['access_token'];

// Resolve a visitor via the real VisitorAuthService with a fake Google client.
$visitorAuthService = new VisitorAuthService(
    new FakeGoogleOAuthClientForCv(),
    new VisitorRepository($connection),
    new VisitorTokenRepository($connection),
);
$visitorResult = $visitorAuthService->handleCallback((int) $registered['node']['id'], 'any-code', 'https://example.test/callback');
$visitorToken = $visitorResult['token']['access_token'];

$profileService = new ProfileService(new ProfileRepository($connection));
$documentService = new CvDocumentService(new CvDocumentRepository($connection), new CvAccessGrantRepository($connection), $storageDir);
$accessService = new CvAccessService(
    new CvDocumentRepository($connection),
    new CvAccessGrantRepository($connection),
    new PaymentService(),
    $storageDir,
);
$controller = new CvController($authService, $profileService, $visitorAuthService, $documentService, $accessService);

$router = new Router();
$router->post('/api/v1/me/cv', fn (Request $r, array $p) => $controller->upload($r));
$router->get('/api/v1/profiles/{handle}/cv', fn (Request $r, array $p) => $controller->show($r, $p));
$router->post('/api/v1/profiles/{handle}/cv/access', fn (Request $r, array $p) => $controller->grantAccess($r, $p));
$router->get('/api/v1/profiles/{handle}/cv/download', fn (Request $r, array $p) => $controller->download($r, $p));

function bearer(?string $token): array
{
    return $token === null ? [] : ['authorization' => 'Bearer ' . $token];
}

$fileBytes = 'PDF-ish content for the free CV.';
$freeCvBody = [
    'title' => 'Alice Resume',
    'price_amount' => 0,
    'price_currency' => 'IDR',
    'content_type' => 'application/pdf',
    'content_base64' => base64_encode($fileBytes),
];

// 1. Upload without an owner token is rejected.
$unauthUpload = $router->dispatch(new Request('POST', '/api/v1/me/cv', [], json_encode($freeCvBody)));
assert_that($unauthUpload->status === 401, "Upload without owner token did not return 401, got {$unauthUpload->status}");

// 2. Upload with a missing title fails validation.
$invalidBody = $freeCvBody;
unset($invalidBody['title']);
$invalidUpload = $router->dispatch(new Request('POST', '/api/v1/me/cv', [], json_encode($invalidBody), bearer($ownerToken)));
assert_that($invalidUpload->status === 422, "Upload with missing title did not return 422, got {$invalidUpload->status}");

// 3. Owner uploads a free CV.
$upload = $router->dispatch(new Request('POST', '/api/v1/me/cv', [], json_encode($freeCvBody), bearer($ownerToken)));
assert_that($upload->status === 201, "Free CV upload failed: {$upload->body}");
$storedFiles = glob($storageDir . '/*');
assert_that(count($storedFiles) === 1 && file_get_contents($storedFiles[0]) === $fileBytes, 'Uploaded file content was not stored correctly');

// 4. Public metadata read shows access for a free CV, even anonymously.
$publicMeta = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv'));
$publicMetaData = json_decode($publicMeta->body, true)['data'];
assert_that($publicMeta->status === 200 && $publicMetaData['has_access'] === true, 'Free CV was not reported as accessible anonymously');

// 5. Unknown profile handle returns 404.
$missingProfile = $router->dispatch(new Request('GET', '/api/v1/profiles/nobody/cv'));
assert_that($missingProfile->status === 404, "Unknown handle did not return 404, got {$missingProfile->status}");

// 6. Access requires a visitor token.
$unauthAccess = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/cv/access'));
assert_that($unauthAccess->status === 401, "Access without a visitor token did not return 401, got {$unauthAccess->status}");

// 7. Visitor grants access to the free CV (no payment needed) and can download it.
$freeAccess = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/cv/access', [], null, bearer($visitorToken)));
$freeAccessData = json_decode($freeAccess->body, true)['data'];
assert_that($freeAccess->status === 200 && $freeAccessData['granted'] === true && $freeAccessData['payment'] === null, 'Free CV access grant failed');

$freeDownload = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv/download', [], null, bearer($visitorToken)));
assert_that($freeDownload->status === 200 && $freeDownload->body === $fileBytes, 'Free CV download did not return the uploaded bytes');
assert_that($freeDownload->headers['Content-Type'] === 'application/pdf', 'Free CV download had the wrong content type');

// 8. Owner replaces the CV with a priced one; the row is replaced, not duplicated.
$pricedBytes = 'Priced PDF content.';
$pricedBody = [
    'title' => 'Alice Resume v2',
    'price_amount' => 50000,
    'price_currency' => 'IDR',
    'content_type' => 'application/pdf',
    'content_base64' => base64_encode($pricedBytes),
];
$reupload = $router->dispatch(new Request('POST', '/api/v1/me/cv', [], json_encode($pricedBody), bearer($ownerToken)));
assert_that($reupload->status === 201, "Priced CV re-upload failed: {$reupload->body}");
$documentCount = (int) $connection->query('SELECT COUNT(*) FROM cv_documents')->fetchColumn();
assert_that($documentCount === 1, "Expected exactly one CV document after re-upload, found {$documentCount}");
$storedFilesAfterReplace = glob($storageDir . '/*');
assert_that(count($storedFilesAfterReplace) === 1, 'Old CV file was not deleted after replacement');

// 9. The previous visitor's grant was for the old document row, so the priced
// CV now correctly requires a new payment before it can be downloaded.
$pricedMeta = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv'));
$pricedMetaData = json_decode($pricedMeta->body, true)['data'];
assert_that($pricedMetaData['has_access'] === false, 'Priced CV was incorrectly reported as accessible with no auth');

$deniedDownload = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv/download', [], null, bearer($visitorToken)));
assert_that($deniedDownload->status === 402, "Download without a grant did not return 402, got {$deniedDownload->status}");

// 10. Visitor pays (dummy gateway) and is granted access, then can download.
$paidAccess = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/cv/access', [], null, bearer($visitorToken)));
$paidAccessData = json_decode($paidAccess->body, true)['data'];
assert_that($paidAccess->status === 200 && $paidAccessData['granted'] === true && $paidAccessData['payment'] !== null, 'Paid CV access did not create a payment-backed grant');

$paidDownload = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/cv/download', [], null, bearer($visitorToken)));
assert_that($paidDownload->status === 200 && $paidDownload->body === $pricedBytes, 'Paid CV download did not return the new file bytes');

unset($router, $controller, $accessService, $documentService, $profileService, $visitorAuthService, $authService, $connection);
Database::reset();
gc_collect_cycles();
unlink($envPath);
unlink($dbPath);
array_map('unlink', glob($storageDir . '/*'));
rmdir($storageDir);

fwrite(STDOUT, "CV endpoints test passed\n");
