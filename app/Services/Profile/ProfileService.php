<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Repositories\ProfileRepository;
use App\Services\Security\AuditService;

final class ProfileService
{
    private const UPDATABLE_FIELDS = ['display_name', 'bio', 'avatar_url', 'visibility'];
    private const VISIBILITIES = ['PUBLIC', 'UNLISTED', 'PRIVATE'];

    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublicProfile(string $handle): array
    {
        $profile = $this->profiles->findByHandle(strtolower(trim($handle)));

        if ($profile === null || $profile['visibility'] === 'PRIVATE') {
            throw new NotFoundException('Profile not found.');
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateOwnProfile(int $userId, array $input): array
    {
        $errors = [];

        $unknown = array_diff(array_keys($input), self::UPDATABLE_FIELDS);
        foreach ($unknown as $field) {
            $errors[] = ['field' => $field, 'reason' => 'unknown_field'];
        }

        if ($input === []) {
            $errors[] = ['field' => '_', 'reason' => 'empty_update'];
        }

        if (array_key_exists('display_name', $input)) {
            $displayName = (string) $input['display_name'];
            if ($displayName === '' || mb_strlen($displayName) > 128) {
                $errors[] = ['field' => 'display_name', 'reason' => 'invalid_length'];
            }
        }

        if (array_key_exists('bio', $input) && $input['bio'] !== null && mb_strlen((string) $input['bio']) > 2000) {
            $errors[] = ['field' => 'bio', 'reason' => 'invalid_length'];
        }

        if (array_key_exists('avatar_url', $input) && $input['avatar_url'] !== null) {
            if (!filter_var($input['avatar_url'], FILTER_VALIDATE_URL)) {
                $errors[] = ['field' => 'avatar_url', 'reason' => 'invalid_format'];
            }
        }

        if (array_key_exists('visibility', $input) && !in_array($input['visibility'], self::VISIBILITIES, true)) {
            $errors[] = ['field' => 'visibility', 'reason' => 'invalid_value'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $profile = $this->profiles->update($userId, $input);

        $this->audit?->record(
            ['user' => ['id' => $userId], 'node' => ['id' => $profile['node_id'] ?? 0]],
            'profile.updated',
            'profile',
            $profile['public_id'],
            array_intersect_key($input, array_flip(self::UPDATABLE_FIELDS)),
        );

        return $profile;
    }
}
