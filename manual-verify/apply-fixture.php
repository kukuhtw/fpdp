<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Core\Config;
use App\Core\Database;
use App\Core\Uuid;
Config::load(__DIR__ . '/manual.env');
Database::reset();
$pdo = Database::connection();
$pdo->exec('CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, domain TEXT NOT NULL UNIQUE, name TEXT NOT NULL, status TEXT NOT NULL DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, node_id INTEGER NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "OWNER", status TEXT NOT NULL DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL UNIQUE, handle TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL, bio TEXT, avatar_url TEXT, visibility TEXT NOT NULL DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE visitor_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, node_id INTEGER NOT NULL, google_sub TEXT NOT NULL, email TEXT NOT NULL, display_name TEXT, avatar_url TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (node_id, google_sub))');
$pdo->exec('CREATE TABLE visitor_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, visitor_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, expires_at TIMESTAMP NOT NULL, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');

$pdo->exec("INSERT INTO nodes (public_id, domain, name) VALUES ('" . Uuid::v4() . "', 'alice.localtest.dev', 'Alice Node')");
$nodeId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO users (public_id, node_id, email, password_hash) VALUES ('" . Uuid::v4() . "', {$nodeId}, 'alice@example.com', 'hash')");
$userId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO profiles (public_id, user_id, handle, display_name) VALUES ('" . Uuid::v4() . "', {$userId}, 'alice', 'Alice Owner')");

echo "fixture applied\n";
