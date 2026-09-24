<?php

declare(strict_types=1);

namespace App\Services\Theme;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Repositories\NodeRepository;

/**
 * Discovers installed template themes under /themes and resolves/updates
 * which one a node has active. A theme is "installed" simply by placing
 * its folder under /themes on the server (see documentation/THEME-GUIDE) —
 * there is no upload/extract step, so no remote code can land here without
 * filesystem access the owner already has.
 */
final class ThemeService
{
    /** View names a theme is allowed to override. Anything else in views/ is ignored. */
    public const THEMEABLE_VIEWS = ['profile', 'about-me', 'youtube', 'wall-coretan', 'post', 'public-cv', 'about', 'timeline', 'shop', 'product'];

    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly string $themesPath,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAvailable(): array
    {
        $themes = [];

        foreach (is_dir($this->themesPath) ? (scandir($this->themesPath) ?: []) : [] as $entry) {
            if ($entry === '.' || $entry === '..' || preg_match(self::SLUG_PATTERN, $entry) !== 1) {
                continue;
            }

            $themeDir = $this->themesPath . '/' . $entry;
            $manifestPath = $themeDir . '/theme.json';
            if (!is_dir($themeDir) || !is_file($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                continue;
            }

            $overriddenViews = [];
            foreach (self::THEMEABLE_VIEWS as $view) {
                if (is_file($themeDir . '/views/' . $view . '.php')) {
                    $overriddenViews[] = $view;
                }
            }

            $themes[] = [
                'slug' => $entry,
                'name' => (string) ($manifest['name'] ?? $entry),
                'description' => (string) ($manifest['description'] ?? ''),
                'author' => (string) ($manifest['author'] ?? ''),
                'version' => (string) ($manifest['version'] ?? '1.0.0'),
                'preview_color' => (string) ($manifest['preview_color'] ?? '#185f48'),
                'overridden_views' => $overriddenViews,
            ];
        }

        usort($themes, static function (array $a, array $b): int {
            if ($a['slug'] === 'default') return -1;
            if ($b['slug'] === 'default') return 1;
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $themes;
    }

    /**
     * The node's chosen theme, falling back to 'default' if unset or if
     * the chosen theme's folder is no longer installed on disk.
     */
    public function getActiveSlug(int $nodeId): string
    {
        $node = $this->nodes->findById($nodeId);
        $slug = (string) ($node['theme'] ?? 'default');

        if ($slug === 'default') {
            return 'default';
        }

        foreach ($this->listAvailable() as $theme) {
            if ($theme['slug'] === $slug) {
                return $slug;
            }
        }

        return 'default';
    }

    /**
     * The active theme for pages with no handle/profile in context (About
     * FPDP, Timeline) — resolved for "the" node the same single-tenant way
     * HomeController does for its handle-less public routes.
     */
    public function getActiveSlugForFirstNode(): ?string
    {
        $node = $this->nodes->findFirst();

        return $node === null ? null : $this->getActiveSlug((int) $node['id']);
    }

    /**
     * @return array<string, mixed>
     */
    public function setActive(int $nodeId, string $slug): array
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new ValidationException([['field' => 'slug', 'reason' => 'invalid_format']]);
        }

        if ($slug !== 'default') {
            $found = false;
            foreach ($this->listAvailable() as $theme) {
                if ($theme['slug'] === $slug) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new NotFoundException('Theme not found.');
            }
        }

        $this->nodes->updateTheme($nodeId, $slug);

        return ['active_theme' => $slug];
    }

    /**
     * Resolves a theme asset file (CSS/JS/image/font) to an absolute path
     * for /themes/{slug}/assets/{file}, rejecting traversal and anything
     * outside a small allow-list of static asset extensions.
     */
    public function assetPath(string $slug, string $filename): ?string
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.(css|js|png|jpe?g|svg|webp|gif|woff2?|ttf)$/', $filename) !== 1) {
            return null;
        }

        $path = $this->themesPath . '/' . $slug . '/assets/' . $filename;

        return is_file($path) ? $path : null;
    }
}
