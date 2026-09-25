<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\View;

function tn_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Every themed view must render through \App\Core\View::partial('topnav'),
 * the single source of truth for the site's link list — not a hardcoded
 * per-theme copy, which is exactly how themes/editorial and themes/minimal
 * silently drifted out of sync with each other and with the un-themed
 * partial (missing /shop, /dashboard/payments, etc. depending on which
 * page and which theme a visitor happened to be on).
 *
 * @return array<string, mixed>
 */
function tn_dataFor(string $view): array
{
    $profile = ['handle' => 'alice', 'display_name' => 'Alice', 'bio' => null, 'avatar_url' => null, 'node_domain' => 'test.local'];

    return match ($view) {
        'youtube' => ['title' => 't', 'profile' => $profile, 'youtubeVideos' => []],
        'about-me' => ['title' => 't', 'profile' => $profile, 'aboutMeContent' => ''],
        'wall-coretan' => ['title' => 't', 'profile' => $profile, 'coretanPosts' => []],
        'post' => ['title' => 't', 'profile' => $profile, 'post' => ['title' => 'T', 'content' => 'C', 'slug' => null, 'id' => 1, 'published_at' => null, 'handle' => 'alice', 'display_name' => 'Alice']],
        'profile' => ['title' => 't', 'profile' => $profile, 'posts' => []],
        'public-cv' => ['title' => 't', 'profile' => $profile],
        default => ['title' => 't', 'profile' => $profile],
    };
}

$views = ['youtube', 'about-me', 'wall-coretan', 'post', 'profile', 'public-cv'];
$themes = [
    'editorial' => ['navClass' => 'ed-nav', 'brandClass' => 'ed-brand'],
    'minimal' => ['navClass' => 'mn-nav', 'brandClass' => 'mn-brand'],
];
$canonicalLinks = ['/shop', '/dashboard/payments', '/dashboard/rag', '/dashboard/products', '/cv'];

foreach ($themes as $themeSlug => $classes) {
    foreach ($views as $view) {
        $html = View::renderThemed($view, tn_dataFor($view), $themeSlug);

        tn_assert(
            str_contains($html, 'class="' . $classes['navClass'] . '"'),
            "{$themeSlug}/{$view} should render its own nav class ({$classes['navClass']}), not the bare core one",
        );
        foreach ($canonicalLinks as $link) {
            tn_assert(
                str_contains($html, 'href="' . $link . '"'),
                "{$themeSlug}/{$view} is missing {$link} from the canonical nav — it likely still hardcodes its own stale link list",
            );
        }
    }
}

fwrite(STDOUT, "Themed nav test passed\n");
