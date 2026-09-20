<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\HttpClient;
use App\Core\Http\Request;
use App\Core\Router;

function ext_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-ext-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-ext-test-' . uniqid() . '.env';
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
    'CREATE TABLE external_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, provider TEXT, external_account_id TEXT, external_username TEXT, display_name TEXT, profile_url TEXT, access_token TEXT, refresh_token TEXT, token_expires_at TIMESTAMP, permissions TEXT, connection_status TEXT DEFAULT "ACTIVE", last_sync_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE external_feed_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, provider TEXT, source_type TEXT, source_url TEXT, external_account_id INTEGER, sync_enabled INTEGER DEFAULT 1, sync_interval INTEGER DEFAULT 3600, last_sync_at TIMESTAMP, next_sync_at TIMESTAMP, status TEXT DEFAULT "ACTIVE", last_error TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE external_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, provider TEXT, external_post_id TEXT, external_account_id INTEGER, post_type TEXT DEFAULT "ARTICLE", canonical_url TEXT, title TEXT, content TEXT, media_json TEXT, author_name TEXT, published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, raw_payload TEXT, status TEXT DEFAULT "ACTIVE")',
    'CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER, profile_id INTEGER, title TEXT, content TEXT, post_type TEXT DEFAULT "NOTE", visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, deleted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE post_media (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, media_type TEXT, url TEXT, alt_text TEXT, sort_order INTEGER DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
'CREATE TABLE integration_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, provider TEXT, job_type TEXT, payload TEXT, status TEXT DEFAULT "QUEUED", retry_count INTEGER DEFAULT 0, next_retry_at TIMESTAMP, last_error TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}
/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$dispatch = static fn(string $method, string $path, ?array $body = null, ?string $token = null, array $query = []) => $router->dispatch(new Request($method, $path, $query, $body === null ? null : json_encode($body), $token === null ? [] : ['authorization' => 'Bearer ' . $token]));

// Register owner
$r = $dispatch('POST', '/api/v1/auth/register', ['email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner']);
ext_assert($r->status === 201, 'Owner registration failed');
$token = json_decode($r->body, true)['data']['token']['access_token'];

// Add RSS feed source
$r = $dispatch('POST', '/api/v1/me/feed-sources', ['provider' => 'RSS', 'source_type' => 'rss', 'source_url' => 'https://example.com/feed.xml'], $token);
ext_assert($r->status === 201, 'Add feed source failed');

// YouTube channel URLs are normalized to the official public Atom feed.
$youtubeId = 'UC_x5XG1OV2P6uZZ5FSM9Ttw';
$r = $dispatch('POST', '/api/v1/me/feed-sources', ['provider' => 'YOUTUBE', 'source_type' => 'youtube_channel', 'source_url' => 'https://www.youtube.com/channel/' . $youtubeId], $token);
ext_assert($r->status === 201, 'Add YouTube source failed');

// List feed sources
$r = $dispatch('GET', '/api/v1/me/feed-sources', null, $token);
$body = json_decode($r->body, true);
ext_assert($r->status === 200 && count($body['data']['sources']) === 2, 'Expected 2 feed sources');
$youtubeSources = array_values(array_filter($body['data']['sources'], static fn(array $source): bool => $source['provider'] === 'YOUTUBE'));
ext_assert(count($youtubeSources) === 1 && $youtubeSources[0]['source_url'] === 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $youtubeId, 'YouTube URL was not normalized');

// External posts endpoint
$r = $dispatch('GET', '/api/v1/external/posts');
ext_assert($r->status === 200, 'External posts list failed');

// Trigger sync
$r = $dispatch('POST', '/api/v1/me/sync', null, $token);
ext_assert($r->status === 200, 'Trigger sync failed');

// Stats
$r = $dispatch('GET', '/api/v1/me/external/stats', null, $token);
$body = json_decode($r->body, true);
ext_assert($r->status === 200 && isset($body['data']['total_posts']), 'Stats failed');

// HttpClient SSRF protection
$hc = new HttpClient();
try { $hc->get('http://127.0.0.1/'); ext_assert(false, 'Should block 127.0.0.1'); }
catch (\RuntimeException) { ext_assert(true, 'Blocked 127.0.0.1'); }
try { $hc->get('ftp://example.com/'); ext_assert(false, 'Should block FTP'); }
catch (\RuntimeException) { ext_assert(true, 'Blocked FTP'); }

// ExternalPostRepository
$ep = new \App\Repositories\ExternalPostRepository($db);
ext_assert(!$ep->exists('RSS', 'x'), 'Should not exist');
$ep->create(1, 'RSS', 'x', null, 'ARTICLE', null, null, 'Content', null, 'Author', null);
ext_assert($ep->exists('RSS', 'x'), 'Should exist after insert');

// Queue repo
$qr = new \App\Repositories\IntegrationQueueRepository($db);
$qr->enqueue(1, 'RSS', 'sync', []);
ext_assert(count($qr->fetchReady()) === 1, 'Queue should have 1 ready');
$qr->markCompleted(1);
ext_assert($qr->getStats()['completed'] === 1, 'Queue completed');

Database::reset(); unset($db); unlink($envPath); unlink($dbPath);
fwrite(STDOUT, "External content test passed\n");
