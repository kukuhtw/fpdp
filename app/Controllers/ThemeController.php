<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Theme\ThemeService;

final class ThemeController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ThemeService $themes,
    ) {
    }

    /**
     * GET /api/v1/me/themes
     */
    public function list(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success([
            'themes' => $this->themes->listAvailable(),
            'active_theme' => $this->themes->getActiveSlug((int) $context['node']['id']),
        ]);
    }

    /**
     * PATCH /api/v1/me/theme
     */
    public function update(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];

        return JsonEnvelope::success($this->themes->setActive((int) $context['node']['id'], (string) ($input['slug'] ?? '')));
    }

    /**
     * GET /themes/{slug}/assets/{file}
     *
     * Public static file server for a theme's own CSS/JS/images — theme
     * PHP templates themselves are never served over HTTP, only assets.
     *
     * @param array<string, string> $params
     */
    public function asset(Request $request, array $params): Response
    {
        $path = $this->themes->assetPath($params['slug'] ?? '', $params['file'] ?? '');
        if ($path === null) {
            throw new NotFoundException('Theme asset not found.');
        }

        return Response::media((string) file_get_contents($path), self::contentType($path));
    }

    private static function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            default => 'application/octet-stream',
        };
    }
}
