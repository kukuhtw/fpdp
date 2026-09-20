<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

function home_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-home-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-home-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();
foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY, public_id TEXT, domain TEXT, name TEXT, default_locale TEXT, timezone TEXT, status TEXT, created_at TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY, public_id TEXT, node_id INTEGER, email TEXT, password_hash TEXT, role TEXT, status TEXT, created_at TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY, public_id TEXT, user_id INTEGER, handle TEXT, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT, links TEXT, created_at TIMESTAMP, updated_at TIMESTAMP)',
    'CREATE TABLE posts (id INTEGER PRIMARY KEY, public_id TEXT, user_id INTEGER, profile_id INTEGER, title TEXT, content TEXT, post_type TEXT, visibility TEXT, published_at TIMESTAMP, deleted_at TIMESTAMP, created_at TIMESTAMP, updated_at TIMESTAMP)',
    'CREATE TABLE post_media (id INTEGER PRIMARY KEY, post_id INTEGER, media_type TEXT, url TEXT, alt_text TEXT, sort_order INTEGER, created_at TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

// Before a node/owner exists, "/" must fall back to the static placeholder.
$beforeOwner = $router->dispatch(new Request('GET', '/'));
home_assert(
    $beforeOwner->status === 200 && str_contains($beforeOwner->body, 'FPDP') && str_contains($beforeOwner->body, 'Personal Digital Home'),
    'Home should render the placeholder before a node/owner exists',
);

$db->exec("INSERT INTO nodes VALUES (1, 'node-1', 'test.local', 'Test Node', 'en', 'UTC', 'ACTIVE', CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO users VALUES (1, 'user-1', 1, 'owner@example.com', 'hash', 'OWNER', 'ACTIVE', CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO profiles VALUES (1, 'profile-1', 1, 'owner', 'Node Owner', NULL, NULL, 'PUBLIC', '[]', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

// Once the owner has a public profile, "/" must render it instead of the placeholder.
$withOwner = $router->dispatch(new Request('GET', '/'));
home_assert(
    $withOwner->status === 200 && str_contains($withOwner->body, 'Node Owner') && str_contains($withOwner->body, '@owner'),
    'Home should render the owner public profile once one exists',
);
home_assert(
    !str_contains($withOwner->body, 'Your domain becomes your digital home.'),
    'Home should no longer show the placeholder copy once an owner profile is public',
);

$db->exec("UPDATE profiles SET visibility = 'PRIVATE' WHERE id = 1");
$privateOwner = $router->dispatch(new Request('GET', '/'));
home_assert(
    $privateOwner->status === 200 && str_contains($privateOwner->body, 'Personal Digital Home'),
    'Home should fall back to the placeholder when the owner profile is not public',
);

Database::reset();
unset($db);
unlink($envPath);
unlink($dbPath);

fwrite(STDOUT, "MVC home render passed\n");
