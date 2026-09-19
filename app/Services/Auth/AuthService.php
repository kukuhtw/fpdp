<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\AuthTokenRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use DateTimeImmutable;
use PDOException;

final class AuthService
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly AuthTokenRepository $tokens,
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

        $domain = $handle . '.' . Config::get('NODE_DOMAIN', 'localhost');

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

        return [
            'user' => $this->users->findById($userId),
            'node' => $this->nodes->findById($nodeId),
            'profile' => $this->profiles->findByUserId($userId),
            'token' => $this->issueToken($userId),
        ];
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
            throw new UnauthorizedException('Invalid email or password.');
        }

        return [
            'user' => $user,
            'node' => $this->nodes->findById((int) $user['node_id']),
            'token' => $this->issueToken((int) $user['id']),
        ];
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

        return [
            'user' => $user,
            'node' => $this->nodes->findById((int) $user['node_id']),
            'profile' => $this->profiles->findByUserId((int) $user['id']),
        ];
    }

    public function logout(string $rawToken): void
    {
        $this->tokens->revokeByHash(hash('sha256', $rawToken), (new DateTimeImmutable())->format('Y-m-d H:i:s'));
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
