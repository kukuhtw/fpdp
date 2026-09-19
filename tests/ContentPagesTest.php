<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

function page_assert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); }
}

$dbPath = sys_get_temp_dir() . '/fpdp-page-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-page-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();
foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY, public_id TEXT, domain TEXT, name TEXT, default_locale TEXT, timezone TEXT, status TEXT, created_at TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, public_id TEXT, node_id INTEGER, email TEXT, password_hash TEXT, role TEXT, status TEXT, created_at TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY, public_id TEXT, user_id INTEGER, handle TEXT, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT, links TEXT, created_at TIMESTAMP, updated_at TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT, token_type TEXT, scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP)',
    'CREATE TABLE posts (id INTEGER PRIMARY KEY, public_id TEXT, user_id INTEGER, profile_id INTEGER, title TEXT, content TEXT, post_type TEXT, visibility TEXT, published_at TIMESTAMP, deleted_at TIMESTAMP, created_at TIMESTAMP, updated_at TIMESTAMP)',
] as $sql) { $db->exec($sql); }
$db->exec("INSERT INTO nodes VALUES (1, 'node-1', 'writer.test.local', 'Writer Node', 'en', 'UTC', 'ACTIVE', CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO users VALUES (1, 'user-1', 1, 'writer@example.com', 'hash', 'OWNER', 'ACTIVE', CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO profiles VALUES (1, 'profile-1', 1, 'writer', 'Writer <script>', 'Bio <b>unsafe</b>', NULL, 'PUBLIC', '[]', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO posts VALUES (1, 'post-1', 1, 1, 'Hello <img>', '<script>alert(1)</script> body', 'ARTICLE', 'PUBLIC', CURRENT_TIMESTAMP, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$timeline = $router->dispatch(new Request('GET', '/timeline'));
page_assert($timeline->status === 200 && str_contains($timeline->body, 'Local timeline'), 'Timeline page failed');
page_assert(!str_contains($timeline->body, '<script>alert(1)</script>'), 'Timeline rendered unsafe post HTML');
page_assert(str_contains($timeline->body, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Timeline did not escape post content');

$profile = $router->dispatch(new Request('GET', '/@writer'));
page_assert($profile->status === 200 && str_contains($profile->body, 'Writer &lt;script&gt;'), 'Profile page failed or did not escape display name');
page_assert(str_contains($profile->body, 'Bio &lt;b&gt;unsafe&lt;/b&gt;'), 'Profile bio was not escaped');

$post = $router->dispatch(new Request('GET', '/posts/post-1'));
page_assert($post->status === 200 && str_contains($post->body, 'Hello &lt;img&gt;'), 'Post page failed or title was not escaped');
$editor = $router->dispatch(new Request('GET', '/dashboard/posts'));
page_assert($editor->status === 200 && str_contains($editor->body, 'Post editor'), 'Post editor page failed');

Database::reset(); unset($db); unlink($envPath); unlink($dbPath);
fwrite(STDOUT, "Content pages test passed\n");
