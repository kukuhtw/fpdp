<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/editorial/assets/theme.css"></head>
<body>
<nav class="ed-nav"><a class="ed-brand" href="/">FPDP</a><div class="ed-nav-links"><a href="/">Beranda</a><a href="/about">About FPDP</a><a href="/dashboard">Dashboard</a><a href="/timeline">Timeline</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a><a href="/dashboard/themes" class="owner-nav hidden">Template</a></div></nav>
<main class="ed-main ed-main-narrow">
  <?php \App\Core\View::partial('post-card', ['post' => $post]); ?>
</main>
</body></html>
