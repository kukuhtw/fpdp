<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><a href="/@<?= rawurlencode((string) $profile['handle']) ?>/cv">CV / Resume</a><a href="/timeline">Timeline</a></nav>
<main class="shell">
    <header class="profile-hero">
        <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) $profile['display_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
        <div><p class="eyebrow">@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?></p><h1><?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($profile['bio'] !== null): ?><p><?= nl2br(htmlspecialchars((string) $profile['bio'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?></div>
    </header>
    <section class="feed">
        <?php if ($posts === []): ?><div class="empty">No published posts yet.</div><?php endif; ?>
        <?php foreach ($posts as $post): require __DIR__ . '/partials/post-card.php'; endforeach; ?>
    </section>
</main>
</body>
</html>
