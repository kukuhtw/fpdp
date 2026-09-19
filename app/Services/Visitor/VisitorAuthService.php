<?php

declare(strict_types=1);

namespace App\Services\Visitor;

use App\Contracts\GoogleOAuthClientInterface;
use App\Core\Config;
use App\Core\Exceptions\UnauthorizedException;
use App\Core\Uuid;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use DateTimeImmutable;

final class VisitorAuthService
{
    public function __construct(
        private readonly GoogleOAuthClientInterface $googleClient,
        private readonly VisitorRepository $visitors,
        private readonly VisitorTokenRepository $tokens,
    ) {
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return $this->googleClient->getAuthorizationUrl($state, $redirectUri);
    }

    /**
     * Resolves the Google profile behind an authorization code, finds or
     * creates the node-scoped visitor account, and issues a fresh visitor
     * bearer token.
     *
     * @return array<string, mixed>
     */
    public function handleCallback(int $nodeId, string $code, string $redirectUri): array
    {
        $profile = $this->googleClient->resolveVisitorProfile($code, $redirectUri);

        if ($profile['sub'] === '' || $profile['email'] === '') {
            throw new UnauthorizedException('Google did not provide a usable identity.');
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $visitor = $this->visitors->findByNodeAndGoogleSub($nodeId, $profile['sub']);

        if ($visitor === null) {
            $visitorId = $this->visitors->create(
                Uuid::v4(),
                $nodeId,
                $profile['sub'],
                $profile['email'],
                $profile['name'],
                $profile['picture'],
            );
            $visitor = $this->visitors->findById($visitorId);
        } else {
            $this->visitors->touchLastSeen((int) $visitor['id'], $now);
        }

        return [
            'visitor' => $visitor,
            'token' => $this->issueToken((int) $visitor['id']),
        ];
    }

    /**
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

        $visitor = $this->visitors->findById((int) $tokenRow['visitor_id']);
        if ($visitor === null) {
            throw new UnauthorizedException();
        }

        return ['visitor' => $visitor];
    }

    public function logout(string $rawToken): void
    {
        $this->tokens->revokeByHash(hash('sha256', $rawToken), (new DateTimeImmutable())->format('Y-m-d H:i:s'));
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    private function issueToken(int $visitorId): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $ttl = (int) Config::get('VISITOR_TOKEN_TTL', '2592000');
        $expiresAt = (new DateTimeImmutable())->modify("+{$ttl} seconds")->format('Y-m-d H:i:s');

        $this->tokens->create($visitorId, hash('sha256', $rawToken), $expiresAt);

        return [
            'access_token' => $rawToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
        ];
    }
}
