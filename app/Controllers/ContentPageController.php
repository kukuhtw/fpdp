<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\Content\PostService;
use App\Services\Profile\ProfileService;
use App\Repositories\ExternalPostRepository;
use App\Services\External\YouTubeEmbedResolver;

final class ContentPageController
{
    public function __construct(
        private readonly PostService $posts,
        private readonly ProfileService $profiles,
        private readonly ?ExternalPostRepository $externalPosts = null,
    ) {
    }

    public function about(): string
    {
        return View::render('about', ['title' => 'About FPDP · Federated Personal Digital Platform']);
    }

    public function timeline(array $query = []): string
    {
        $result = $this->posts->list($query);

        return View::render('timeline', [
            'title' => 'Local Timeline · FPDP',
            'posts' => $result['items'],
            'nextCursor' => $result['next_cursor'],
        ]);
    }

    public function profile(string $handle, array $query = []): string
    {
        $profile = $this->profiles->getPublicProfile($handle);
        $result = $this->posts->list(array_merge($query, ['author_handle' => $profile['handle']]));
        return View::render('profile', [
            'title' => $profile['display_name'] . ' · FPDP',
            'profile' => $profile,
            'posts' => $result['items'],
            'nextCursor' => $result['next_cursor'],
        ]);
    }

    public function aboutMe(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);
        return View::render('about-me', ['title' => 'About Me · ' . $profile['display_name'], 'profile' => $profile]);
    }

    public function youtube(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);
        return View::render('youtube', [
            'title' => 'YouTube · ' . $profile['display_name'],
            'profile' => $profile,
            'youtubeVideos' => $this->youtubeVideos((int) $profile['user_id']),
        ]);
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

        return View::render('post', [
            'title' => ($post['title'] ?: 'Post by ' . $post['display_name']) . ' · FPDP',
            'post' => $post,
        ]);
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

        return View::render('public-cv', ['title' => 'CV ' . $profile['display_name'] . ' · FPDP', 'profile' => $profile]);
    }

    public function wallCoretan(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);

        return View::render('wall-coretan', ['title' => 'Coretan · ' . $profile['display_name'], 'profile' => $profile]);
    }

    public function wallCoretanManager(): string
    {
        return View::render('dashboard-wall-coretan', ['title' => 'Coretan · Dashboard · FPDP']);
    }

    public function federationManager(): string
    {
        return View::render('dashboard-federation', ['title' => 'Federasi · Dashboard · FPDP']);
    }
}
