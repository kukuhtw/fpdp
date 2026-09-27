<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\AuthTokenRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\Security\AuditService;
use DateTimeImmutable;
use PDOException;

final class AuthService
{
    private const OWNER_ROLE = 'OWNER';
    /** last_used_at is refreshed at most this often, not on every request. */
    private const TOUCH_INTERVAL_SECONDS = 300;
    /** Expired/revoked tokens older than this are deleted at the next login. */
    private const STALE_TOKEN_DAYS = 30;

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly AuthTokenRepository $tokens,
        private readonly ?AuditService $audit = null,
        private readonly ?TwoFactorService $twoFactor = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(array $input, array $client = []): array
    {
        $email = self::normalizeEmail((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $handle = strtolower(trim((string) ($input['handle'] ?? '')));
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $locale = (string) ($input['locale'] ?? 'id');

        $errors = self::validateAgainstSchema($input, ['email', 'password', 'handle', 'display_name', 'locale']);

        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['field' => 'email', 'reason' => 'invalid_format'];
        }
        if (strlen($password) < 12 || strlen($password) > 128) {
            $errors[] = ['field' => 'password', 'reason' => 'invalid_length'];
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,62}$/', $handle)) {
            $errors[] = ['field' => 'handle', 'reason' => 'invalid_format'];
        }
        if ($displayName === '' || mb_strlen($displayName) > 128) {
            $errors[] = ['field' => 'display_name', 'reason' => 'invalid_length'];
        }
        if (!in_array($locale, ['en', 'id'], true)) {
            $errors[] = ['field' => 'locale', 'reason' => 'invalid_value'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($this->users->emailExists($email)) {
            throw new ConflictException('Email is already registered.');
        }
        if ($this->profiles->handleExists($handle)) {
            throw new ConflictException('Handle is already taken.');
        }

        // One FPDP install is one node for one owner (every read path uses
        // NodeRepository::findFirst(), never a handle-keyed lookup), so the
        // node's domain is simply the domain this install is deployed on —
        // not a handle.NODE_DOMAIN subdomain, which would only make sense
        // for a multi-tenant host and contradicts "your domain is your
        // digital home" (see README/BRD): the node's domain is the owner's
        // own domain, e.g. kukuhtw.com, not kukuh.kukuhtw.com.
        $domain = (string) Config::get('NODE_DOMAIN', 'localhost');

        try {
            $nodeId = $this->nodes->create(
                Uuid::v4(),
                $domain,
                Config::get('NODE_NAME', 'FPDP Node') . ' — ' . $displayName,
                $locale,
                Config::get('NODE_TIMEZONE', 'Asia/Jakarta'),
            );

            $userId = $this->users->create(
                Uuid::v4(),
                $nodeId,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                'OWNER',
            );

            $this->profiles->create(Uuid::v4(), $userId, $handle, $displayName);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ConflictException('Email or handle is already registered.');
            }

            throw $e;
        }

        $result = [
            'user' => $this->users->findById($userId),
            'node' => $this->nodes->findById($nodeId),
            'profile' => $this->profiles->findByUserId($userId),
            'token' => $this->issueToken($userId, $client),
        ];

        $this->audit?->record($result, 'user.registered', 'user', $result['user']['public_id'], [
            'handle' => $handle,
            'node_domain' => $domain,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @param array{user_agent?: string|null, ip?: string|null} $client The device logging in, shown in the session list.
     * @return array<string, mixed>
     */
    public function login(array $input, array $client = []): array
    {
        $email = self::normalizeEmail((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        $errors = self::validateAgainstSchema($input, ['email', 'password']);
        if ($email === '') {
            $errors[] = ['field' => 'email', 'reason' => 'required'];
        }
        if ($password === '') {
            $errors[] = ['field' => 'password', 'reason' => 'required'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            if ($user !== null) {
                $this->audit?->record(['user' => $user, 'node' => ['id' => $user['node_id']]], 'user.login_failed', 'user', $user['public_id']);
            }
            throw new UnauthorizedException('Invalid email or password.');
        }
        $this->assertOwnerAccess($user);

        // With 2FA on, a correct password only earns a short-lived challenge;
        // the session comes from verifyLoginCode().
        if ($this->twoFactor !== null && TwoFactorService::isEnabled($user)) {
            return [
                'mfa_required' => true,
                'mfa_token' => $this->twoFactor->startChallenge($user),
                'expires_in' => 300,
            ];
        }

        return $this->completeLogin($user, $client, false);
    }

    /**
     * Second step of a 2FA login: the challenge from login() plus a code from
     * the authenticator app (or a recovery code) → a normal session.
     *
     * @param array<string, mixed> $input {mfa_token, code}
     * @param array{user_agent?: string|null, ip?: string|null} $client
     * @return array<string, mixed>
     */
    public function verifyLoginCode(array $input, array $client = []): array
    {
        if ($this->twoFactor === null) {
            throw new UnauthorizedException();
        }
        $errors = self::validateAgainstSchema($input, ['mfa_token', 'code']);
        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            $errors[] = ['field' => 'code', 'reason' => 'required'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->twoFactor->consumeChallenge((string) ($input['mfa_token'] ?? ''), $code);
        $this->assertOwnerAccess($user);

        return $this->completeLogin($user, $client, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function completeLogin(array $user, array $client, bool $withSecondFactor): array
    {
        $result = [
            'user' => $user,
            'node' => $this->nodes->findById((int) $user['node_id']),
            'token' => $this->issueToken((int) $user['id'], $client),
        ];

        $this->audit?->record($result, 'user.login', 'user', $user['public_id'], [
            'ip_hint' => self::ipHint($client['ip'] ?? null),
            'second_factor' => $withSecondFactor,
        ]);

        return $result;
    }

    /**
     * Resolves a bearer token into its user/node/profile context, or throws
     * UnauthorizedException when the token is missing, unknown, revoked, or
     * expired. Every owner dashboard/API endpoint goes through here, so this
     * is also the one access check: see assertOwnerAccess().
     *
     * @return array<string, mixed>
     */
    public function authenticate(?string $rawToken): array
    {
        if ($rawToken === null || $rawToken === '') {
            throw new UnauthorizedException();
        }

        $tokenRow = $this->tokens->findByHash(hash('sha256', $rawToken));

        if ($tokenRow === null || $tokenRow['revoked_at'] !== null) {
            throw new UnauthorizedException();
        }

        if (strtotime((string) $tokenRow['expires_at']) <= time()) {
            throw new UnauthorizedException('Token has expired.');
        }

        $user = $this->users->findById((int) $tokenRow['user_id']);
        if ($user === null) {
            throw new UnauthorizedException();
        }
        $this->assertOwnerAccess($user);

        $lastUsed = strtotime((string) ($tokenRow['last_used_at'] ?? ''));
        if ($lastUsed === false || time() - $lastUsed > self::TOUCH_INTERVAL_SECONDS) {
            $this->tokens->touchLastUsed((string) $tokenRow['token_hash'], date('Y-m-d H:i:s'));
        }

        return [
            'user' => $user,
            'node' => $this->nodes->findById((int) $user['node_id']),
            'profile' => $this->profiles->findByUserId((int) $user['id']),
            // Which session this request is, so the session list can mark it.
            'session_id' => $tokenRow['public_id'] ?? null,
        ];
    }

    /**
     * The owner's active sessions (devices still logged in), newest first,
     * with the one making this request marked `current`.
     *
     * @param array<string, mixed> $context from authenticate()
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(array $context): array
    {
        return array_map(static fn (array $row): array => [
            'id' => $row['public_id'],
            'current' => $row['public_id'] !== null && $row['public_id'] === ($context['session_id'] ?? null),
            'device' => self::describeUserAgent($row['user_agent'] ?? null),
            'user_agent' => $row['user_agent'],
            'ip_hint' => $row['ip_hint'],
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'],
            'expires_at' => $row['expires_at'],
        ], $this->tokens->findActiveByUserId((int) $context['user']['id'], date('Y-m-d H:i:s')));
    }

    /**
     * Logs one other device out. The current session is ended with logout
     * instead, so a click here can never lock the owner out mid-page.
     *
     * @param array<string, mixed> $context from authenticate()
     */
    public function revokeSession(array $context, string $sessionId): void
    {
        if ($sessionId !== '' && $sessionId === ($context['session_id'] ?? null)) {
            throw new ValidationException([['field' => 'session', 'reason' => 'is_current']], 'This is the session you are using; log out instead.');
        }
        if (!$this->tokens->revokeByPublicId((int) $context['user']['id'], $sessionId, date('Y-m-d H:i:s'))) {
            throw new NotFoundException('Session not found.');
        }

        $this->audit?->record($context, 'session.revoked', 'user', (string) $context['user']['public_id'], ['session_id' => $sessionId]);
    }

    /**
     * Logs every other device out, keeping only this one.
     *
     * @param array<string, mixed> $context from authenticate()
     * @return int how many sessions were ended
     */
    public function revokeOtherSessions(array $context, string $rawToken): int
    {
        $count = $this->tokens->revokeAllExcept((int) $context['user']['id'], hash('sha256', $rawToken), date('Y-m-d H:i:s'));
        $this->audit?->record($context, 'session.revoked_others', 'user', (string) $context['user']['public_id'], ['count' => $count]);

        return $count;
    }

    /**
     * Changes the owner's password. The current password is required (a
     * stolen, still-valid token alone is not enough), the new one follows
     * the registration rules, and every other session is logged out — the
     * usual reason to change a password is that someone else may have it.
     *
     * @param array<string, mixed> $context from authenticate()
     * @return array{other_sessions_revoked: int}
     */
    public function changePassword(array $context, string $rawToken, string $currentPassword, string $newPassword): array
    {
        $user = $this->users->findById((int) $context['user']['id']);
        if ($user === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            $this->audit?->record($context, 'user.password_change_failed', 'user', (string) $context['user']['public_id']);
            throw new ValidationException([['field' => 'current_password', 'reason' => 'incorrect']], 'The current password is incorrect.');
        }
        if (strlen($newPassword) < 12 || strlen($newPassword) > 128) {
            throw new ValidationException([['field' => 'new_password', 'reason' => 'invalid_length']], 'The new password must be 12 to 128 characters.');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            throw new ValidationException([['field' => 'new_password', 'reason' => 'unchanged']], 'The new password must differ from the current one.');
        }

        $this->users->updatePasswordHash((int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));
        $revoked = $this->tokens->revokeAllExcept((int) $user['id'], hash('sha256', $rawToken), date('Y-m-d H:i:s'));
        $this->audit?->record($context, 'user.password_changed', 'user', (string) $user['public_id'], ['other_sessions_revoked' => $revoked]);

        return ['other_sessions_revoked' => $revoked];
    }

    /**
     * Keeps the network, not the person: IPv4 to its /24 ("203.0.113.x"),
     * IPv6 to its /48. Enough to tell "my home connection" from "somewhere
     * else" in the session list.
     */
    public static function ipHint(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.x";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $expanded = unpack('n8', (string) inet_pton($ip));

            return sprintf('%x:%x:%x::/48', $expanded[1], $expanded[2], $expanded[3]);
        }

        return null;
    }

    /**
     * A short "Chrome on Windows" style label; the raw user agent is kept
     * alongside it for anything this doesn't recognise.
     */
    private static function describeUserAgent(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'Unknown device';
        }
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            str_starts_with($userAgent, 'curl/') => 'curl',
            default => null,
        };
        $os = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $os !== null => "{$browser} on {$os}",
            $browser !== null => $browser,
            $os !== null => $os,
            default => 'Unknown device',
        };
    }

    /**
     * FPDP's access model is one owner per node (see the README): the only
     * dashboard account that may act is an ACTIVE user with role OWNER. Any
     * other status or role — a suspended owner, or a row with an unexpected
     * role — is refused on login and on every request, even with a token
     * issued earlier, and the refusal is written to the audit trail.
     *
     * @param array<string, mixed> $user
     */
    private function assertOwnerAccess(array $user): void
    {
        $status = strtoupper((string) ($user['status'] ?? 'ACTIVE'));
        $role = strtoupper((string) ($user['role'] ?? self::OWNER_ROLE));
        if ($status === 'ACTIVE' && $role === self::OWNER_ROLE) {
            return;
        }

        $this->audit?->record(['user' => $user, 'node' => ['id' => $user['node_id']]], 'access.denied', 'user', (string) $user['public_id'], [
            'status' => $status,
            'role' => $role,
        ]);
        throw new ForbiddenException('This account is not allowed to access the dashboard.');
    }

    public function logout(string $rawToken): void
    {
        $tokenRow = $this->tokens->findByHash(hash('sha256', $rawToken));
        $this->tokens->revokeByHash(hash('sha256', $rawToken), (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        if ($tokenRow !== null) {
            $user = $this->users->findById((int) $tokenRow['user_id']);
            if ($user !== null) {
                $this->audit?->record(
                    ['user' => $user, 'node' => $this->nodes->findById((int) $user['node_id'])],
                    'user.logout',
                    'user',
                    $user['public_id'],
                );
            }
        }
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    private function issueToken(int $userId, array $client = []): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $ttl = (int) Config::get('AUTH_TOKEN_TTL', '604800');
        $expiresAt = (new DateTimeImmutable())->modify("+{$ttl} seconds")->format('Y-m-d H:i:s');

        $userAgent = trim((string) ($client['user_agent'] ?? ''));
        $this->tokens->create(
            $userId,
            hash('sha256', $rawToken),
            $expiresAt,
            'ACCESS',
            Uuid::v4(),
            $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            self::ipHint($client['ip'] ?? null),
        );
        $this->tokens->deleteStale($userId, date('Y-m-d H:i:s', time() - self::STALE_TOKEN_DAYS * 86400));

        return [
            'access_token' => $rawToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<int, string> $allowedFields
     * @return array<int, array<string, mixed>>
     */
    private static function validateAgainstSchema(array $input, array $allowedFields): array
    {
        $unknown = array_diff(array_keys($input), $allowedFields);
        if ($unknown === []) {
            return [];
        }

        return array_map(
            static fn (string $field): array => ['field' => $field, 'reason' => 'unknown_field'],
            array_values($unknown),
        );
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
