<?php

declare(strict_types=1);

namespace App\Contracts;

interface GoogleOAuthClientInterface
{
    public function getAuthorizationUrl(string $state, string $redirectUri): string;

    /**
     * Exchanges an authorization code for a verified visitor identity.
     *
     * @return array{sub: string, email: string, name: ?string, picture: ?string}
     */
    public function resolveVisitorProfile(string $code, string $redirectUri): array;
}
