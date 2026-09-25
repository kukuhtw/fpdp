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

    /**
     * Post-payment landing page for every checkout flow (product purchase,
     * chatbot wallet top-up, CV/resume access) that redirects the visitor
     * to a gateway-hosted checkout page. Entirely client-rendered — the
     * query string (type/handle/ref) tells payment-thank-you.js which
     * status endpoint to poll, since the gateway redirect itself carries no
     * trustworthy payment outcome (that's confirmed server-side by the
     * webhook, not this browser redirect).
     */
    public function paymentThankYou(): string
    {
        return View::render('payment-thank-you', ['title' => 'Payment · FPDP']);
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
        $query['source_type'] = $query['source_type'] ?? 'ALL';
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
        return View::render('post-editor', [
            'title' => 'Post Editor · FPDP',
            'mediaMaxFileSizeBytes' => (int) \App\Core\Config::get('MEDIA_MAX_FILE_SIZE_BYTES', '10485760'),
        ]);
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
        return View::render('dashboard-about-me', [
            'title' => 'About Me · Dashboard · FPDP',
            'mediaMaxFileSizeBytes' => (int) \App\Core\Config::get('MEDIA_MAX_FILE_SIZE_BYTES', '10485760'),
        ]);
    }

    public function cvManager(): string
    {
        return View::render('dashboard-cv', ['title' => 'CV & Resume · FPDP']);
    }

    public function ragManager(): string
    {
        return View::render('dashboard-rag', ['title' => 'RAG Documents · Dashboard · FPDP']);
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

    public function shop(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);

        return View::renderThemed('shop', [
            'title' => 'Shop · ' . $profile['display_name'],
            'profile' => $profile,
        ], $this->activeThemeForNode((int) $profile['node_id']));
    }

    public function product(string $handle, string $productId): string
    {
        $profile = $this->profiles->getPublicProfile($handle);

        return View::renderThemed('product', [
            'title' => 'Product · ' . $profile['display_name'],
            'profile' => $profile,
            'productId' => $productId,
        ], $this->activeThemeForNode((int) $profile['node_id']));
    }

    public function productsManager(): string
    {
        return View::render('dashboard-products', [
            'title' => 'Products · Dashboard · FPDP',
            'mediaMaxFileSizeBytes' => (int) \App\Core\Config::get('MEDIA_MAX_FILE_SIZE_BYTES', '10485760'),
            'productAssetMaxFileSizeBytes' => (int) \App\Core\Config::get('PRODUCT_ASSET_MAX_FILE_SIZE_BYTES', '20971520'),
        ]);
    }

    public function ordersManager(): string
    {
        return View::render('dashboard-orders', ['title' => 'Orders · Dashboard · FPDP']);
    }

    public function paymentsManager(): string
    {
        return View::render('dashboard-payments', ['title' => 'Payments · Dashboard · FPDP']);
    }
}
