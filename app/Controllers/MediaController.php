<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Content\MediaUploadService;

final class MediaController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly MediaUploadService $media,
    ) {
    }

    /**
     * POST /api/v1/me/media
     *
     * Owner-only. Uploads a file for attaching to a post (image, video,
     * audio, or PDF) and returns its public URL, ready to paste into a
     * post's `media[].url` — see MediaUploadService for why no separate
     * "post media" table is needed for this.
     */
    public function upload(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $result = $this->media->upload($request->json() ?? []);

        return JsonEnvelope::success([
            'url' => $request->scheme() . '://' . $context['node']['domain'] . '/api/v1/media/' . $result['storage_key'],
            'storage_key' => $result['storage_key'],
            'content_type' => $result['content_type'],
            'media_type' => $result['media_type'],
        ], 201);
    }

    /**
     * GET /api/v1/media/{key}
     *
     * Public, no auth — uploaded post media is embedded in public posts the
     * same way an external CDN URL would be. The storage key is an
     * unguessable UUID; that is the access control.
     *
     * @param array<string, string> $params
     */
    public function show(array $params): Response
    {
        $file = $this->media->read((string) ($params['key'] ?? ''));
        if ($file === null) {
            throw new NotFoundException('Media not found.');
        }

        return Response::media($file['content'], $file['content_type']);
    }
}
