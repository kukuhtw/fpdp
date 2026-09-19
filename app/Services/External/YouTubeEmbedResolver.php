<?php

declare(strict_types=1);

namespace App\Services\External;

/**
 * Extracts a YouTube video ID from a canonical URL and builds the
 * privacy-enhanced embed/thumbnail URLs used to render it.
 */
final class YouTubeEmbedResolver
{
    private function __construct()
    {
    }

    public static function extractVideoId(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        if (preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#youtu\.be/([A-Za-z0-9_-]{11})#', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#youtube(?:-nocookie)?\.com/(?:embed|shorts)/([A-Za-z0-9_-]{11})#', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public static function embedUrl(string $videoId): string
    {
        return 'https://www.youtube-nocookie.com/embed/' . $videoId;
    }

    public static function thumbnailUrl(string $videoId): string
    {
        return 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
    }

    /**
     * @return array{type: string, provider: string, video_id: string, embed_url: string, thumbnail_url: string}|null
     */
    public static function describe(string $url, ?string $thumbnailUrl = null): ?array
    {
        $videoId = self::extractVideoId($url);
        if ($videoId === null) {
            return null;
        }

        return [
            'type' => 'VIDEO',
            'provider' => 'YOUTUBE',
            'video_id' => $videoId,
            'embed_url' => self::embedUrl($videoId),
            'thumbnail_url' => $thumbnailUrl ?? self::thumbnailUrl($videoId),
        ];
    }
}
