<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\I18n;
use App\Core\Router;
use App\Core\View;

function i18n_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$resolve = static function (array $query = [], array $headers = [], array $settings = ['default' => 'id', 'enabled' => 'id,en'], bool $secure = true): string {
    I18n::useRequest(new Request('GET', '/', $query, null, $headers, '203.0.113.1', $secure), static fn (): array => $settings);

    return I18n::locale();
};

// ---- 1. Resolution order: ?lang, cookie, Accept-Language, node default ----
i18n_assert($resolve() === 'id', 'no hints should give the node default');
i18n_assert($resolve([], ['accept-language' => 'en-US,en;q=0.9,id;q=0.5']) === 'en', 'Accept-Language en-US should give en');
i18n_assert($resolve([], ['accept-language' => 'fr-FR, en;q=0.5']) === 'en', 'an unsupported first choice should fall through to the next one');
i18n_assert($resolve([], ['accept-language' => 'fr, de;q=0.8']) === 'id', 'no supported browser language should give the default');
i18n_assert($resolve([], ['accept-language' => 'en;q=0.2, id;q=0.9']) === 'id', 'quality values decide, not order');
i18n_assert($resolve([], ['cookie' => 'other=1; fpdp_lang=en', 'accept-language' => 'id']) === 'en', 'the cookie beats Accept-Language');
i18n_assert(I18n::pendingCookie() === null, 'reading the cookie must not set a new one');

i18n_assert($resolve(['lang' => 'en'], ['cookie' => 'fpdp_lang=id']) === 'en', '?lang beats the cookie');
$cookie = (string) I18n::pendingCookie();
i18n_assert(str_starts_with($cookie, 'fpdp_lang=en;') && str_contains($cookie, 'SameSite=Lax') && str_contains($cookie, 'Secure'), '?lang should remember the choice in a Secure cookie: ' . $cookie);
$resolve(['lang' => 'en'], [], ['default' => 'id', 'enabled' => 'id,en'], false);
i18n_assert(!str_contains((string) I18n::pendingCookie(), 'Secure'), 'no Secure flag over plain http');
i18n_assert($resolve(['lang' => 'fr']) === 'id' && I18n::pendingCookie() === null, 'an unsupported ?lang is ignored');
i18n_assert($resolve(['lang' => 'EN']) === 'en', '?lang is case-insensitive');

// ---- 2. Only the languages the owner enabled ----
$onlyId = ['default' => 'id', 'enabled' => 'id'];
i18n_assert($resolve(['lang' => 'en'], [], $onlyId) === 'id', '?lang for a disabled language is ignored');
i18n_assert($resolve([], ['cookie' => 'fpdp_lang=en', 'accept-language' => 'en'], $onlyId) === 'id', 'cookie and browser language are ignored when disabled');
i18n_assert($resolve([], [], ['default' => 'en', 'enabled' => 'en']) === 'en', 'an English-only node opens in English');
i18n_assert($resolve([], [], ['default' => 'xx', 'enabled' => 'zz']) === 'id', 'invalid settings fall back to the built-in default');
I18n::useRequest(new Request('GET', '/'), static function (): array {
    throw new RuntimeException('no database yet');
});
i18n_assert(I18n::locale() === 'id' && I18n::enabledLocales() === ['id', 'en'], 'a failing settings lookup (fresh install) uses the defaults');

// ---- 3. Translation, fallback, placeholders, JS catalog ----
I18n::setLocale('en');
i18n_assert(View::t('nav.shop') === 'Shop' && View::te('nav.cv') === 'CV &amp; Resume', 'English strings, escaped by te()');
i18n_assert(View::t('js.common.request_failed', ['status' => 404]) === 'Request failed (404)', 'placeholders are filled');
i18n_assert(View::t('no.such.key') === 'no.such.key', 'a missing key prints the key instead of breaking the page');
I18n::setLocale('id');
i18n_assert(View::t('nav.shop') === 'Toko', 'Indonesian strings');
$js = I18n::jsCatalog();
i18n_assert(isset($js['common.loading']) && !isset($js['nav.shop']) && !array_key_exists('js.common.loading', $js), 'the JS catalog holds only js.* keys, without the prefix');
$head = View::i18nHead();
i18n_assert(str_contains($head, 'hreflang="en"') && str_contains($head, 'window.FPDP_LOCALE="id"') && str_contains($head, 'window.fpdpT='), 'the head carries hreflang links, the locale, and the JS helper: ' . $head);
i18n_assert(!str_contains($head, '</script><script') || substr_count($head, '<script>') === 1, 'exactly one inline script');

// ---- 4. Settings validation ----
i18n_assert(I18n::validateSettings('id', ['id', 'en']) === [], 'a valid setting passes');
i18n_assert(I18n::validateSettings('en', ['id']) !== [], 'the default must be enabled');
i18n_assert(I18n::validateSettings('id', []) !== [], 'at least one language must be enabled');
i18n_assert(I18n::validateSettings('id', ['id', 'fr']) !== [], 'unsupported languages are refused');
i18n_assert(I18n::normalizeSettings(['default' => 'en', 'enabled' => ' en , id ']) === ['default' => 'en', 'enabled' => ['id', 'en']], 'a stored comma list is parsed and ordered');

// ---- 5. The owner's API ----
$dbPath = sys_get_temp_dir() . '/fpdp-i18n-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-i18n-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\n");
Config::load($envPath);
Database::reset();
I18n::reset();
$db = Database::connection();
foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, enabled_locales TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", active_gateway TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, user_agent TEXT, ip_hint TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, last_used_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, actor_user_id INTEGER, action TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}
/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';
$call = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($router): array {
    $headers = $token === null ? [] : ['authorization' => 'Bearer ' . $token];
    $response = $router->dispatch(new Request($method, $path, [], $body === null ? null : json_encode($body), $headers));

    return ['status' => $response->status, 'body' => json_decode($response->body, true)];
};
$token = $call('POST', '/api/v1/auth/register', ['email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner', 'locale' => 'id'])['body']['data']['token']['access_token'];

i18n_assert($call('GET', '/api/v1/me/locale-settings')['status'] === 401, 'the settings need login');
$settings = $call('GET', '/api/v1/me/locale-settings', null, $token);
i18n_assert($settings['status'] === 200 && $settings['body']['data']['default_locale'] === 'id' && $settings['body']['data']['enabled_locales'] === ['id', 'en'], 'a new node defaults to Indonesian with both languages: ' . json_encode($settings));
i18n_assert(count($settings['body']['data']['supported_locales']) === 2, 'the supported languages are listed');

$updated = $call('PATCH', '/api/v1/me/locale-settings', ['default_locale' => 'en', 'enabled_locales' => ['en', 'id', 'en']], $token);
i18n_assert($updated['status'] === 200 && $updated['body']['data']['default_locale'] === 'en' && $updated['body']['data']['enabled_locales'] === ['id', 'en'], 'settings should save, deduplicated and ordered: ' . json_encode($updated));
i18n_assert($db->query('SELECT enabled_locales FROM nodes')->fetchColumn() === 'id,en', 'stored as a comma list');
foreach ([
    ['default_locale' => 'fr', 'enabled_locales' => ['id']],
    ['default_locale' => 'id', 'enabled_locales' => []],
    ['default_locale' => 'id', 'enabled_locales' => ['en']],
    ['default_locale' => 'id', 'enabled_locales' => 'id,en'],
    ['default_locale' => 'id', 'enabled_locales' => ['id', 'xx']],
] as $bad) {
    i18n_assert($call('PATCH', '/api/v1/me/locale-settings', $bad, $token)['status'] === 422, 'an invalid setting should be refused: ' . json_encode($bad));
}
i18n_assert($db->query("SELECT COUNT(*) FROM audit_events WHERE action = 'node.locale_updated'")->fetchColumn() == 1, 'a saved change is audited once');

// ---- 6. The switcher follows the node's settings ----
$nav = static function (array $query) use ($db): string {
    $node = $db->query('SELECT default_locale, enabled_locales FROM nodes')->fetch();
    I18n::useRequest(new Request('GET', '/shop', $query), static fn (): array => ['default' => $node['default_locale'], 'enabled' => $node['enabled_locales']]);
    ob_start();
    View::partial('topnav');

    return (string) ob_get_clean();
};
$html = $nav([]);
i18n_assert(str_contains($html, '>Shop<') && str_contains($html, 'class="lang-switch"') && str_contains($html, 'hreflang="id"'), 'the English default shows English labels and a switcher');
i18n_assert(str_contains($nav(['lang' => 'id']), '>Toko<'), '?lang=id switches the labels to Indonesian');
$call('PATCH', '/api/v1/me/locale-settings', ['default_locale' => 'id', 'enabled_locales' => ['id']], $token);
$single = $nav(['lang' => 'en']);
i18n_assert(str_contains($single, '>Toko<') && !str_contains($single, 'lang-switch'), 'with one language there is no switcher and ?lang=en is ignored');

I18n::reset();
unset($router, $call, $db);
Database::reset();
gc_collect_cycles();
@unlink($envPath);
@unlink($dbPath);

fwrite(STDOUT, "I18n test passed\n");
