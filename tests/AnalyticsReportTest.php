<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Repositories\AnalyticsEventRepository;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\PageViewTracker;

function ar_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-analytics-report-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-analytics-report-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=kukuhtw.com\nAUTH_TOKEN_TTL=3600\nAPP_KEY=" . bin2hex(random_bytes(32)) . "\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();
foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, enabled_locales TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, user_agent TEXT, ip_hint TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, last_used_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, actor_user_id INTEGER, action TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE analytics_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, event_type TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, visitor_hash TEXT, referrer_host TEXT, occurred_on TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, title TEXT)',
    'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, title TEXT)',
] as $sql) {
    $db->exec($sql);
}

$events = new AnalyticsEventRepository($db);
$analytics = new AnalyticsService($events);
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

// ---- 1. Which paths are public pages ----
$cases = [
    '/' => ['PAGE_VIEW', 'home', null], '/shop' => ['PAGE_VIEW', 'shop', null], '/about' => ['PAGE_VIEW', 'about_fpdp', null],
    '/shop/abc-123' => ['PAGE_VIEW', 'product', 'abc-123'], '/posts/42' => ['POST_VIEW', 'post', '42'],
    '/posts/42-halo-dunia' => ['POST_VIEW', 'post', '42'], '/@kukuh' => ['PROFILE_VIEW', 'profile', null], '/@kukuh/cv' => ['PAGE_VIEW', 'cv', null],
    '/timeline/' => ['PAGE_VIEW', 'timeline', null],
];
foreach ($cases as $path => $expected) {
    ar_assert(PageViewTracker::classify($path) === $expected, "{$path} should classify as " . json_encode($expected) . ', got ' . json_encode(PageViewTracker::classify($path)));
}
foreach (['/dashboard', '/dashboard/analytics', '/api/v1/me', '/assets/app.css', '/documentation/README.md', '/install.php', '/.well-known/webfinger', '/@kukuh/inbox', '/posts/abc', '/payment/thank-you'] as $path) {
    ar_assert(PageViewTracker::classify($path) === null, "{$path} must not count as a page view");
}

// ---- 2. Bots and link previews are not people; in-app browsers are ----
foreach (['', 'Googlebot/2.1', 'http.rb/5.1.1 (Mastodon/4.2.1; +https://mastodon.social/)', 'curl/8.4.0', 'python-requests/2.31', 'facebookexternalhit/1.1', 'WhatsApp/2.23.20', 'Slackbot-LinkExpanding 1.0', 'TelegramBot (like TwitterBot)', 'Mozilla/5.0 (compatible; LinkedInBot/1.0)'] as $bot) {
    ar_assert(PageViewTracker::isBot($bot), "should be a bot: {$bot}");
}
foreach ([$chrome, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 [LinkedInApp]', 'Mozilla/5.0 (Linux; Android 14) Telegram-Android/10.0 Chrome/120 Mobile Safari/537.36'] as $person) {
    ar_assert(!PageViewTracker::isBot($person), "should be a person: {$person}");
}

// ---- 3. Referrer: host only, never our own site ----
$ref = static fn (string $referer, string $host = 'kukuhtw.com'): ?string => PageViewTracker::referrerHost(new Request('GET', '/', [], null, ['referer' => $referer, 'host' => $host]));
ar_assert($ref('https://mastodon.social/@someone/123?utm=x') === 'mastodon.social', 'only the referring host is kept');
ar_assert($ref('https://www.Google.com/search?q=kukuh') === 'google.com', 'www. is dropped and the host lowercased');
ar_assert($ref('https://kukuhtw.com/shop') === null && $ref('https://www.kukuhtw.com/') === null, 'links within the site are not a referrer');
ar_assert($ref('android-app://com.google.android.gm/') === null && $ref('') === null, 'non-web referrers are ignored');

// ---- 4. When a visit is recorded ----
$html = Response::html('<p>hi</p>');
$tracker = new PageViewTracker($analytics, static fn (): ?int => 1);
$count = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM analytics_events')->fetchColumn();
$visit = static fn (string $path, array $headers = [], string $method = 'GET', ?Response $response = null) => $tracker->track(new Request($method, $path, [], null, $headers + ['user-agent' => $chrome, 'host' => 'kukuhtw.com'], '203.0.113.9'), $response ?? $html);

$visit('/shop/abc-123', ['referer' => 'https://mastodon.social/@x/1']);
ar_assert($count() === 1, 'a person viewing a product page is recorded');
$row = $db->query('SELECT * FROM analytics_events')->fetch();
ar_assert($row['event_type'] === 'PAGE_VIEW' && $row['subject_type'] === 'product' && $row['subject_public_id'] === 'abc-123' && $row['referrer_host'] === 'mastodon.social', 'the event records page and referrer host: ' . json_encode($row));
ar_assert($row['visitor_hash'] !== null && !str_contains(json_encode($row), '203.0.113.9'), 'no raw IP is stored');

$visit('/shop', ['user-agent' => 'Googlebot/2.1']);
$visit('/shop', [], 'POST');
$visit('/dashboard/analytics');
$visit('/@kukuh', [], 'GET', Response::activityJson(['id' => 'x']));
$visit('/shop', [], 'GET', Response::html('not found', 404));
$visit('/shop', ['sec-purpose' => 'prefetch']);
ar_assert($count() === 1, 'bots, POSTs, the dashboard, ActivityPub JSON, 404s, and prefetches are not recorded');
(new PageViewTracker($analytics, static fn (): ?int => null))->track(new Request('GET', '/', [], null, ['user-agent' => $chrome]), $html);
ar_assert($count() === 1, 'nothing is recorded before a node exists');

// ---- 5. The report ----
$db->exec('DELETE FROM analytics_events');
$db->exec("INSERT INTO posts (id, public_id, title) VALUES (42, 'post-uuid-42', 'Halo dunia')");
$db->exec("INSERT INTO products (public_id, title) VALUES ('abc-123', 'Kaos')");
$daysAgo = static fn (int $n): string => gmdate('Y-m-d', strtotime("-{$n} days"));
$insert = $db->prepare('INSERT INTO analytics_events (node_id, event_type, subject_type, subject_public_id, visitor_hash, referrer_host, occurred_on) VALUES (1, ?, ?, ?, ?, ?, ?)');
$seed = [
    // today: 2 visitors, 4 page views
    ['PAGE_VIEW', 'home', null, 'v1', 'mastodon.social', 0], ['POST_VIEW', 'post', '42', 'v1', null, 0],
    ['POST_VIEW', 'post', 'post-uuid-42', 'v2', 'google.com', 0], ['PAGE_VIEW', 'product', 'abc-123', 'v2', 'mastodon.social', 0],
    // 3 days ago: 1 visitor, 1 view, 1 outbound click, 1 conversion
    ['PAGE_VIEW', 'shop', null, 'v3', null, 3], ['OUTBOUND_CLICK', 'link', 'https://github.com/kukuhtw', 'v3', null, 3],
    ['SHOP_CONVERSION', 'order', 'o1', null, null, 3],
    // 10 days ago: previous 7-day window
    ['PAGE_VIEW', 'home', null, 'v4', null, 10], ['PAGE_VIEW', 'home', null, 'v5', null, 10],
    // 60 days ago: outside 30, inside 90
    ['PAGE_VIEW', 'home', null, 'v6', null, 60],
];
foreach ($seed as [$type, $subjectType, $subjectId, $hash, $referrer, $ago]) {
    $insert->execute([$type, $subjectType, $subjectId, $hash, $referrer, $daysAgo($ago)]);
}

$week = $analytics->getReport(1, 7);
ar_assert($week['window_days'] === 7 && count($week['daily']) === 7 && end($week['daily'])['date'] === gmdate('Y-m-d'), 'seven daily points ending today');
ar_assert($week['totals'] === ['visits' => 3, 'page_views' => 5, 'outbound_clicks' => 1, 'shop_conversions' => 1], 'last-7-day totals: ' . json_encode($week['totals']));
ar_assert($week['previous'] === ['visits' => 2, 'page_views' => 2, 'outbound_clicks' => 0, 'shop_conversions' => 0], 'previous 7 days: ' . json_encode($week['previous']));
ar_assert(end($week['daily']) === ['date' => gmdate('Y-m-d'), 'visits' => 2, 'page_views' => 4], 'today\'s point: ' . json_encode(end($week['daily'])));

$pages = $week['top_pages'];
$posts = array_values(array_filter($pages, static fn (array $p): bool => $p['kind'] === 'post'));
ar_assert(count($posts) === 2 && $posts[0]['title'] === 'Halo dunia' && $posts[1]['title'] === 'Halo dunia' && $posts[0]['path'] === '/posts/42', 'post views by numeric or public id both resolve to the title and path: ' . json_encode($posts));
$product = array_values(array_filter($pages, static fn (array $p): bool => $p['kind'] === 'product'))[0];
ar_assert($product['title'] === 'Kaos' && $product['path'] === '/shop/abc-123', 'product views carry the product title');
$home = array_values(array_filter($pages, static fn (array $p): bool => $p['kind'] === 'home'))[0];
ar_assert($home['path'] === '/' && $home['page_views'] === 1, 'the home page in the last 7 days');
ar_assert($week['referrers'][0] === ['host' => 'mastodon.social', 'page_views' => 2, 'visits' => 2] && count($week['referrers']) === 2, 'referrers: ' . json_encode($week['referrers']));
ar_assert($week['outbound'] === [['url' => 'https://github.com/kukuhtw', 'host' => 'github.com', 'clicks' => 1]], 'outbound links: ' . json_encode($week['outbound']));

ar_assert($analytics->getReport(1, 30)['totals']['page_views'] === 7 && $analytics->getReport(1, 90)['totals']['page_views'] === 8, 'longer windows include older views');
$rejected = false;
try {
    $analytics->getReport(1, 5);
} catch (ValidationException) {
    $rejected = true;
}
ar_assert($rejected, 'only 7, 30, or 90 days are allowed');

// ---- 6. The endpoints ----
/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$call = static function (string $method, string $path, ?array $body = null, ?string $token = null, array $query = [], string $ip = '198.51.100.1') use ($router): array {
    $headers = ['user-agent' => 'Mozilla/5.0 Chrome/129'];
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    $response = $router->dispatch(new Request($method, $path, $query, $body === null ? null : json_encode($body), $headers, $ip));

    return ['status' => $response->status, 'body' => json_decode($response->body, true)];
};
$token = $call('POST', '/api/v1/auth/register', ['email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner'])['body']['data']['token']['access_token'];
$db->exec('UPDATE analytics_events SET node_id = (SELECT id FROM nodes LIMIT 1)');
ar_assert($call('GET', '/api/v1/me/analytics', null, null, ['days' => '7'])['status'] === 401, 'the report needs login');
$report = $call('GET', '/api/v1/me/analytics', null, $token, ['days' => '7']);
ar_assert($report['status'] === 200 && $report['body']['data']['totals']['page_views'] === 5, 'the owner gets the report: ' . json_encode($report['body']['data']['totals'] ?? $report));
ar_assert($call('GET', '/api/v1/me/analytics', null, $token, ['days' => '365'])['status'] === 422, 'an unsupported range is refused');

$click = $call('POST', '/api/v1/track/outbound-click', ['target_url' => 'https://example.org/page']);
ar_assert($click['status'] === 202, 'a public outbound click is accepted: ' . json_encode($click));
ar_assert($call('POST', '/api/v1/track/outbound-click', ['target_url' => 'javascript:alert(1)'])['status'] === 422, 'a javascript: URL is refused');
$limited = null;
for ($i = 0; $i < 70; $i++) {
    $status = $call('POST', '/api/v1/track/outbound-click', ['target_url' => 'https://example.org/' . $i], null, [], '198.51.100.77')['status'];
    if ($status === 429) {
        $limited = $i;
        break;
    }
}
ar_assert($limited === 60, 'outbound clicks are limited to 60 per 10 minutes per IP, got the 429 at request ' . var_export($limited, true));

// ---- 7. Retention ----
$before = $count();
$deleted = $analytics->pruneOlderThan(30);
ar_assert($deleted === 1 && $count() === $before - 1, 'events older than the retention window are deleted (the one from 60 days ago)');

unset($router, $call, $tracker, $analytics, $events, $db);
Database::reset();
gc_collect_cycles();
@unlink($envPath);
@unlink($dbPath);

fwrite(STDOUT, "Analytics report test passed\n");
