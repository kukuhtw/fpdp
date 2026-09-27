<!doctype html>
<html lang="<?= \App\Core\View::lang() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/minimal/assets/theme.css"><?= \App\Core\View::i18nHead() ?></head>
<body>
<?php \App\Core\View::partial('topnav', ['navClass' => 'mn-nav', 'brandClass' => 'mn-brand', 'linksClass' => 'mn-nav-links']); ?>
<main class="mn-main mn-main-narrow">
  <?php \App\Core\View::partial('post-card', ['post' => $post]); ?>
</main>
</body></html>
