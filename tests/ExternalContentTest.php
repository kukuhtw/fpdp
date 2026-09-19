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
] as $sql) {
    $db->exec($sql);
}