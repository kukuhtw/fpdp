#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Emergency: turn off two-factor authentication for an account, for when the
 * owner has lost both the phone and the recovery codes. Needs shell access
 * to the server, which is the proof of ownership here. The owner can sign in
 * with the password afterwards and set up 2FA again. Written to the audit
 * trail as user.2fa_disabled (via: cli).
 *
 * Usage:  php scripts/disable-2fa.php --email=owner@example.com
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AuditEventRepository;
use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Services\Security\AuditService;

Config::load(dirname(__DIR__) . '/.env');

$options = getopt('', ['email:']);
$email = strtolower(trim((string) ($options['email'] ?? '')));
if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/disable-2fa.php --email=owner@example.com\n");
    exit(1);
}

$connection = Database::connection();
$user = (new UserRepository($connection))->findByEmail($email);
if ($user === null) {
    fwrite(STDERR, "No account with that email.\n");
    exit(1);
}
if (($user['totp_enabled_at'] ?? null) === null) {
    fwrite(STDOUT, "Two-factor authentication is already off for {$email}.\n");
    exit(0);
}

(new TwoFactorRepository($connection))->disable((int) $user['id']);
(new AuditService(new AuditEventRepository($connection)))->record(
    ['user' => $user, 'node' => ['id' => $user['node_id']]],
    'user.2fa_disabled',
    'user',
    (string) $user['public_id'],
    ['via' => 'cli'],
);

fwrite(STDOUT, "Two-factor authentication is now off for {$email}. Sign in with the password and set it up again under Settings → Keamanan akun.\n");
