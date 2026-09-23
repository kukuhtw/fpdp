<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        $viewPath = __DIR__ . '/../Views/' . $view . '.php';

        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View not found: {$viewPath}");
        }

        ob_start();
        require $viewPath;
        return (string) ob_get_clean();
    }

    /**
     * Renders a themeable public-facing view: if the active theme provides
     * its own template for $view (themes/{slug}/views/{view}.php), that
     * overrides the core one in app/Views/{view}.php. Falls back to the
     * core template for themes that don't override a given view, and for
     * the built-in 'default' theme (which never has its own views/).
     *
     * @param array<string, mixed> $data
     */
    public static function renderThemed(string $view, array $data, ?string $themeSlug): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require self::resolveThemedPath($view, $themeSlug);
        return (string) ob_get_clean();
    }

    /**
     * Renders a shared partial (app/Views/partials/{name}.php) from inside
     * a view — including a themed one, since partials always resolve
     * against the core Views directory regardless of which theme is
     * active, so themes don't need to duplicate shared, escaping-sensitive
     * markup (like the post card) just to override a page's chrome.
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $name, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require __DIR__ . '/../Views/partials/' . $name . '.php';
    }

    private static function resolveThemedPath(string $view, ?string $themeSlug): string
    {
        if ($themeSlug !== null && $themeSlug !== '' && $themeSlug !== 'default'
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $themeSlug) === 1) {
            $themedPath = __DIR__ . '/../../themes/' . $themeSlug . '/views/' . $view . '.php';
            if (is_file($themedPath)) {
                return $themedPath;
            }
        }

        $corePath = __DIR__ . '/../Views/' . $view . '.php';
        if (!file_exists($corePath)) {
            throw new \RuntimeException("View not found: {$corePath}");
        }

        return $corePath;
    }

    /**
     * Turn bare http(s) URLs inside already-escaped HTML into clickable links.
     * Must be called on text that has already been through htmlspecialchars().
     */
    public static function autolink(string $escapedText): string
    {
        return preg_replace_callback(
            '/https?:\/\/[^\s<]+/i',
            static function (array $match): string {
                $url = $match[0];
                $trail = '';
                while ($url !== '' && str_contains('.,!?:)]}\'"', substr($url, -1))) {
                    $trail = substr($url, -1) . $trail;
                    $url = substr($url, 0, -1);
                }
                if ($url === '') {
                    return $match[0];
                }
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow ugc">' . $url . '</a>' . $trail;
            },
            $escapedText
        ) ?? $escapedText;
    }
}
