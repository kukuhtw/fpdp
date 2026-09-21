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
    'CREATE TABLE posts (id INTEGER PRIMARY KEY, public_id TEXT, user_id INTEGER, profile_id INTEGER, title TEXT, slug TEXT, content TEXT, post_type TEXT, visibility TEXT, published_at TIMESTAMP, deleted_at TIMESTAMP, created_at TIMESTAMP, updated_at TIMESTAMP)',
    'CREATE TABLE post_media (id INTEGER PRIMARY KEY, post_id INTEGER, media_type TEXT, url TEXT, alt_text TEXT, sort_order INTEGER, created_at TIMESTAMP)',
    'CREATE TABLE external_feed_sources (id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT, source_type TEXT, source_url TEXT, external_account_id INTEGER, sync_enabled INTEGER, sync_interval INTEGER, last_sync_at TIMESTAMP, next_sync_at TIMESTAMP, status TEXT, last_error TEXT, created_at TIMESTAMP, updated_at TIMESTAMP)',
    'CREATE TABLE external_posts (id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT, external_post_id TEXT, external_account_id INTEGER, feed_source_id INTEGER, post_type TEXT, canonical_url TEXT, title TEXT, content TEXT, media_json TEXT, author_name TEXT, published_at TIMESTAMP, fetched_at TIMESTAMP, raw_payload TEXT, status TEXT)',
] as $sql) { $db->exec($sql); }
$db->exec("INSERT INTO nodes VALUES (1, 'node-1', 'writer.test.local', 'Writer Node', 'en', 'UTC', 'ACTIVE', CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO users VALUES (1, 'user-1', 1, 'writer@example.com', 'hash', 'OWNER', 'ACTIVE', CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO profiles VALUES (1, 'profile-1', 1, 'writer', 'Writer <script>', 'Bio <b>unsafe</b>', NULL, 'PUBLIC', '[]', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO posts VALUES (1, 'post-1', 1, 1, 'Hello <img>', 'hello-img', '&lt;script&gt;alert(1)&lt;/script&gt; body', 'ARTICLE', 'PUBLIC', CURRENT_TIMESTAMP, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO external_feed_sources VALUES (1, 1, 'YOUTUBE', 'youtube_channel', 'https://www.youtube.com/feeds/videos.xml?channel_id=UCtest', NULL, 1, 3600, CURRENT_TIMESTAMP, NULL, 'ACTIVE', NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$media = json_encode([['type' => 'VIDEO', 'provider' => 'YOUTUBE', 'video_id' => 'jNQXAC9IVRw', 'embed_url' => 'https://evil.example/embed']]);
$insertVideo = $db->prepare("INSERT INTO external_posts VALUES (1, 1, 'YOUTUBE', 'yt:video:jNQXAC9IVRw', NULL, 1, 'MEDIA', 'https://www.youtube.com/watch?v=jNQXAC9IVRw', NULL, 'Me at the zoo', :media, 'Studio Notes', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, 'ACTIVE')");
$insertVideo->execute(['media' => $media]);

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$timeline = $router->dispatch(new Request('GET', '/timeline'));
page_assert($timeline->status === 200 && str_contains($timeline->body, 'Local timeline'), 'Timeline page failed');
page_assert(!str_contains($timeline->body, '<script>alert(1)</script>'), 'Timeline rendered unsafe post HTML');
page_assert(str_contains($timeline->body, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Timeline did not escape post content');

$profile = $router->dispatch(new Request('GET', '/@writer'));
page_assert($profile->status === 200 && str_contains($profile->body, 'Writer &lt;script&gt;'), 'Profile page failed or did not escape display name');
page_assert(str_contains($profile->body, 'Bio &lt;b&gt;unsafe&lt;/b&gt;'), 'Profile bio was not escaped');
page_assert(str_contains($profile->body, 'https://www.youtube-nocookie.com/embed/jNQXAC9IVRw'), 'Profile did not render the synced YouTube video');
page_assert(!str_contains($profile->body, 'evil.example'), 'Profile trusted an unvalidated stored embed URL');

$post = $router->dispatch(new Request('GET', '/posts/post-1'));
page_assert($post->status === 200 && str_contains($post->body, 'Hello &lt;img&gt;'), 'Post page failed or title was not escaped');
$editor = $router->dispatch(new Request('GET', '/dashboard/posts'));
page_assert($editor->status === 200 && str_contains($editor->body, 'Post editor'), 'Post editor page failed');

Database::reset(); unset($db); unlink($envPath); unlink($dbPath);
fwrite(STDOUT, "Content pages test passed\n");
