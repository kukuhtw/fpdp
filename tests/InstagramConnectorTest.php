<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\External\InstagramConnector;

function ig_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// Username / URL normalization
ig_assert(InstagramConnector::extractUsername('kukuhtw') === 'kukuhtw', 'Bare username should be accepted');
ig_assert(InstagramConnector::extractUsername('@kukuhtw') === 'kukuhtw', 'Leading @ should be stripped');
ig_assert(InstagramConnector::extractUsername('https://www.instagram.com/kukuhtw/') === 'kukuhtw', 'Profile URL should be normalized');
ig_assert(InstagramConnector::extractUsername('https://www.instagram.com/kukuhtw/?hl=en') === 'kukuhtw', 'Query string should be ignored');
ig_assert(InstagramConnector::extractUsername('https://youtube.com/kukuhtw') === null, 'Non-Instagram host should be rejected');
ig_assert(InstagramConnector::extractUsername('') === null, 'Empty value should be rejected');

// Fake profile page: modern Instagram embeds render data as JSON <script> blobs.
$html = '<html><body><script type="application/json" data-sjs>' . json_encode([
    'require_login' => false,
    'data' => [
        'user' => [
            'edge_owner_to_timeline_media' => [
                'edges' => [
                    ['node' => [
                        'shortcode' => 'ABC123',
                        'display_url' => 'https://scontent.example/img1.jpg',
                        'is_video' => false,
                        'taken_at_timestamp' => 1758300000,
                        'edge_media_to_caption' => ['edges' => [['node' => ['text' => 'Hello from Instagram']]]],
                    ]],
                    ['node' => [
                        // Instagram often repeats the same node across multiple blobs on one page.
                        'shortcode' => 'ABC123',
                        'display_url' => 'https://scontent.example/img1.jpg',
                        'is_video' => false,
                        'taken_at_timestamp' => 1758300000,
                    ]],
                    ['node' => [
                        'shortcode' => 'XYZ789',
                        'display_url' => 'https://scontent.example/video1.jpg',
                        'is_video' => true,
                        'taken_at_timestamp' => 1758386400,
                        'edge_media_to_caption' => ['edges' => []],
                    ]],
                ],
            ],
        ],
    ],
]) . '</script></body></html>';

$seen = [];
$connector = new InstagramConnector(['http_requester' => function (string $url, array $headers) use (&$seen, $html): array {
    $seen = [$url, $headers];
    return ['status' => 200, 'headers' => [], 'url' => $url, 'body' => $html];
}]);

$result = $connector->fetchPosts(['source_url' => 'kukuhtw']);
ig_assert(!isset($result['error']), 'Unexpected error: ' . ($result['error'] ?? ''));
ig_assert(count($result['items']) === 2, 'Expected 2 unique posts after de-duplication, got ' . count($result['items']));
ig_assert($result['items'][0]['external_id'] === 'ABC123', 'First post shortcode mismatch');
ig_assert($result['items'][0]['canonical_url'] === 'https://www.instagram.com/p/ABC123/', 'Canonical URL was not built from the shortcode');
ig_assert($result['items'][0]['media'][0]['type'] === 'IMAGE', 'First post should be normalized as an image');
ig_assert($result['items'][0]['text'] === 'Hello from Instagram', 'Caption was not extracted from edge_media_to_caption');
ig_assert($result['items'][1]['media'][0]['type'] === 'VIDEO', 'Second post should be normalized as a video');
ig_assert($seen[0] === 'https://www.instagram.com/kukuhtw/', 'Connector did not request the normalized profile URL');
ig_assert(($seen[1]['User-Agent'] ?? '') !== '', 'A browser-like User-Agent header should be sent to avoid trivial bot blocking');

// Missing/invalid username never reaches the network.
$missing = $connector->fetchPosts(['source_url' => '']);
ig_assert(isset($missing['error']), 'Missing username should return a sync error, not throw');

// Login wall (Instagram demanding auth) must be surfaced as a clear error, not an empty success.
$loginWallHtml = '<html><head><title>Login • Instagram</title></head><body>Log in to see photos and videos.</body></html>';
$blockedConnector = new InstagramConnector(['http_requester' => function (string $url, array $headers) use ($loginWallHtml): array {
    return ['status' => 200, 'headers' => [], 'url' => $url, 'body' => $loginWallHtml];
}]);
$blocked = $blockedConnector->fetchPosts(['source_url' => 'kukuhtw']);
ig_assert(isset($blocked['error']), 'A login wall should be surfaced as a sync error');

fwrite(STDOUT, "Instagram connector test passed\n");
