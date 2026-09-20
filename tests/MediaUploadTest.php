<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\MediaController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Repositories\AuthTokenRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\Content\MediaUploadService;

function media_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// The smallest possible valid PNG (a 1x1 transparent pixel) — verified
// server-side by finfo, never trusted from a filename or claimed type.
const TEST_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

$dbPath = sys_get_temp_dir() . '/fpdp-media-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-media-test-' . uniqid() . '.env';
$storageDir = sys_get_temp_dir() . '/fpdp-media-storage-' . uniqid();

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
NODE_DOMAIN=test.local
AUTH_TOKEN_TTL=3600
MEDIA_MAX_FILE_SIZE_BYTES=200
ENV);

Config::load($envPath);
Database::reset();
$connection = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $connection->exec($sql);
}

$authService = new AuthService(
    new NodeRepository($connection),
    new UserRepository($connection),
    new ProfileRepository($connection),
    new AuthTokenRepository($connection),
);
$controller = new MediaController($authService, new MediaUploadService($storageDir));

$registered = $authService->register([
    'email' => 'owner@test.local',
    'password' => 'correct horse battery',
    'handle' => 'owner',
    'display_name' => 'Owner',
]);
$token = $registered['token']['access_token'];

$dispatchUpload = static function (?array $body, ?string $token) use ($controller): array {
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    try {
        $response = $controller->upload(new Request('POST', '/api/v1/me/media', [], $body === null ? null : json_encode($body), $headers));

        return ['status' => $response->status, 'body' => json_decode($response->body, true)];
    } catch (HttpException $e) {
        return ['status' => $e->getStatusCode(), 'body' => null];
    }
};
$dispatchShow = static function (string $key) use ($controller): array {
    try {
        $response = $controller->show(['key' => $key]);

        return ['status' => $response->status, 'headers' => $response->headers, 'raw' => $response->body];
    } catch (HttpException $e) {
        return ['status' => $e->getStatusCode(), 'headers' => [], 'raw' => null];
    }
};

// ---- Test 1: upload requires auth ----
$unauth = $dispatchUpload(['media_type' => 'IMAGE', 'content_base64' => TEST_PNG_BASE64], null);
media_assert($unauth['status'] === 401, 'Upload should require auth');

// ---- Test 2: a valid PNG upload succeeds and returns a public URL ----
$upload = $dispatchUpload(['media_type' => 'IMAGE', 'content_base64' => TEST_PNG_BASE64], $token);
media_assert($upload['status'] === 201, 'Valid PNG upload should succeed: ' . json_encode($upload));
media_assert($upload['body']['data']['content_type'] === 'image/png', 'Response should report the server-detected content type, not a client claim');
media_assert($upload['body']['data']['media_type'] === 'IMAGE', 'Response should echo the media type');
$url = $upload['body']['data']['url'];
media_assert(str_starts_with($url, 'https://owner.test.local/api/v1/media/'), "Upload should return an absolute https URL on the owner's node domain: {$url}");
media_assert((bool) preg_match('#/api/v1/media/([^/]+\.png)$#', $url, $urlMatch), 'Upload should assign a .png extension for an image/png file');
$storageKey = $urlMatch[1];

// ---- Test 3: the returned key actually serves the file back, inline, with nosniff ----
$fetched = $dispatchShow($storageKey);
media_assert($fetched['status'] === 200, 'The uploaded file should be fetchable by its returned key');
media_assert($fetched['headers']['Content-Type'] === 'image/png', 'Served file should carry the verified content type');
media_assert($fetched['headers']['Content-Disposition'] === 'inline', 'Post media should be served inline, not force-downloaded');
media_assert($fetched['headers']['X-Content-Type-Options'] === 'nosniff', 'Served media should set X-Content-Type-Options: nosniff');
media_assert($fetched['raw'] === base64_decode(TEST_PNG_BASE64), 'Served bytes should match the uploaded file exactly');

// ---- Test 4: an unknown/nonexistent key 404s, and a path-traversal-shaped key is rejected the same way ----
$missing = $dispatchShow('00000000-0000-4000-8000-000000000000.png');
media_assert($missing['status'] === 404, 'A well-formed but nonexistent key should 404');

$traversal = $dispatchShow('../../../../etc/passwd');
media_assert($traversal['status'] === 404, 'A path-traversal-shaped key should 404, not read an arbitrary file');

// ---- Test 5: invalid media_type is rejected ----
$badType = $dispatchUpload(['media_type' => 'EXECUTABLE', 'content_base64' => TEST_PNG_BASE64], $token);
media_assert($badType['status'] === 422, 'An unrecognized media_type should be rejected');

// ---- Test 6: content that does not match any allowed type for the declared media_type is rejected (stored-XSS defense) ----
// This also covers "the client lies about the file type": plain HTML/script bytes claiming to be an IMAGE.
$htmlAsImage = $dispatchUpload([
    'media_type' => 'IMAGE',
    'content_base64' => base64_encode('<script>alert(1)</script>'),
], $token);
media_assert($htmlAsImage['status'] === 422, 'HTML/script content disguised as IMAGE must be rejected, not stored and served back');

// ---- Test 7: a file over MEDIA_MAX_FILE_SIZE_BYTES (200 bytes in this test env) is rejected ----
$oversized = $dispatchUpload([
    'media_type' => 'FILE',
    'content_base64' => base64_encode('%PDF-1.4' . str_repeat('0', 500)),
], $token);
media_assert($oversized['status'] === 422, 'A file larger than MEDIA_MAX_FILE_SIZE_BYTES should be rejected');

Database::reset();
unset($connection);
@unlink($envPath);
@unlink($dbPath);
array_map('unlink', glob($storageDir . '/*') ?: []);
@rmdir($storageDir);

fwrite(STDOUT, "Media upload test passed\n");
