<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <?= \App\Core\View::themeStylesheetTag() ?>
</head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell">
    <header class="page-heading"><p class="eyebrow">Discover</p><h1>Local timeline</h1><p>Published posts from this FPDP installation.</p></header>
    <section class="feed">
        <?php if ($posts === []): ?><div class="empty">No published posts yet.</div><?php endif; ?>
        <?php foreach ($posts as $post): require __DIR__ . '/partials/post-card.php'; endforeach; ?>
    </section>
    <?php if ($nextCursor !== null): ?><a class="button secondary" href="/timeline?cursor=<?= rawurlencode($nextCursor) ?>">Older posts</a><?php endif; ?>
</main>
<script src="/assets/topnav-auth.js" defer></script>
</body>
</html>
