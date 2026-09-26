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
use App\Services\Security\AccessPolicy;
use App\Services\Security\AuditService;
use DateTimeImmutable;
use PDOException;

final class AuthService
{
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
        if (!self::isUsable($user)) {
            throw new ForbiddenException('This account is not active.');
        }

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
     * UnauthorizedException when the token is missing, unknown, revoked, or expired.
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
        // A suspended account, or one with a role AccessPolicy doesn't know,
        // loses access immediately — even with a token issued before.
        if (!self::isUsable($user)) {
            throw new ForbiddenException('This account is not active.');
        }

        return [
            'user' => $user,
            'node' => $this->nodes->findById((int) $user['node_id']),
            'profile' => $this->profiles->findByUserId((int) $user['id']),
        ];
    }

    /**
     * authenticate() plus an AccessPolicy permission check, for endpoints
     * that touch money, credentials, buyer data, or federation trust. A
     * denial is written to the audit trail before the 403 is thrown.
     *
     * @return array<string, mixed>
     */
    public function authorize(?string $rawToken, string $permission): array
    {
        $context = $this->authenticate($rawToken);
        if (!AccessPolicy::allows((string) ($context['user']['role'] ?? 'OWNER'), $permission)) {
            $this->audit?->record($context, 'access.denied', 'user', (string) $context['user']['public_id'], [
                'permission' => $permission,
                'role' => $context['user']['role'] ?? 'OWNER',
            ]);
            throw new ForbiddenException();
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function isUsable(array $user): bool
    {
        return strtoupper((string) ($user['status'] ?? 'ACTIVE')) === 'ACTIVE'
            && AccessPolicy::isKnownRole((string) ($user['role'] ?? 'OWNER'));
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
