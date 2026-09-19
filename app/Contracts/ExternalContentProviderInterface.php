<?php

declare(strict_types=1);

namespace App\Contracts;

interface ExternalContentProviderInterface
{
    public function getProviderCode(): string;

    public function authenticate(array $configuration): array;

    public function refreshAuthentication(array $account): array;

    public function getProfile(array $account): array;

    public function fetchPosts(array $account, ?string $cursor = null): array;

    public function fetchSinglePost(array $account, string $externalPostId): array;

    public function disconnect(array $account): bool;
}
