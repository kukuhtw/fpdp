<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

function post_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-post-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-post-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER, profile_id INTEGER, title TEXT, content TEXT, post_type TEXT DEFAULT "NOTE", visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, deleted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$dispatch = static function (string $method, string $path, ?array $body = null, ?string $token = null, array $query = []) use ($router): array {
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    $response = $router->dispatch(new Request($method, $path, $query, $body === null ? null : json_encode($body), $headers));
    return ['status' => $response->status, 'body' => $response->body === '' ? null : json_decode($response->body, true)];
};

$registration = $dispatch('POST', '/api/v1/auth/register', [
    'email' => 'writer@example.com',
    'password' => 'correct horse battery',
    'handle' => 'writer',
    'display_name' => 'Writer',
]);
post_assert($registration['status'] === 201, 'Registration failed');
$token = $registration['body']['data']['token']['access_token'];

$draft = $dispatch('POST', '/api/v1/posts', [
    'title' => 'Draft', 'content' => 'Not public yet', 'post_type' => 'ARTICLE', 'visibility' => 'PUBLIC',
], $token);
post_assert($draft['status'] === 201, 'Draft creation failed');
$postId = $draft['body']['data']['id'];

$hiddenDraft = $dispatch('GET', '/api/v1/posts/' . $postId);
post_assert($hiddenDraft['status'] === 404, 'Draft was publicly readable');

$published = $dispatch('PATCH', '/api/v1/posts/' . $postId, ['published_at' => '2026-09-19T10:00:00Z'], $token);
post_assert($published['status'] === 200, 'Publishing failed');
post_assert($published['body']['data']['canonical_url'] === 'https://writer.test.local/posts/' . $postId, 'Canonical URL is incorrect');

$publicPost = $dispatch('GET', '/api/v1/posts/' . $postId);
post_assert($publicPost['status'] === 200, 'Published post was not readable');

$timeline = $dispatch('GET', '/api/v1/timeline', null, null, ['limit' => 1]);
post_assert($timeline['status'] === 200 && count($timeline['body']['data']) === 1, 'Timeline did not return the published post');
post_assert($timeline['body']['data'][0]['source_type'] === 'LOCAL', 'Timeline source normalization is incorrect');

$deleted = $dispatch('DELETE', '/api/v1/posts/' . $postId, null, $token);
post_assert($deleted['status'] === 204, 'Soft delete failed');
post_assert($dispatch('GET', '/api/v1/posts/' . $postId)['status'] === 404, 'Deleted post remained visible');

Database::reset();
unset($db);
unlink($envPath);
unlink($dbPath);
fwrite(STDOUT, "Post endpoints test passed\n");
