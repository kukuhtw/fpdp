<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/minimal/assets/theme.css"></head>
<body>
<nav class="mn-nav"><a class="mn-brand" href="/">FPDP</a><div class="mn-nav-links"><a href="/">Home</a><a href="/about">About FPDP</a><a href="/dashboard">Dashboard</a><a href="/timeline">Timeline</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a><a href="/dashboard/themes" class="owner-nav hidden">Template</a></div></nav>
<main class="mn-main mn-main-narrow">
  <?php \App\Core\View::partial('post-card', ['post' => $post]); ?>
</main>
</body></html>
