<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

$dbPath = sys_get_temp_dir() . '/fpdp-bootstrap-test-' . uniqid() . '.sqlite';
foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $dbPath,
    'NODE_DOMAIN' => 'example.test',
    'AUTH_TOKEN_TTL' => '3600',
    'BOOTSTRAP_OWNER_EMAIL' => 'owner@example.test',
    'BOOTSTRAP_OWNER_PASSWORD' => 'a strong bootstrap password',
    'BOOTSTRAP_OWNER_HANDLE' => 'profile',
    'BOOTSTRAP_OWNER_DISPLAY_NAME' => 'Profile Owner',
    'BOOTSTRAP_OWNER_LOCALE' => 'en',
] as $key => $value) {
    putenv($key . '=' . $value);
}

Database::reset();
$db = Database::connection();
foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

require __DIR__ . '/../database/bootstrap-owner.php';

$user = $db->query("SELECT email, role FROM users WHERE email = 'owner@example.test'")->fetch();
$profile = $db->query("SELECT handle, display_name FROM profiles WHERE handle = 'profile'")->fetch();
$node = $db->query("SELECT domain FROM nodes")->fetch();
if ($user === false || $user['role'] !== 'OWNER'
    || $profile === false || $profile['display_name'] !== 'Profile Owner'
    || $node === false || $node['domain'] !== 'example.test') {
    fwrite(STDERR, "Owner bootstrap did not create the expected node context\n");
    exit(1);
}

Database::reset();
unset($db);
@unlink($dbPath);
foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'NODE_DOMAIN', 'AUTH_TOKEN_TTL', 'BOOTSTRAP_OWNER_EMAIL', 'BOOTSTRAP_OWNER_PASSWORD', 'BOOTSTRAP_OWNER_HANDLE', 'BOOTSTRAP_OWNER_DISPLAY_NAME', 'BOOTSTRAP_OWNER_LOCALE'] as $key) {
    putenv($key);
}

fwrite(STDOUT, "Bootstrap owner test passed\n");
