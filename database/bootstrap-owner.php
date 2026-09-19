<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AuthTokenRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;

Config::load(__DIR__ . '/../.env');

$email = trim((string) Config::get('BOOTSTRAP_OWNER_EMAIL', ''));
if ($email === '') {
    fwrite(STDOUT, "Owner bootstrap skipped: BOOTSTRAP_OWNER_EMAIL is empty.\n");
    exit(0);
}

$password = (string) Config::get('BOOTSTRAP_OWNER_PASSWORD', '');
$handle = trim((string) Config::get('BOOTSTRAP_OWNER_HANDLE', ''));
$displayName = trim((string) Config::get('BOOTSTRAP_OWNER_DISPLAY_NAME', ''));
$locale = (string) Config::get('BOOTSTRAP_OWNER_LOCALE', 'id');

if ($password === '' || $handle === '' || $displayName === '') {
    fwrite(STDERR, "Owner bootstrap requires BOOTSTRAP_OWNER_PASSWORD, BOOTSTRAP_OWNER_HANDLE, and BOOTSTRAP_OWNER_DISPLAY_NAME.\n");
    exit(1);
}

$connection = Database::connection();
$users = new UserRepository($connection);
if ($users->findByEmail(strtolower($email)) !== null) {
    fwrite(STDOUT, "Owner bootstrap skipped: account already exists.\n");
    exit(0);
}

$service = new AuthService(
    new NodeRepository($connection),
    $users,
    new ProfileRepository($connection),
    new AuthTokenRepository($connection),
);

$service->register([
    'email' => $email,
    'password' => $password,
    'handle' => $handle,
    'display_name' => $displayName,
    'locale' => $locale,
]);

fwrite(STDOUT, "Owner bootstrap completed. Remove BOOTSTRAP_OWNER_PASSWORD from Dokploy and redeploy.\n");
