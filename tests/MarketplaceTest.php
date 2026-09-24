<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;

function mp_assert(bool $c, string $msg): void { if (!$c) { fwrite(STDERR, "FAIL: {$msg}\n"); exit(1); } }

$db = sys_get_temp_dir() . '/fpdp-mp-test-' . uniqid() . '.sqlite';
$env = sys_get_temp_dir() . '/fpdp-mp-test-' . uniqid() . '.env';
file_put_contents($env, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$db}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\n");
Config::load($env);
Database::reset();
$conn = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, title TEXT, description TEXT, price TEXT DEFAULT "0", currency TEXT DEFAULT "IDR", product_type TEXT DEFAULT "PHYSICAL", digital_asset_url TEXT, digital_asset_metadata TEXT, status TEXT DEFAULT "ACTIVE", visibility TEXT DEFAULT "PUBLIC", is_promoted INTEGER DEFAULT 0, media TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, visitor_id INTEGER, buyer_email TEXT, buyer_name TEXT, status TEXT DEFAULT "PENDING", total_amount TEXT DEFAULT "0", currency TEXT DEFAULT "IDR", notes TEXT, shipping_address TEXT, payment_reference TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, product_snapshot TEXT, quantity INTEGER DEFAULT 1, unit_price TEXT DEFAULT "0", subtotal TEXT DEFAULT "0", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) { $conn->exec($sql); }

$router = require __DIR__ . '/../app/routes.php';
$d = function(string $m, string $p, ?array $b = null, ?string $t = null, array $q = []) use ($router): array {
    $h = $t === null ? [] : ['authorization' => 'Bearer ' . $t];
    $r = $router->dispatch(new Request($m, $p, $q, $b === null ? null : json_encode($b), $h));
    return ['s' => $r->status, 'b' => $r->body === '' ? null : json_decode($r->body, true)];
};

// Register
$reg = $d('POST', '/api/v1/auth/register', ['email' => 'shop@t.local', 'password' => 'correct horse battery', 'handle' => 'shop', 'display_name' => 'Shop']);
mp_assert($reg['s'] === 201, 'Register failed');
$t = $reg['b']['data']['token']['access_token'];

// Create product
$p = $d('POST', '/api/v1/products', ['title' => 'T-Shirt', 'description' => 'Cool shirt', 'price' => 150000, 'currency' => 'IDR'], $t);
mp_assert($p['s'] === 201, 'Create product failed');
mp_assert($p['b']['data']['title'] === 'T-Shirt', 'Product title mismatch');
$pid = $p['b']['data']['public_id'];

// Get product
$pg = $d('GET', "/api/v1/products/{$pid}");
mp_assert($pg['s'] === 200, 'Get product failed');
mp_assert($pg['b']['data']['price'] == 150000, 'Product price mismatch');

// Update product
$pu = $d('PATCH', "/api/v1/products/{$pid}", ['price' => 175000], $t);
mp_assert($pu['s'] === 200, 'Update product failed');
mp_assert($pu['b']['data']['price'] == 175000, 'Update price mismatch');

// List products
$pl = $d('GET', '/api/v1/products', null, $t);
mp_assert($pl['s'] === 200 && count($pl['b']['data']) === 1, 'List products failed');

// Validation: empty title
$pe = $d('POST', '/api/v1/products', ['price' => 100], $t);
mp_assert($pe['s'] === 422, 'Empty title should 422');

// Create order
$o = $d('POST', '/api/v1/orders', ['items' => [['product_id' => $pid, 'quantity' => 2]], 'shipping_address' => 'Jl. Contoh No. 1, Jakarta'], $t);
mp_assert($o['s'] === 201, 'Create order failed');
mp_assert($o['b']['data']['status'] === 'PENDING', 'Order status should be PENDING');
mp_assert((float) $o['b']['data']['total_amount'] > 0, 'Order should have total');
$oid = $o['b']['data']['public_id'];

// Get order
$og = $d('GET', "/api/v1/orders/{$oid}");
mp_assert($og['s'] === 200, 'Get order failed');
mp_assert(isset($og['b']['data']['items'][0]), 'Order should have items');

// Update order status
$os = $d('PATCH', "/api/v1/orders/{$oid}/status", ['status' => 'COMPLETED'], $t);
mp_assert($os['s'] === 200 && $os['b']['data']['status'] === 'COMPLETED', 'Order status update failed');

// List orders
$ol = $d('GET', '/api/v1/orders', null, $t);
mp_assert($ol['s'] === 200 && count($ol['b']['data']) === 1, 'List orders failed');

// Invalid order status
$oi = $d('PATCH', "/api/v1/orders/{$oid}/status", ['status' => 'INVALID'], $t);
mp_assert($oi['s'] === 422, 'Invalid status should 422');

// Non-existent product in order
$oe = $d('POST', '/api/v1/orders', ['items' => [['product_id' => 'nonexistent', 'quantity' => 1]]], $t);
mp_assert($oe['s'] === 422, 'Non-existent product should 422');

Database::reset(); unset($conn); unlink($env); @unlink($db);
fwrite(STDOUT, "Marketplace test passed\n");