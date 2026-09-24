<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\Content\PostService;
use App\Services\Profile\ProfileService;
use App\Services\Theme\ThemeService;
use App\Repositories\ExternalPostRepository;
use App\Services\External\YouTubeEmbedResolver;

final class ContentPageController
{
    public function __construct(
        private readonly PostService $posts,
        private readonly ProfileService $profiles,
        private readonly ?ExternalPostRepository $externalPosts = null,
        private readonly ?ThemeService $themes = null,
    ) {
    }

    private function activeThemeForNode(int $nodeId): ?string
    {
        return $this->themes?->getActiveSlug($nodeId);
    }

    public function about(): string
    {
        return View::renderThemed(
            'about',
            ['title' => 'About FPDP · Federated Personal Digital Platform'],
            $this->themes?->getActiveSlugForFirstNode(),
        );
    }

    public function timeline(array $query = []): string
    {
        $result = $this->posts->list($query);

        return View::renderThemed('timeline', [
            'title' => 'Local Timeline · FPDP',
            'posts' => $result['items'],
            'nextCursor' => $result['next_cursor'],
        ], $this->themes?->getActiveSlugForFirstNode());
    }

    public function profile(string $handle, array $query = []): string
    {
        $profile = $this->profiles->getPublicProfile($handle);
        $result = $this->posts->list(array_merge($query, ['author_handle' => $profile['handle']]));
        return View::renderThemed('profile', [
            'title' => $profile['display_name'] . ' · FPDP',
            'profile' => $profile,
            'posts' => $result['items'],
            'nextCursor' => $result['next_cursor'],
        ], $this->activeThemeForNode((int) $profile['node_id']));
    }

    public function aboutMe(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);
        return View::renderThemed('about-me', ['title' => 'About Me · ' . $profile['display_name'], 'profile' => $profile], $this->activeThemeForNode((int) $profile['node_id']));
    }

    public function youtube(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);
        return View::renderThemed('youtube', [
            'title' => 'YouTube · ' . $profile['display_name'],
            'profile' => $profile,
            'youtubeVideos' => $this->youtubeVideos((int) $profile['user_id']),
        ], $this->activeThemeForNode((int) $profile['node_id']));
    }

    /** @return array<int, array<string, mixed>> */
    private function youtubeVideos(int $userId): array
    {
        if ($this->externalPosts === null) {
            return [];
        }

        $videos = [];
        foreach ($this->externalPosts->listYouTubeByUserId($userId) as $row) {
            $media = json_decode((string) ($row['media_json'] ?? '[]'), true);
            if (!is_array($media)) {
                continue;
            }
            foreach ($media as $item) {
                $videoId = is_array($item) ? (string) ($item['video_id'] ?? '') : '';
                if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
                    continue;
                }
                $videos[] = [
                    'video_id' => $videoId,
                    'embed_url' => YouTubeEmbedResolver::embedUrl($videoId),
                    'watch_url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
                    'thumbnail_url' => YouTubeEmbedResolver::thumbnailUrl($videoId),
                    'title' => mb_substr(trim((string) ($row['title'] ?? '')) ?: trim(strip_tags((string) ($row['content'] ?? ''))) ?: 'Video YouTube', 0, 180),
                    'author_name' => (string) ($row['author_name'] ?? 'YouTube'),
                    'published_at' => $row['published_at'] ?? null,
                ];
                break;
            }
        }
        return $videos;
    }

    public function post(string $publicId): string
    {
        $post = $this->posts->get($publicId);

        return View::renderThemed('post', [
            'title' => ($post['title'] ?: 'Post by ' . $post['display_name']) . ' · FPDP',
            'post' => $post,
        ], $this->activeThemeForNode((int) $post['node_id']));
    }

    public function editor(): string
    {
        return View::render('post-editor', ['title' => 'Post Editor · FPDP']);
    }

    public function dashboardOverview(): string
    {
        return View::render('dashboard-overview', ['title' => 'Dashboard · FPDP']);
    }

    public function postsList(): string
    {
        return View::render('posts-list', ['title' => 'My Posts · FPDP']);
    }

    public function integrations(): string
    {
        return View::render('dashboard-integrations', ['title' => 'Integrations · FPDP']);
    }

    public function settings(): string
    {
        return View::render('dashboard-settings', ['title' => 'Settings · FPDP']);
    }

    public function aboutMeManager(): string
    {
        return View::render('dashboard-about-me', ['title' => 'About Me · Dashboard · FPDP']);
    }

    public function cvManager(): string
    {
        return View::render('dashboard-cv', ['title' => 'CV & Resume · FPDP']);
    }

    public function publicCv(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);

        return View::renderThemed('public-cv', ['title' => 'CV ' . $profile['display_name'] . ' · FPDP', 'profile' => $profile], $this->activeThemeForNode((int) $profile['node_id']));
    }

    public function wallCoretan(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);

        return View::renderThemed('wall-coretan', ['title' => 'Coretan · ' . $profile['display_name'], 'profile' => $profile], $this->activeThemeForNode((int) $profile['node_id']));
    }

    public function wallCoretanManager(): string
    {
        return View::render('dashboard-wall-coretan', ['title' => 'Coretan · Dashboard · FPDP']);
    }

    public function federationManager(): string
    {
        return View::render('dashboard-federation', ['title' => 'Federasi · Dashboard · FPDP']);
    }

    public function themeManager(): string
    {
        return View::render('dashboard-themes', ['title' => 'Template · Dashboard · FPDP']);
    }
}
