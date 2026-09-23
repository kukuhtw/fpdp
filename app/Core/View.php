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
