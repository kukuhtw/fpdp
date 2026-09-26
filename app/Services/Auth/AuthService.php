<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\ForbiddenException;
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

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly AuthTokenRepository $tokens,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(array $input): array
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
            'token' => $this->issueToken($userId),
        ];

        $this->audit?->record($result, 'user.registered', 'user', $result['user']['public_id'], [
            'handle' => $handle,
            'node_domain' => $domain,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function login(array $input): array
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

        $result = [
            'user' => $user,
            'node' => $this->nodes->findById((int) $user['node_id']),
            'token' => $this->issueToken((int) $user['id']),
        ];

        $this->audit?->record($result, 'user.login', 'user', $user['public_id']);

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

        return [
            'user' => $user,
            'node' => $this->nodes->findById((int) $user['node_id']),
            'profile' => $this->profiles->findByUserId((int) $user['id']),
        ];
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
    private function issueToken(int $userId): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $ttl = (int) Config::get('AUTH_TOKEN_TTL', '604800');
        $expiresAt = (new DateTimeImmutable())->modify("+{$ttl} seconds")->format('Y-m-d H:i:s');

        $this->tokens->create($userId, hash('sha256', $rawToken), $expiresAt);

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
