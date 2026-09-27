#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FPDP server check — run on the server (as the web user) before going live
 * and after every upgrade:
 *
 *   sudo -u www-data php scripts/check-requirements.php
 *   sudo -u www-data php scripts/check-requirements.php --http   # also calls the public site
 *
 * Checks PHP version and extensions, upload limits, RSA key generation
 * (federation signing), .env production settings, the database and pending
 * migrations, and that storage/ is writable. With --http it also fetches
 * /api/v1/health and /.well-known/webfinger over HTTPS, which is how other
 * fediverse servers find this node. Exit code 1 if anything FAILs.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Federation\NodeKeyService;

$root = dirname(__DIR__);
$checkHttp = in_array('--http', $argv, true);
$failures = 0;

$report = static function (string $status, string $label, string $detail = '') use (&$failures): void {
    if ($status === 'FAIL') {
        $failures++;
    }
    fwrite(STDOUT, sprintf("[%-4s] %s%s\n", $status, $label, $detail !== '' ? " — {$detail}" : ''));
};
$bytes = static function (string $value): int {
    $value = trim($value);
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
};

// ---- PHP ----
$report(version_compare(PHP_VERSION, '8.2.0', '>=') ? 'OK' : 'FAIL', 'PHP ' . PHP_VERSION, 'FPDP needs 8.2 or newer');
foreach (['pdo_mysql', 'mbstring', 'simplexml', 'json', 'openssl', 'fileinfo'] as $extension) {
    $report(extension_loaded($extension) ? 'OK' : 'FAIL', "extension {$extension}", extension_loaded($extension) ? '' : "install php-" . ($extension === 'pdo_mysql' ? 'mysql' : ($extension === 'simplexml' ? 'xml' : $extension)));
}
$postMax = $bytes((string) ini_get('post_max_size'));
$report($postMax >= 32 * 1024 ** 2 ? 'OK' : 'WARN', 'post_max_size = ' . ini_get('post_max_size'), 'uploads need 32M (see deploy/ubuntu/php-fpdp.ini); this is the CLI value, check PHP-FPM too');
$memory = (string) ini_get('memory_limit');
$report($memory === '-1' || $bytes($memory) >= 256 * 1024 ** 2 ? 'OK' : 'WARN', "memory_limit = {$memory}", 'large uploads need 256M');

try {
    NodeKeyService::createRsaKeyPair(2048);
    $report('OK', 'RSA key generation (federation signing)');
} catch (Throwable $e) {
    $report('FAIL', 'RSA key generation (federation signing)', $e->getMessage());
}

// ---- .env ----
$envPath = $root . '/.env';
if (!is_file($envPath)) {
    $report('FAIL', '.env', 'missing — copy .env.example and fill it in');
    exit(1);
}
$permissions = fileperms($envPath) & 0777;
$report(($permissions & 0077) === 0 ? 'OK' : 'WARN', sprintf('.env permissions %o', $permissions), 'should be 600 (chmod 600 .env)');
Config::load($envPath);
$report(Config::get('APP_ENV') === 'production' ? 'OK' : 'FAIL', 'APP_ENV = ' . Config::get('APP_ENV', '(unset)'), 'must be production');
$report(in_array(strtolower((string) Config::get('APP_DEBUG', 'false')), ['false', '0', ''], true) ? 'OK' : 'FAIL', 'APP_DEBUG = ' . Config::get('APP_DEBUG', '(unset)'), 'must be false');
$appKey = (string) Config::get('APP_KEY', '');
$report(strlen($appKey) >= 64 && !str_starts_with($appKey, 'replace-with') ? 'OK' : 'FAIL', 'APP_KEY', 'needs 64+ random characters: php -r "echo bin2hex(random_bytes(32));"');
$domain = (string) Config::get('NODE_DOMAIN', '');
$report($domain !== '' && $domain !== 'localhost' && !str_contains($domain, '/') ? 'OK' : 'FAIL', "NODE_DOMAIN = {$domain}", 'the bare public domain, e.g. kukuhtw.com');

// ---- Database ----
try {
    $db = Database::connection();
    $report('OK', 'database connection');
    $applied = [];
    try {
        $applied = $db->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        // No migrations table yet: everything is pending.
    }
    $pending = array_diff(array_map('basename', glob($root . '/database/migrations/*.sql') ?: []), $applied);
    $report($pending === [] ? 'OK' : 'FAIL', 'migrations', $pending === [] ? 'all applied' : count($pending) . ' pending — run: php database/migrate.php');
} catch (Throwable $e) {
    $report('FAIL', 'database connection', $e->getMessage());
}

// ---- Storage ----
foreach (['storage', 'storage/cv', 'storage/media', 'storage/products'] as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) {
        $report($directory === 'storage' ? 'FAIL' : 'OK', "{$directory}/", $directory === 'storage' ? 'missing' : 'created on first upload');
        continue;
    }
    $report(is_writable($path) ? 'OK' : 'FAIL', "{$directory}/ writable", is_writable($path) ? '' : 'chown -R www-data:www-data storage');
}
// Two-factor codes depend on the clock: more than ~30 seconds off and the
// owner's authenticator codes stop being accepted.
$ntp = function_exists('shell_exec') && PHP_OS_FAMILY === 'Linux' ? trim((string) @shell_exec('timedatectl show -p NTPSynchronized --value 2>/dev/null')) : '';
if ($ntp === 'yes') {
    $report('OK', 'clock synchronized (NTP)');
} elseif ($ntp === 'no') {
    $report('WARN', 'clock not synchronized', '2FA codes need an accurate clock: sudo timedatectl set-ntp true');
} else {
    $report('WARN', 'clock sync unknown', 'could not run timedatectl; make sure NTP is on (2FA codes need an accurate clock)');
}

$report(is_file($root . '/public/install.php') && !is_file($root . '/storage/installed.lock') ? 'WARN' : 'OK', 'web installer locked', 'touch storage/installed.lock (or delete public/install.php)');

// ---- Public endpoints ----
if ($checkHttp && $domain !== '') {
    $fetch = static function (string $url): array {
        $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $context);
        preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $match);

        return [(int) ($match[1] ?? 0), (string) $body];
    };
    [$status] = $fetch("https://{$domain}/api/v1/health");
    $report($status === 200 ? 'OK' : 'FAIL', "https://{$domain}/api/v1/health", "HTTP {$status}");

    $handle = null;
    try {
        $handle = Database::connection()->query('SELECT handle FROM profiles ORDER BY id LIMIT 1')->fetchColumn() ?: null;
    } catch (Throwable) {
    }
    if ($handle === null) {
        $report('WARN', 'WebFinger', 'no owner profile yet');
    } else {
        [$status, $body] = $fetch("https://{$domain}/.well-known/webfinger?resource=" . rawurlencode("acct:{$handle}@{$domain}"));
        $ok = $status === 200 && str_contains($body, 'application/activity+json');
        $report($ok ? 'OK' : 'FAIL', "WebFinger for @{$handle}@{$domain}", $ok ? '' : "HTTP {$status} — fediverse servers can't find this node (is /.well-known blocked by the web server?)");
    }
    [$status] = $fetch("https://{$domain}/.env");
    $report($status === 200 ? 'FAIL' : 'OK', "https://{$domain}/.env is not served", "HTTP {$status}");
}

fwrite(STDOUT, $failures === 0 ? "\nAll required checks passed.\n" : "\n{$failures} check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
