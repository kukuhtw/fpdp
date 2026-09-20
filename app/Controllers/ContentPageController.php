<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Services\Content\PostService;
use App\Services\Profile\ProfileService;

final class ContentPageController
{
    public function __construct(
        private readonly PostService $posts,
        private readonly ProfileService $profiles,
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

    public function cvManager(): string
    {
        return View::render('dashboard-cv', ['title' => 'CV & Resume · FPDP']);
    }

    public function publicCv(string $handle): string
    {
        $profile = $this->profiles->getPublicProfile($handle);

        return View::render('public-cv', ['title' => 'CV ' . $profile['display_name'] . ' · FPDP', 'profile' => $profile]);
    }
}
