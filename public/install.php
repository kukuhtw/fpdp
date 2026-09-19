<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\ValidationException;
use App\Core\Installer\EnvWriter;
use App\Core\Installer\InstallLock;
use App\Core\Installer\RequirementsChecker;
use App\Core\MigrationRunner;
use App\Repositories\AuthTokenRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;

require_once __DIR__ . '/../vendor/autoload.php';

$rootPath = dirname(__DIR__);
$envPath = $rootPath . '/.env';

/**
 * This installer intentionally does not route through app/routes.php: it
 * must keep working before .env exists and before migrations have run, so
 * it only depends on the classes it needs directly.
 */
function render_page(string $title, string $body): void
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$safeTitle} · FPDP Installer</title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 40px 16px; background: #f4f1eb; color: #17211b; font: 15px/1.6 -apple-system, "Segoe UI", Inter, sans-serif; }
  .card { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #e1ded2; border-radius: 14px; padding: 32px 36px; box-shadow: 0 18px 45px rgba(32,43,36,.08); }
  h1 { font-size: 22px; margin: 0 0 6px; }
  .eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #185f48; margin: 0 0 10px; }
  p.lede { color: #636d65; margin: 0 0 24px; }
  label { display: block; font-size: 12.5px; font-weight: 600; margin: 14px 0 4px; }
  input, select { width: 100%; padding: 9px 11px; border: 1px solid #dedfd8; border-radius: 8px; font-size: 14px; font-family: inherit; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .actions { margin-top: 26px; display: flex; gap: 10px; align-items: center; }
  button, .button { appearance: none; border: 0; border-radius: 8px; padding: 11px 18px; font: 600 14px inherit; cursor: pointer; text-decoration: none; display: inline-block; }
  .button-primary { background: #185f48; color: #fff; }
  .button-secondary { background: #f0efe8; color: #17211b; }
  ul.checklist { list-style: none; padding: 0; margin: 0 0 20px; display: grid; gap: 8px; }
  ul.checklist li { display: flex; gap: 10px; align-items: baseline; padding: 9px 12px; border-radius: 8px; background: #f8f7f2; font-size: 13.5px; }
  .ok { color: #185f48; font-weight: 700; }
  .fail { color: #a13d37; font-weight: 700; }
  .error-box { background: #f6e9e7; border: 1px solid #e3b6ae; color: #7c2d24; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
  .muted { color: #636d65; font-size: 12.5px; }
  code, .token { font-family: ui-monospace, "SF Mono", Consolas, monospace; background: #f0efe8; padding: 2px 6px; border-radius: 5px; font-size: 12.5px; word-break: break-all; }
  .steps { display: flex; gap: 6px; margin-bottom: 22px; }
  .steps span { flex: 1; height: 4px; border-radius: 2px; background: #e1ded2; }
  .steps span.active { background: #185f48; }
</style>
</head>
<body>
  <div class="card">
    {$body}
  </div>
</body>
</html>
HTML;
}

function step_indicator(int $current): string
{
    $html = '<div class="steps">';
    for ($i = 1; $i <= 4; $i++) {
        $html .= '<span' . ($i <= $current ? ' class="active"' : '') . '></span>';
    }

    return $html . '</div>';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
}

/**
 * @param array<string, string> $values
 * @return array<string, string>
 */
function escape_all(array $values): array
{
    return array_map('h', $values);
}

// Returns ' selected' when $current === $value, for building <option> tags.
// Never receives raw user input as output — it only ever emits this fixed
// marker string — so its result is safe to interpolate unescaped.
$sel = static fn (string $current, string $value): string => $current === $value ? ' selected' : '';

// --- Guard: once installed, refuse to run again until the lock file is removed. ---
if (InstallLock::isInstalled($rootPath)) {
    render_page('Already installed', <<<HTML
        <p class="eyebrow">FPDP Installer</p>
        <h1>This node is already installed</h1>
        <p class="lede">A <code>storage/installed.lock</code> file was found, so the installer refuses to run and risk overwriting a live node's configuration.</p>
        <p>To reinstall on purpose (for example on a fresh staging copy), delete <code>storage/installed.lock</code> and reload this page.</p>
        <div class="actions">
          <a class="button button-primary" href="/api/v1/health">Check /api/v1/health</a>
          <a class="button button-secondary" href="/">Go to the site</a>
        </div>
        <p class="muted" style="margin-top:22px">Security reminder: delete or block public access to <code>install.php</code> now that setup is complete.</p>
    HTML);
    exit;
}

$step = $_GET['step'] ?? '1';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($step) {
    case '1':
        $checks = RequirementsChecker::check($rootPath);
        $passed = RequirementsChecker::allPassed($checks);

        $items = '';
        foreach ($checks as $check) {
            $mark = $check['passed'] ? '<span class="ok">OK</span>' : '<span class="fail">FAIL</span>';
            $items .= '<li>' . $mark . '<span>' . h($check['label']) . ' — ' . h($check['detail']) . '</span></li>';
        }

        $continue = $passed
            ? '<a class="button button-primary" href="install.php?step=2">Continue</a>'
            : '<button class="button button-primary" disabled>Fix the items above to continue</button>';

        render_page('Requirements', step_indicator(1) . <<<HTML
            <p class="eyebrow">FPDP Installer · Step 1 of 4</p>
            <h1>Environment check</h1>
            <p class="lede">Confirms PHP, required extensions, and file permissions before anything is written.</p>
            <ul class="checklist">{$items}</ul>
            <div class="actions">{$continue}</div>
        HTML);
        break;

    case '2':
        $errors = [];
        $data = [
            'APP_NAME' => 'FPDP',
            'APP_ENV' => 'production',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'fpdp',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            'DB_CHARSET' => 'utf8mb4',
            'NODE_DOMAIN' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'NODE_NAME' => 'FPDP Node',
            'NODE_DEFAULT_LOCALE' => 'id',
            'NODE_TIMEZONE' => 'Asia/Jakarta',
        ];

        if ($method === 'POST') {
            foreach (array_keys($data) as $key) {
                $data[$key] = $key !== 'DB_PASSWORD'
                    ? trim((string) ($_POST[$key] ?? ''))
                    : (string) ($_POST[$key] ?? '');
            }

            if ($data['APP_NAME'] === '' || $data['NODE_DOMAIN'] === '' || $data['DB_DATABASE'] === '' || $data['DB_USERNAME'] === '') {
                $errors[] = 'Please fill in every required field.';
            }
            if (!in_array($data['APP_ENV'], ['local', 'testing', 'production'], true)) {
                $errors[] = 'APP_ENV must be local, testing, or production.';
            }
            if (!ctype_digit($data['DB_PORT'])) {
                $errors[] = 'DB_PORT must be numeric.';
            }
            if (!in_array($data['NODE_DEFAULT_LOCALE'], ['en', 'id'], true)) {
                $errors[] = 'Default locale must be en or id.';
            }

            if ($errors === []) {
                $connectionError = EnvWriter::testConnection($data);
                if ($connectionError !== null) {
                    $errors[] = 'Could not connect to that database: ' . $connectionError;
                }
            }

            if ($errors === []) {
                EnvWriter::write($envPath, array_merge($data, ['DB_CONNECTION' => 'mysql', 'APP_DEBUG' => 'false', 'APP_VERSION' => '0.1.0']));
                header('Location: install.php?step=3');
                exit;
            }
        }

        $errorBox = $errors === [] ? '' : '<div class="error-box">' . implode('<br>', array_map('h', $errors)) . '</div>';
        $s = escape_all($data);

        render_page('Database & node', step_indicator(2) . <<<HTML
            <p class="eyebrow">FPDP Installer · Step 2 of 4</p>
            <h1>Database and node settings</h1>
            <p class="lede">The database must already exist; the installer creates its tables, not the database itself.</p>
            {$errorBox}
            <form method="post" action="install.php?step=2">
              <div class="row">
                <div><label>App name</label><input name="APP_NAME" value="{$s['APP_NAME']}" required></div>
                <div><label>Environment</label>
                  <select name="APP_ENV">
                    <option value="production"{$sel($data['APP_ENV'], 'production')}>production</option>
                    <option value="local"{$sel($data['APP_ENV'], 'local')}>local</option>
                    <option value="testing"{$sel($data['APP_ENV'], 'testing')}>testing</option>
                  </select>
                </div>
              </div>
              <div class="row">
                <div><label>DB host</label><input name="DB_HOST" value="{$s['DB_HOST']}" required></div>
                <div><label>DB port</label><input name="DB_PORT" value="{$s['DB_PORT']}" required></div>
              </div>
              <label>DB name</label><input name="DB_DATABASE" value="{$s['DB_DATABASE']}" required>
              <div class="row">
                <div><label>DB username</label><input name="DB_USERNAME" value="{$s['DB_USERNAME']}" required></div>
                <div><label>DB password</label><input type="password" name="DB_PASSWORD" value=""></div>
              </div>
              <label>DB charset</label><input name="DB_CHARSET" value="{$s['DB_CHARSET']}">
              <div class="row">
                <div><label>Node domain</label><input name="NODE_DOMAIN" value="{$s['NODE_DOMAIN']}" required></div>
                <div><label>Node display name</label><input name="NODE_NAME" value="{$s['NODE_NAME']}" required></div>
              </div>
              <div class="row">
                <div><label>Default locale</label>
                  <select name="NODE_DEFAULT_LOCALE">
                    <option value="id"{$sel($data['NODE_DEFAULT_LOCALE'], 'id')}>Bahasa Indonesia</option>
                    <option value="en"{$sel($data['NODE_DEFAULT_LOCALE'], 'en')}>English</option>
                  </select>
                </div>
                <div><label>Timezone</label><input name="NODE_TIMEZONE" value="{$s['NODE_TIMEZONE']}"></div>
              </div>
              <div class="actions">
                <a class="button button-secondary" href="install.php?step=1">Back</a>
                <button class="button button-primary" type="submit">Test connection & continue</button>
              </div>
            </form>
        HTML);
        break;

    case '3':
        if (!is_file($envPath)) {
            header('Location: install.php?step=2');
            exit;
        }

        if ($method === 'POST') {
            Config::load($envPath);
            Database::reset();

            try {
                $applied = (new MigrationRunner(Database::connection(), $rootPath . '/database/migrations'))->run();
                $list = $applied === []
                    ? '<p class="muted">No pending migrations — the schema was already up to date.</p>'
                    : '<ul class="checklist">' . implode('', array_map(
                        static fn (string $name): string => '<li><span class="ok">OK</span><span>' . h($name) . '</span></li>',
                        $applied,
                    )) . '</ul>';

                render_page('Migrations applied', step_indicator(3) . <<<HTML
                    <p class="eyebrow">FPDP Installer · Step 3 of 4</p>
                    <h1>Database schema is ready</h1>
                    {$list}
                    <div class="actions">
                      <a class="button button-primary" href="install.php?step=4">Continue</a>
                    </div>
                HTML);
            } catch (\Throwable $e) {
                $message = h($e->getMessage());
                render_page('Migration failed', step_indicator(3) . <<<HTML
                    <p class="eyebrow">FPDP Installer · Step 3 of 4</p>
                    <h1>Migrations failed</h1>
                    <div class="error-box">{$message}</div>
                    <div class="actions">
                      <a class="button button-secondary" href="install.php?step=2">Back to database settings</a>
                      <form method="post" action="install.php?step=3"><button class="button button-primary" type="submit">Retry</button></form>
                    </div>
                HTML);
            }
            break;
        }

        render_page('Run migrations', step_indicator(3) . <<<HTML
            <p class="eyebrow">FPDP Installer · Step 3 of 4</p>
            <h1>Create the database schema</h1>
            <p class="lede">This applies every pending file in <code>database/migrations/</code>, in order. It is safe to run more than once — already-applied migrations are skipped.</p>
            <form method="post" action="install.php?step=3">
              <div class="actions">
                <a class="button button-secondary" href="install.php?step=2">Back</a>
                <button class="button button-primary" type="submit">Run migrations</button>
              </div>
            </form>
        HTML);
        break;

    case '4':
        if (!is_file($envPath)) {
            header('Location: install.php?step=2');
            exit;
        }

        Config::load($envPath);
        Database::reset();

        $errors = [];
        $data = [
            'display_name' => '',
            'handle' => '',
            'email' => '',
            'locale' => Config::get('NODE_DEFAULT_LOCALE', 'id'),
        ];

        if ($method === 'POST') {
            $data['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
            $data['handle'] = trim((string) ($_POST['handle'] ?? ''));
            $data['email'] = trim((string) ($_POST['email'] ?? ''));
            $data['locale'] = (string) ($_POST['locale'] ?? 'id');
            $password = (string) ($_POST['password'] ?? '');

            try {
                $connection = Database::connection();
                $auth = new AuthService(
                    new NodeRepository($connection),
                    new UserRepository($connection),
                    new ProfileRepository($connection),
                    new AuthTokenRepository($connection),
                );

                $result = $auth->register([
                    'display_name' => $data['display_name'],
                    'handle' => $data['handle'],
                    'email' => $data['email'],
                    'password' => $password,
                    'locale' => $data['locale'],
                ]);

                InstallLock::lock($rootPath);

                $token = h((string) $result['token']['access_token']);
                $handle = h($data['handle']);

                render_page('Installation complete', step_indicator(4) . <<<HTML
                    <p class="eyebrow">FPDP Installer · Done</p>
                    <h1>Your node is ready</h1>
                    <p class="lede">Owner account <strong>@{$handle}</strong> was created and the installer is now locked.</p>
                    <label>Access token (shown once — store it securely)</label>
                    <p class="token">{$token}</p>
                    <p class="muted">Use it as <code>Authorization: Bearer &lt;token&gt;</code> against <code>GET /api/v1/me</code>, or discard it and log in again via <code>POST /api/v1/auth/login</code>.</p>
                    <div class="actions">
                      <a class="button button-primary" href="/api/v1/health">Check /api/v1/health</a>
                      <a class="button button-secondary" href="/">Go to the site</a>
                    </div>
                    <p class="muted" style="margin-top:22px">Security reminder: delete or block public access to <code>install.php</code> now.</p>
                HTML);
                exit;
            } catch (ValidationException $e) {
                foreach ($e->getDetails() as $detail) {
                    $errors[] = ($detail['field'] ?? 'field') . ': ' . ($detail['reason'] ?? 'invalid');
                }
            } catch (ConflictException $e) {
                $errors[] = $e->getMessage();
            } catch (\Throwable $e) {
                $errors[] = 'Could not create the owner account: ' . $e->getMessage() . ' (did step 3 finish successfully?)';
            }
        }

        $errorBox = $errors === [] ? '' : '<div class="error-box">' . implode('<br>', array_map('h', $errors)) . '</div>';
        $s = escape_all($data);

        render_page('Owner account', step_indicator(4) . <<<HTML
            <p class="eyebrow">FPDP Installer · Step 4 of 4</p>
            <h1>Create the owner account</h1>
            <p class="lede">This becomes the first user on the node, with the OWNER role.</p>
            {$errorBox}
            <form method="post" action="install.php?step=4">
              <label>Display name</label><input name="display_name" value="{$s['display_name']}" required>
              <label>Handle</label><input name="handle" value="{$s['handle']}" pattern="[a-z0-9][a-z0-9-]{2,62}" required>
              <label>Email</label><input type="email" name="email" value="{$s['email']}" required>
              <label>Password (min. 12 characters)</label><input type="password" name="password" minlength="12" required>
              <label>Locale</label>
              <select name="locale">
                <option value="id"{$sel($data['locale'], 'id')}>Bahasa Indonesia</option>
                <option value="en"{$sel($data['locale'], 'en')}>English</option>
              </select>
              <div class="actions">
                <a class="button button-secondary" href="install.php?step=3">Back</a>
                <button class="button button-primary" type="submit">Create owner account</button>
              </div>
            </form>
        HTML);
        break;

    default:
        header('Location: install.php?step=1');
        exit;
}
