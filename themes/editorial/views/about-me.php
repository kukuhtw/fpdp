<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/editorial/assets/theme.css"></head>
<body>
<nav class="ed-nav"><a class="ed-brand" href="/">FPDP</a><div class="ed-nav-links"><a href="/">Beranda</a><a href="/about-me">Tentang</a><a href="/youtube">YouTube</a><a href="/coretan">Coretan</a><a href="/about">About FPDP</a><a href="/timeline">Timeline</a><a href="/dashboard" class="owner-nav hidden">Dashboard</a><a href="/dashboard/about-me" class="owner-nav hidden">Edit About Me</a><a href="/dashboard/coretan" class="owner-nav hidden">Kelola Coretan</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a><a href="/dashboard/themes" class="owner-nav hidden">Template</a></div></nav>
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
