<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AnalyticsEventRepository;
use App\Services\Analytics\AnalyticsService;

function ans_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-analytics-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-analytics-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();
$db->exec('CREATE TABLE analytics_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, event_type TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, visitor_hash TEXT, occurred_on TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');

$repo = new AnalyticsEventRepository($db);
$service = new AnalyticsService($repo);

// ---- Test 1: recordProfileView/recordPostView never throw, even on garbage input, and are visible immediately ----
$service->recordProfileView(1, '203.0.113.5', 'TestAgent/1.0');
$service->recordPostView(1, 'post-a', '203.0.113.5', 'TestAgent/1.0');
$service->recordPostView(1, 'post-a', '203.0.113.6', 'OtherAgent/2.0');
$service->recordPostView(1, 'post-b', '203.0.113.5', 'TestAgent/1.0');

$summary = $service->getSummary(1);
ans_assert($summary['profile_views'] === 1, 'profile_views should be 1');
ans_assert($summary['content_views'] === 3, 'content_views should be 3 (post-a x2, post-b x1)');
ans_assert($summary['unique_visitors'] === 2, 'unique_visitors should be 2 distinct (ip, ua) pairs today: ' . json_encode($summary));
ans_assert(count($summary['daily_traffic']) === 7, 'daily_traffic must always have exactly 7 entries');
ans_assert(end($summary['daily_traffic'])['date'] === gmdate('Y-m-d'), "the last daily_traffic entry must be today's UTC date, not shifted by local timezone");
ans_assert($summary['daily_traffic'][0]['unique_visitors'] === 0, 'days with no events should be filled with zero, not omitted');

// ---- Test 2: top_content is ordered by view count, most-viewed first ----
ans_assert($summary['top_content'][0]['subject_public_id'] === 'post-a', 'post-a (2 views) should rank above post-b (1 view): ' . json_encode($summary['top_content']));
ans_assert($summary['top_content'][0]['views'] === 2, 'post-a should report 2 views');

// ---- Test 3: the visitor hash is a privacy-preserving daily rotation, not a bare/reversible IP hash ----
$rows = $db->query('SELECT visitor_hash FROM analytics_events WHERE subject_public_id = "post-a" ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
ans_assert($rows[0] !== $rows[1], 'Different (ip, user agent) pairs must produce different visitor hashes');
ans_assert($rows[0] !== hash('sha256', '203.0.113.5'), 'The hash must not be a bare, unsalted sha256(ip) — that is brute-forceable given how small the IP address space is');
ans_assert(strlen($rows[0]) === 64, 'HMAC-SHA256 output should be a 64-character hex string');

// ---- Test 4: no APP_KEY configured means no visitor hash is stored, rather than an unsalted one ----
$envPath2 = sys_get_temp_dir() . '/fpdp-analytics-test-nokey-' . uniqid() . '.env';
file_put_contents($envPath2, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\n");
Config::load($envPath2);
$service->recordProfileView(2, '203.0.113.9', 'NoKeyAgent/1.0');
$noKeyHash = $db->query('SELECT visitor_hash FROM analytics_events WHERE node_id = 2')->fetchColumn();
ans_assert($noKeyHash === null, 'Without APP_KEY, visitor_hash must be stored as NULL rather than an insecure unsalted hash');
Config::load($envPath); // restore APP_KEY for the rest of this test

// ---- Test 5: trackOutboundClick validates target_url and is recorded distinctly from views ----
$service->trackOutboundClick(1, 'https://shop.example/buy', '203.0.113.5', 'TestAgent/1.0');

$scriptSchemeRejected = false;
try {
    $service->trackOutboundClick(1, 'javascript:alert(1)', '203.0.113.5', null);
} catch (\App\Core\Exceptions\ValidationException) {
    $scriptSchemeRejected = true;
}
ans_assert($scriptSchemeRejected, 'A javascript: URI must be rejected even though FILTER_VALIDATE_URL alone would accept it');

$emptyRejected = false;
try {
    $service->trackOutboundClick(1, '', '203.0.113.5', null);
} catch (\App\Core\Exceptions\ValidationException) {
    $emptyRejected = true;
}
ans_assert($emptyRejected, 'An empty target_url should be rejected');

$tooLongRejected = false;
try {
    $service->trackOutboundClick(1, 'https://example.test/' . str_repeat('x', 2100), '203.0.113.5', null);
} catch (\App\Core\Exceptions\ValidationException) {
    $tooLongRejected = true;
}
ans_assert($tooLongRejected, 'An excessively long target_url should be rejected');

$afterClick = $service->getSummary(1);
ans_assert($afterClick['outbound_clicks'] === 1, 'Only the one successfully-validated click should be counted: ' . json_encode($afterClick['outbound_clicks']));
ans_assert($afterClick['content_views'] === 3, 'Outbound clicks must not be counted as content views');

// ---- Test 6: recordShopConversion is tracked separately from views/clicks ----
$service->recordShopConversion(1, 'order-1');
$finalSummary = $service->getSummary(1);
ans_assert($finalSummary['shop_conversions'] === 1, 'shop_conversions should be 1');

Database::reset();
unset($db);
@unlink($envPath);
@unlink($envPath2);
@unlink($dbPath);
fwrite(STDOUT, "Analytics service test passed\n");
