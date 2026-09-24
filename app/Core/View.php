<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\NodeRepository;
use App\Services\Theme\ThemeService;

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
     * The <link> tag(s) every page — public or dashboard — puts in <head>
     * for its stylesheet. Always includes core app.css (the baseline every
     * class, including ones a theme never mentions, is styled against),
     * plus the active theme's own theme.css layered on top when one is
     * active and ships that file — themes restyle shared chrome (nav,
     * panels, buttons, status colors) by writing rules against those same
     * class names, which then win the cascade on every page that uses
     * them, not only the small set of pages a theme fully overrides the
     * HTML of (see ThemeService::THEMEABLE_VIEWS). This is what makes
     * switching the active template visibly affect the whole site,
     * dashboard included, without ever handing dashboard/auth pages'
     * markup to theme code.
     */
    public static function themeStylesheetTag(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $tag = '<link rel="stylesheet" href="/assets/app.css">';

        try {
            $connection = Database::connection();
            $node = (new NodeRepository($connection))->findFirst();
            if ($node !== null) {
                $themes = new ThemeService(new NodeRepository($connection), dirname(__DIR__, 2) . '/themes');
                $slug = $themes->getActiveSlug((int) $node['id']);
                if ($slug !== 'default' && $themes->assetPath($slug, 'theme.css') !== null) {
                    $tag .= '<link rel="stylesheet" href="/themes/' . rawurlencode($slug) . '/assets/theme.css">';
                }
            }
        } catch (\Throwable) {
            // No DB yet (e.g. pre-install), or a transient failure — fall
            // back to the core stylesheet alone rather than breaking the page.
        }

        return $cached = $tag;
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

    /**
     * Plain-text excerpt of a post's (HTML) content: strips tags rather than
     * attempting to preserve formatting, since a truncated word count can't
     * safely close whatever tags it cuts through. Callers append their own
     * "read more" link for the full, formatted post.
     */
    /**
     * Caller is responsible for htmlspecialchars()-ing this result exactly
     * once before output (the same as any other plain-text value), then
     * nl2br()-ing it if paragraph breaks should render — this returns plain
     * text with single `\n`s between paragraphs, not HTML.
     */
    public static function excerpt(string $html, int $maxWords = 50): string
    {
        // Post content is stored already HTML-escaped (post-card.php's
        // non-excerpt path echoes it raw) and authored as HTML — turn its
        // block boundaries into newlines before stripping tags, so
        // paragraph breaks survive into the plain-text excerpt instead of
        // the whole thing collapsing onto one line.
        $withBreaks = preg_replace('/<\/p>|<br\s*\/?>|<\/div>|<\/li>/i', "\n", $html) ?? $html;
        $decoded = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES, 'UTF-8');

        // Collapse horizontal whitespace and runs of blank lines, but keep
        // single newlines between paragraphs.
        $normalized = preg_replace('/[ \t]+/', ' ', $decoded) ?? $decoded;
        $normalized = preg_replace('/ *\n */', "\n", $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\n{2,}/', "\n", $normalized) ?? $normalized, "\n ");
        if ($normalized === '') {
            return '';
        }

        // Split into words and the whitespace (including newlines) between
        // them, so truncation can stop at a word boundary while keeping
        // whichever paragraph breaks fall within the kept portion.
        $tokens = preg_split('/(\s+)/', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        $wordCount = 0;
        $totalWords = 0;
        foreach ($tokens as $token) {
            if (trim($token) !== '') {
                $totalWords++;
            }
        }
        if ($totalWords <= $maxWords) {
            return $normalized;
        }

        $result = '';
        foreach ($tokens as $token) {
            if (trim($token) === '') {
                $result .= $token;
                continue;
            }
            if ($wordCount >= $maxWords) {
                break;
            }
            $result .= $token;
            $wordCount++;
        }

        return rtrim($result) . '…';
    }
}
