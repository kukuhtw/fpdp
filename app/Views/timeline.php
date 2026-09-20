<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/">Home</a><a href="/about">About</a><a href="/dashboard">Dashboard</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/timeline">Timeline</a></div></nav>
<main class="shell">
    <header class="page-heading"><p class="eyebrow">Discover</p><h1>Local timeline</h1><p>Published posts from this FPDP installation.</p></header>
    <section class="feed">
        <?php if ($posts === []): ?><div class="empty">No published posts yet.</div><?php endif; ?>
        <?php foreach ($posts as $post): require __DIR__ . '/partials/post-card.php'; endforeach; ?>
    </section>
    <?php if ($nextCursor !== null): ?><a class="button secondary" href="/timeline?cursor=<?= rawurlencode($nextCursor) ?>">Older posts</a><?php endif; ?>
</main>
</body>
</html>
