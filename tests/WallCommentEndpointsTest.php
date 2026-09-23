<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Contracts\GoogleOAuthClientInterface;
use App\Controllers\WallCommentController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Repositories\AuthTokenRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use App\Repositories\WallCommentRepository;
use App\Services\Auth\AuthService;
use App\Services\Content\WallCommentService;
use App\Services\Profile\ProfileService;
use App\Services\Security\RateLimiter;
use App\Services\Visitor\VisitorAuthService;

final class FakeGoogleOAuthClientForWall implements GoogleOAuthClientInterface
{
    public function __construct(private readonly string $sub = 'google-sub-wall-test')
    {
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/fake?state=' . urlencode($state);
    }

    public function resolveVisitorProfile(string $code, string $redirectUri): array
    {
        return ['sub' => $this->sub, 'email' => 'visitor@example.com', 'name' => 'Visitor Name', 'picture' => null];
    }
}

function assert_that(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-wall-comment-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-wall-comment-test-' . uniqid() . '.env';

file_put_contents($envPath, <<<ENV
APP_NAME=FPDP
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE={$dbPath}
NODE_DOMAIN=test.local
AUTH_TOKEN_TTL=3600
VISITOR_TOKEN_TTL=3600
RATE_LIMIT_CORETAN_MAX=5
RATE_LIMIT_CORETAN_WINDOW=600
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
    CREATE TABLE wall_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id TEXT NOT NULL UNIQUE,
        node_id INTEGER NOT NULL,
        visitor_id INTEGER NOT NULL,
        content TEXT NOT NULL,
        admin_reply TEXT,
        admin_reply_at TIMESTAMP,
        deleted_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');
$connection->exec('
    CREATE TABLE rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rate_key TEXT NOT NULL UNIQUE,
        attempts INTEGER NOT NULL DEFAULT 1,
        window_started_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');

// Register a real owner via the real AuthService.
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
$nodeId = (int) $registered['node']['id'];

// A second, unrelated owner+node — used to prove wall comments are node-scoped.
$registeredOther = $authService->register([
    'email' => 'bob@example.com',
    'password' => 'correct horse battery too',
    'handle' => 'bob',
    'display_name' => 'Bob Owner',
]);
$otherOwnerToken = $registeredOther['token']['access_token'];

// Resolve a visitor via the real VisitorAuthService with a fake Google client.
$visitorAuthService = new VisitorAuthService(
    new FakeGoogleOAuthClientForWall(),
    new VisitorRepository($connection),
    new VisitorTokenRepository($connection),
);
$visitorResult = $visitorAuthService->handleCallback($nodeId, 'any-code', 'https://example.test/callback');
$visitorToken = $visitorResult['token']['access_token'];

$profileService = new ProfileService(new ProfileRepository($connection));
$commentService = new WallCommentService(new WallCommentRepository($connection));
$rateLimiter = new RateLimiter(new RateLimitRepository($connection));
$controller = new WallCommentController($authService, $visitorAuthService, $profileService, $commentService, $rateLimiter);

$router = new Router();
$router->get('/api/v1/profiles/{handle}/wall/comments', fn (Request $r, array $p) => $controller->index($r, $p));
$router->post('/api/v1/profiles/{handle}/wall/comments', fn (Request $r, array $p) => $controller->create($r, $p));
$router->delete('/api/v1/me/wall/comments/{commentId}', fn (Request $r, array $p) => $controller->delete($r, $p));
$router->post('/api/v1/me/wall/comments/{commentId}/reply', fn (Request $r, array $p) => $controller->reply($r, $p));

function bearer(?string $token): array
{
    return $token === null ? [] : ['authorization' => 'Bearer ' . $token];
}

// 1. Posting a comment without a visitor token is rejected.
$unauthCreate = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/wall/comments', [], json_encode(['content' => 'Halo!'])));
assert_that($unauthCreate->status === 401, "Comment creation without a visitor token did not return 401, got {$unauthCreate->status}");

// 2. An unknown profile handle returns 404, even with a valid visitor token.
$missingHandle = $router->dispatch(new Request('POST', '/api/v1/profiles/nobody/wall/comments', [], json_encode(['content' => 'Halo!']), bearer($visitorToken)));
assert_that($missingHandle->status === 404, "Unknown handle did not return 404, got {$missingHandle->status}");

// 3. Empty content is rejected with a validation error.
$emptyContent = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/wall/comments', [], json_encode(['content' => '   ']), bearer($visitorToken)));
assert_that($emptyContent->status === 422, "Empty comment content did not return 422, got {$emptyContent->status}");

// 4. A signed-in visitor can post a comment; the raw text (including a bare URL) is stored verbatim.
$createBody = ['content' => "Halo dari pengunjung!\nMampir ke https://kukuhtw.medium.com ya."];
$created = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/wall/comments', [], json_encode($createBody), bearer($visitorToken)));
assert_that($created->status === 201, "Comment creation failed: {$created->body}");
$createdData = json_decode($created->body, true)['data'];
assert_that($createdData['content'] === $createBody['content'], 'Stored comment content did not match the submitted plain text exactly');
assert_that($createdData['author']['display_name'] === 'Visitor Name', 'Comment author display name was not the visitor\'s name');
$commentId = $createdData['id'];

// 5. The public list (no auth needed) shows the new comment, newest first.
$publicList = $router->dispatch(new Request('GET', '/api/v1/profiles/alice/wall/comments'));
$publicListData = json_decode($publicList->body, true)['data'];
assert_that($publicList->status === 200 && count($publicListData) === 1 && $publicListData[0]['id'] === $commentId, 'Public comment list did not return the new comment');

// 6. Deleting requires an owner bearer token.
$unauthDelete = $router->dispatch(new Request('DELETE', "/api/v1/me/wall/comments/{$commentId}"));
assert_that($unauthDelete->status === 401, "Delete without an owner token did not return 401, got {$unauthDelete->status}");

// 7. A different node's owner cannot delete (or even see) this comment — 404, not 204/403.
$crossNodeDelete = $router->dispatch(new Request('DELETE', "/api/v1/me/wall/comments/{$commentId}", [], null, bearer($otherOwnerToken)));
assert_that($crossNodeDelete->status === 404, "Cross-node delete did not return 404, got {$crossNodeDelete->status}");

// 8. Replying requires an owner bearer token.
$unauthReply = $router->dispatch(new Request('POST', "/api/v1/me/wall/comments/{$commentId}/reply", [], json_encode(['reply' => 'Terima kasih!'])));
assert_that($unauthReply->status === 401, "Reply without an owner token did not return 401, got {$unauthReply->status}");

// 9. The owner replies to the comment; the reply shows up in the public list.
$reply = $router->dispatch(new Request('POST', "/api/v1/me/wall/comments/{$commentId}/reply", [], json_encode(['reply' => 'Terima kasih sudah mampir!']), bearer($ownerToken)));
assert_that($reply->status === 200, "Owner reply failed: {$reply->body}");
$listAfterReply = json_decode($router->dispatch(new Request('GET', '/api/v1/profiles/alice/wall/comments'))->body, true)['data'];
assert_that($listAfterReply[0]['admin_reply'] === 'Terima kasih sudah mampir!', 'Admin reply was not reflected in the public comment list');

// 10. The owner deletes the comment (soft delete); it disappears from the public list.
$delete = $router->dispatch(new Request('DELETE', "/api/v1/me/wall/comments/{$commentId}", [], null, bearer($ownerToken)));
assert_that($delete->status === 204, "Owner delete failed, got {$delete->status}");
$listAfterDelete = json_decode($router->dispatch(new Request('GET', '/api/v1/profiles/alice/wall/comments'))->body, true)['data'];
assert_that($listAfterDelete === [], 'Deleted comment still appeared in the public list');

// 11. Deleting again (already gone) returns 404 rather than a false 204.
$redeleteMissing = $router->dispatch(new Request('DELETE', "/api/v1/me/wall/comments/{$commentId}", [], null, bearer($ownerToken)));
assert_that($redeleteMissing->status === 404, "Deleting an already-deleted comment did not return 404, got {$redeleteMissing->status}");

// 12. RATE_LIMIT_CORETAN_MAX=5: a fresh visitor (own counter, unaffected by the
// attempts above) gets exactly 5 comments through; the 6th is throttled.
$secondVisitorAuthService = new VisitorAuthService(
    new FakeGoogleOAuthClientForWall('google-sub-wall-test-2'),
    new VisitorRepository($connection),
    new VisitorTokenRepository($connection),
);
$secondVisitorToken = $secondVisitorAuthService->handleCallback($nodeId, 'any-code', 'https://example.test/callback')['token']['access_token'];

for ($i = 1; $i <= 5; $i++) {
    $withinLimit = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/wall/comments', [], json_encode(['content' => "Coretan {$i}"]), bearer($secondVisitorToken)));
    assert_that($withinLimit->status === 201, "Comment #{$i} within the rate limit was unexpectedly rejected: {$withinLimit->status}");
}
$throttled = $router->dispatch(new Request('POST', '/api/v1/profiles/alice/wall/comments', [], json_encode(['content' => 'One too many']), bearer($secondVisitorToken)));
assert_that($throttled->status === 429, "Comment beyond the rate limit did not return 429, got {$throttled->status}");

unset($router, $controller, $commentService, $profileService, $visitorAuthService, $authService, $rateLimiter, $connection);
Database::reset();
gc_collect_cycles();
unlink($envPath);
unlink($dbPath);

fwrite(STDOUT, "Wall comment endpoints test passed\n");
