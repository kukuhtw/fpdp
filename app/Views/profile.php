<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/">Home</a><a href="/about">About</a><a href="/dashboard">Dashboard</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/timeline">Timeline</a><a href="/@<?= rawurlencode((string) $profile['handle']) ?>/cv">CV</a></div></nav>
<main class="shell">
    <header class="profile-hero">
        <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) $profile['display_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
        <div><p class="eyebrow">@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?></p><h1><?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($profile['bio'] !== null): ?><p><?= nl2br(htmlspecialchars((string) $profile['bio'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?></div>
    </header>
    <?php if (($youtubeVideos ?? []) !== []): ?>
    <section class="youtube-section" aria-labelledby="youtube-heading">
        <div class="section-heading"><div><p class="eyebrow">Channel terhubung</p><h2 id="youtube-heading">Video YouTube terbaru</h2></div></div>
        <div class="youtube-grid">
            <?php foreach ($youtubeVideos as $video): ?>
            <article class="youtube-card">
                <div class="youtube-frame">
                    <iframe
                        src="<?= htmlspecialchars((string) $video['embed_url'], ENT_QUOTES, 'UTF-8') ?>"
                        title="<?= htmlspecialchars((string) $video['title'], ENT_QUOTES, 'UTF-8') ?>"
                        loading="lazy"
                        referrerpolicy="strict-origin-when-cross-origin"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        allowfullscreen></iframe>
                </div>
                <div class="youtube-copy">
                    <h3><?= htmlspecialchars((string) $video['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="muted"><?= htmlspecialchars((string) $video['author_name'], ENT_QUOTES, 'UTF-8') ?><?php if ($video['published_at'] !== null): ?> · <?= htmlspecialchars(date('j M Y', strtotime((string) $video['published_at'])), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
                    <a href="<?= htmlspecialchars((string) $video['watch_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Tonton di YouTube ↗</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
    <section class="feed">
        <?php if ($posts === []): ?><div class="empty">No published posts yet.</div><?php endif; ?>
        <?php foreach ($posts as $post): require __DIR__ . '/partials/post-card.php'; endforeach; ?>
    </section>
</main>
</body>
</html>
