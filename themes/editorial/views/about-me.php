<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/editorial/assets/theme.css"></head>
<body>
<?php \App\Core\View::partial('topnav', ['navClass' => 'ed-nav', 'brandClass' => 'ed-brand', 'linksClass' => 'ed-nav-links']); ?>
<main class="ed-main">
  <article class="ed-panel">
    <p class="ed-eyebrow">Tentang pemilik</p>
    <h1><?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if (trim((string) ($profile['bio'] ?? '')) !== ''): ?>
      <div class="ed-prose"><?= nl2br(\App\Core\View::autolink(htmlspecialchars((string) $profile['bio'], ENT_QUOTES, 'UTF-8'))) ?></div>
    <?php else: ?>
      <p class="ed-empty">Konten About Me belum tersedia.</p>
    <?php endif; ?>
  </article>
</main>
</body></html>
