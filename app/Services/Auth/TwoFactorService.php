<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Exceptions\ValidationException;
use App\Repositories\AuthTokenRepository;
use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Services\Security\AuditService;

/**
 * Optional TOTP two-factor authentication for the owner account (works with
 * Google Authenticator and every other TOTP app):
 *
 * - setup: start() stores a pending secret and returns it with the
 *   otpauth:// URI for the QR code; confirm() turns it on once the owner
 *   types a code from the app, and returns ten one-time recovery codes
 *   (shown once, stored only as password hashes);
 * - login: AuthService::login() hands back a short-lived challenge instead
 *   of a session; consumeChallenge() exchanges it plus a code or recovery
 *   code for the user (5 tries, 5 minutes);
 * - disable() needs the password and a code; the CLI script
 *   scripts/disable-2fa.php is the last resort for a lost phone.
 *
 * A code is accepted once (the last accepted 30-second step is stored), the
 * secret is encrypted with APP_KEY, and every change is audited.
 */
final class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 10;
    /** No 0/o, 1/l/i: recovery codes get read off paper. */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private const CHALLENGE_TTL_SECONDS = 300;
    private const CHALLENGE_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TwoFactorRepository $twoFactor,
        private readonly UserRepository $users,
        private readonly AuthTokenRepository $tokens,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function isEnabled(array $user): bool
    {
        return ($user['totp_enabled_at'] ?? null) !== null && ($user['totp_secret'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $context from AuthService::authenticate()
     * @return array{enabled: bool, enabled_at: string|null, recovery_codes_remaining: int}
     */
    public function status(array $context): array
    {
        $user = $this->freshUser($context);

        return [
            'enabled' => self::isEnabled($user),
            'enabled_at' => $user['totp_enabled_at'] ?? null,
            'recovery_codes_remaining' => self::isEnabled($user) ? count($this->twoFactor->unusedRecoveryCodes((int) $user['id'])) : 0,
        ];
    }

    /**
     * Step 1 of setup: a new secret to scan. Replaces any unfinished setup.
     *
     * @param array<string, mixed> $context
     * @return array{secret: string, otpauth_uri: string}
     */
    public function start(array $context): array
    {
        $user = $this->freshUser($context);
        if (self::isEnabled($user)) {
            throw new ConflictException('Two-factor authentication is already on. Turn it off first to set up a new device.');
        }
        $secret = Totp::generateSecret();
        $this->twoFactor->setPendingSecret((int) $user['id'], Crypto::encrypt($secret));

        $issuer = 'FPDP ' . (string) Config::get('NODE_DOMAIN', 'node');

        return ['secret' => $secret, 'otpauth_uri' => Totp::provisioningUri($issuer, (string) $user['email'], $secret)];
    }

    /**
     * Step 2 of setup: the first code from the app proves it was scanned.
     * Turns 2FA on, logs every other device out, and returns the recovery
     * codes — the only time they are ever shown.
     *
     * @param array<string, mixed> $context
     * @return array{recovery_codes: array<int, string>, other_sessions_revoked: int}
     */
    public function confirm(array $context, string $rawToken, string $code): array
    {
        $user = $this->freshUser($context);
        if (self::isEnabled($user)) {
            throw new ConflictException('Two-factor authentication is already on.');
        }
        $pending = (string) ($user['totp_pending_secret'] ?? '');
        if ($pending === '') {
            throw new ValidationException([['field' => 'code', 'reason' => 'no_setup_in_progress']], 'Start the setup first.');
        }
        $secret = Crypto::decrypt($pending);
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            throw new ValidationException([['field' => 'code', 'reason' => 'incorrect']], 'That code is not correct. Check the time on your phone and try the newest code.');
        }

        $userId = (int) $user['id'];
        $this->twoFactor->enable($userId, Crypto::encrypt($secret), $step, date('Y-m-d H:i:s'));
        $codes = $this->issueRecoveryCodes($userId);
        $revoked = $this->tokens->revokeAllExcept($userId, hash('sha256', $rawToken), date('Y-m-d H:i:s'));
        $this->audit?->record($context, 'user.2fa_enabled', 'user', (string) $user['public_id'], ['other_sessions_revoked' => $revoked]);

        return ['recovery_codes' => $codes, 'other_sessions_revoked' => $revoked];
    }

    /**
     * Turns 2FA off. Needs the password and a current code (or a recovery
     * code), so a stolen session alone can't remove the second factor.
     *
     * @param array<string, mixed> $context
     */
    public function disable(array $context, string $password, string $code): void
    {
        $user = $this->freshUser($context);
        if (!self::isEnabled($user)) {
            throw new ConflictException('Two-factor authentication is not on.');
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->audit?->record($context, 'user.2fa_disable_failed', 'user', (string) $user['public_id'], ['reason' => 'password']);
            throw new ValidationException([['field' => 'password', 'reason' => 'incorrect']], 'The password is incorrect.');
        }
        if (!$this->checkCode($user, $code)) {
            $this->audit?->record($context, 'user.2fa_disable_failed', 'user', (string) $user['public_id'], ['reason' => 'code']);
            throw new ValidationException([['field' => 'code', 'reason' => 'incorrect']], 'That code is not correct.');
        }

        $this->twoFactor->disable((int) $user['id']);
        $this->audit?->record($context, 'user.2fa_disabled', 'user', (string) $user['public_id']);
    }

    /**
     * New set of recovery codes (the old ones stop working). Needs a code.
     *
     * @param array<string, mixed> $context
     * @return array{recovery_codes: array<int, string>}
     */
    public function regenerateRecoveryCodes(array $context, string $code): array
    {
        $user = $this->freshUser($context);
        if (!self::isEnabled($user)) {
            throw new ConflictException('Two-factor authentication is not on.');
        }
        if (!$this->checkCode($user, $code, allowRecoveryCode: false)) {
            throw new ValidationException([['field' => 'code', 'reason' => 'incorrect']], 'That code is not correct.');
        }
        $codes = $this->issueRecoveryCodes((int) $user['id']);
        $this->audit?->record($context, 'user.2fa_recovery_codes_regenerated', 'user', (string) $user['public_id']);

        return ['recovery_codes' => $codes];
    }

    /**
     * After a correct password: a token that is not a session, only a
     * ticket to try the second factor.
     *
     * @param array<string, mixed> $user
     */
    public function startChallenge(array $user): string
    {
        $this->twoFactor->deleteExpiredChallenges(date('Y-m-d H:i:s'));
        $raw = bin2hex(random_bytes(32));
        $this->twoFactor->createChallenge((int) $user['id'], hash('sha256', $raw), date('Y-m-d H:i:s', time() + self::CHALLENGE_TTL_SECONDS));

        return $raw;
    }

    /**
     * Exchanges a challenge plus a code for the user it belongs to.
     *
     * @return array<string, mixed> the user row
     */
    public function consumeChallenge(string $rawChallenge, string $code): array
    {
        $challenge = $rawChallenge !== '' ? $this->twoFactor->findChallenge(hash('sha256', $rawChallenge)) : null;
        if ($challenge === null || strtotime((string) $challenge['expires_at']) < time()) {
            throw new UnauthorizedException('This sign-in has expired. Enter your email and password again.');
        }
        $user = $this->users->findById((int) $challenge['user_id']);
        if ($user === null || !self::isEnabled($user)) {
            $this->twoFactor->deleteChallenge((int) $challenge['id']);
            throw new UnauthorizedException('This sign-in has expired. Enter your email and password again.');
        }

        if (!$this->checkCode($user, $code)) {
            $this->twoFactor->countChallengeAttempt((int) $challenge['id']);
            $context = ['user' => $user, 'node' => ['id' => $user['node_id']]];
            $this->audit?->record($context, 'user.2fa_failed', 'user', (string) $user['public_id']);
            if ((int) $challenge['attempts'] + 1 >= self::CHALLENGE_MAX_ATTEMPTS) {
                $this->twoFactor->deleteChallenge((int) $challenge['id']);
                throw new UnauthorizedException('Too many wrong codes. Enter your email and password again.');
            }
            throw new ValidationException([['field' => 'code', 'reason' => 'incorrect']], 'That code is not correct.');
        }

        $this->twoFactor->deleteChallenge((int) $challenge['id']);

        return $user;
    }

    /**
     * A 6-digit code from the app (each 30-second code once), or — unless
     * disallowed — an unused recovery code, which is then spent.
     *
     * @param array<string, mixed> $user
     */
    private function checkCode(array $user, string $code, bool $allowRecoveryCode = true): bool
    {
        $userId = (int) $user['id'];
        $trimmed = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $trimmed) === 1) {
            $step = Totp::verify(Crypto::decrypt((string) $user['totp_secret']), $trimmed, null, 1, isset($user['totp_last_step']) ? (int) $user['totp_last_step'] : null);

            return $step !== null && $this->twoFactor->claimStep($userId, $step);
        }
        if (!$allowRecoveryCode) {
            return false;
        }

        $normalized = strtolower(preg_replace('/[\s-]+/', '', $code) ?? '');
        if (strlen($normalized) !== 10) {
            return false;
        }
        foreach ($this->twoFactor->unusedRecoveryCodes($userId) as $row) {
            if (password_verify($normalized, (string) $row['code_hash']) && $this->twoFactor->useRecoveryCode((int) $row['id'], date('Y-m-d H:i:s'))) {
                $this->audit?->record(['user' => $user, 'node' => ['id' => $user['node_id']]], 'user.2fa_recovery_code_used', 'user', (string) $user['public_id'], [
                    'remaining' => count($this->twoFactor->unusedRecoveryCodes($userId)),
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string> the codes, formatted "abcde-fghjk"
     */
    private function issueRecoveryCodes(int $userId): array
    {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = '';
            for ($c = 0; $c < 10; $c++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $hashes[] = password_hash($raw, PASSWORD_DEFAULT);
        }
        $this->twoFactor->replaceRecoveryCodes($userId, $hashes);

        return $codes;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function freshUser(array $context): array
    {
        $user = $this->users->findById((int) $context['user']['id']);
        if ($user === null) {
            throw new UnauthorizedException();
        }

        return $user;
    }
}
