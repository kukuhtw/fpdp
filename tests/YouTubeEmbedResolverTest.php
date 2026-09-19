<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\External\AtomConnector;
use App\Services\External\RSSConnector;
use App\Services\External\YouTubeEmbedResolver;

$watchUrl = 'https://www.youtube.com/watch?v=jNQXAC9IVRw&feature=youtu.be';
if (YouTubeEmbedResolver::extractVideoId($watchUrl) !== 'jNQXAC9IVRw') {
    fwrite(STDERR, "Did not extract video ID from a watch URL\n");
    exit(1);
}

if (YouTubeEmbedResolver::extractVideoId('https://youtu.be/jNQXAC9IVRw') !== 'jNQXAC9IVRw') {
    fwrite(STDERR, "Did not extract video ID from a youtu.be short URL\n");
    exit(1);
}

if (YouTubeEmbedResolver::extractVideoId('https://www.youtube.com/embed/jNQXAC9IVRw') !== 'jNQXAC9IVRw') {
    fwrite(STDERR, "Did not extract video ID from an embed URL\n");
    exit(1);
}

if (YouTubeEmbedResolver::extractVideoId('https://example.com/article/42') !== null) {
    fwrite(STDERR, "Extracted a video ID from a non-YouTube URL\n");
    exit(1);
}

if (YouTubeEmbedResolver::embedUrl('jNQXAC9IVRw') !== 'https://www.youtube-nocookie.com/embed/jNQXAC9IVRw') {
    fwrite(STDERR, "Embed URL did not use the privacy-enhanced youtube-nocookie.com domain\n");
    exit(1);
}

$fixturesPath = sys_get_temp_dir() . '/fpdp-youtube-fixture-' . uniqid();
mkdir($fixturesPath);

// Mirrors the shape of a real YouTube channel feed
// (https://www.youtube.com/feeds/videos.xml?channel_id=...): an Atom feed
// with a yt:videoId element and a media:group thumbnail per entry.
file_put_contents($fixturesPath . '/channel.atom', <<<'ATOM'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/">
  <entry>
    <id>yt:video:jNQXAC9IVRw</id>
    <yt:videoId>jNQXAC9IVRw</yt:videoId>
    <title>Me at the zoo</title>
    <link rel="alternate" href="https://www.youtube.com/watch?v=jNQXAC9IVRw"/>
    <author><name>Studio Notes</name></author>
    <published>2005-04-23T14:31:52+00:00</published>
    <media:group>
      <media:thumbnail url="https://i.ytimg.com/vi/jNQXAC9IVRw/hqdefault.jpg"/>
      <media:description>The first video ever uploaded to YouTube.</media:description>
    </media:group>
  </entry>
</feed>
ATOM);

$atomItems = (new AtomConnector())->fetchPosts(['source_url' => $fixturesPath . '/channel.atom'])['items'];
if (count($atomItems) !== 1 || $atomItems[0]['type'] !== 'MEDIA') {
    fwrite(STDERR, "AtomConnector did not classify a YouTube channel entry as MEDIA\n");
    exit(1);
}

$atomEmbed = $atomItems[0]['media'][0] ?? null;
if (
    $atomEmbed === null
    || $atomEmbed['provider'] !== 'YOUTUBE'
    || $atomEmbed['video_id'] !== 'jNQXAC9IVRw'
    || $atomEmbed['embed_url'] !== 'https://www.youtube-nocookie.com/embed/jNQXAC9IVRw'
    || $atomEmbed['thumbnail_url'] !== 'https://i.ytimg.com/vi/jNQXAC9IVRw/hqdefault.jpg'
) {
    fwrite(STDERR, "AtomConnector did not normalize the YouTube embed metadata correctly\n");
    exit(1);
}

file_put_contents($fixturesPath . '/mixed.rss', <<<'RSS'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Studio Notes</title>
    <item>
      <title>A regular article</title>
      <link>https://studionotes.example/post/42</link>
      <guid>https://studionotes.example/post/42</guid>
      <description>Plain text post, no video.</description>
      <pubDate>Wed, 17 Sep 2026 08:00:00 +0000</pubDate>
    </item>
    <item>
      <title>Studio walkthrough video</title>
      <link>https://www.youtube.com/watch?v=jNQXAC9IVRw</link>
      <guid>https://www.youtube.com/watch?v=jNQXAC9IVRw</guid>
      <description>Video linked from a regular RSS feed.</description>
      <pubDate>Thu, 18 Sep 2026 08:00:00 +0000</pubDate>
    </item>
  </channel>
</rss>
RSS);

$rssItems = (new RSSConnector())->fetchPosts(['source_url' => $fixturesPath . '/mixed.rss'])['items'];
if (count($rssItems) !== 2) {
    fwrite(STDERR, "RSSConnector did not parse both fixture items\n");
    exit(1);
}

if ($rssItems[0]['type'] !== 'ARTICLE' || $rssItems[0]['media'] !== []) {
    fwrite(STDERR, "RSSConnector flagged a plain article as a video embed\n");
    exit(1);
}

if ($rssItems[1]['type'] !== 'MEDIA' || ($rssItems[1]['media'][0]['video_id'] ?? null) !== 'jNQXAC9IVRw') {
    fwrite(STDERR, "RSSConnector did not detect the YouTube link in a plain RSS item\n");
    exit(1);
}

unlink($fixturesPath . '/channel.atom');
unlink($fixturesPath . '/mixed.rss');
rmdir($fixturesPath);

fwrite(STDOUT, "YouTube embed resolver test passed\n");
