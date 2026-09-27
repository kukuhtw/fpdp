<!doctype html>
<html lang="<?= \App\Core\View::lang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <?= \App\Core\View::themeStylesheetTag() ?>
</head>
<body>
<?php \App\Core\View::partial('topnav', ['navClass' => 'mn-nav', 'brandClass' => 'mn-brand', 'linksClass' => 'mn-nav-links']); ?>
<main class="shell">
    <header class="page-heading"><p class="eyebrow"><?= \App\Core\View::te('timeline.eyebrow') ?></p><h1><?= \App\Core\View::te('timeline.heading') ?></h1><p><?= \App\Core\View::te('timeline.intro') ?></p></header>
    <section class="feed">
        <?php if ($posts === []): ?><div class="empty"><?= \App\Core\View::te('timeline.empty') ?></div><?php endif; ?>
        <?php foreach ($posts as $post): \App\Core\View::partial('post-card', ['post' => $post, 'excerpt' => true]); endforeach; ?>
    </section>
    <?php if ($nextCursor !== null): ?><a class="button secondary" href="/timeline?cursor=<?= rawurlencode($nextCursor) ?>"><?= \App\Core\View::te('timeline.older') ?></a><?php endif; ?>
</main>
<script src="/assets/topnav-auth.js" defer></script>
<script src="/assets/lightbox.js" defer></script>
</body>
</html>
