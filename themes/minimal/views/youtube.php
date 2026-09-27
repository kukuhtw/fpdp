<!doctype html>
<html lang="<?= \App\Core\View::lang() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/minimal/assets/theme.css"><?= \App\Core\View::i18nHead() ?></head>
<body>
<?php \App\Core\View::partial('topnav', ['navClass' => 'mn-nav', 'brandClass' => 'mn-brand', 'linksClass' => 'mn-nav-links']); ?>
<main class="mn-main">
  <header class="mn-panel">
    <p class="mn-eyebrow"><?= \App\Core\View::te('youtube.eyebrow') ?></p>
    <h1><?= \App\Core\View::te('youtube.heading') ?></h1>
    <p class="mn-muted"><?= \App\Core\View::te('youtube.intro', ['name' => (string) $profile['display_name']]) ?></p>
  </header>
  <?php if (($youtubeVideos ?? []) === []): ?>
    <p class="mn-empty"><?= \App\Core\View::te('youtube.empty') ?></p>
  <?php else: ?>
    <div class="mn-youtube-grid">
      <?php foreach ($youtubeVideos as $video): ?>
        <article class="mn-youtube-card">
          <div class="mn-youtube-frame"><iframe src="<?= htmlspecialchars((string) $video['embed_url'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars((string) $video['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>
          <div class="mn-youtube-copy">
            <h3><?= htmlspecialchars((string) $video['title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars((string) $video['author_name'], ENT_QUOTES, 'UTF-8') ?><?php if ($video['published_at'] !== null): ?> · <?= htmlspecialchars(date('j M Y', strtotime((string) $video['published_at'])), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
            <a href="<?= htmlspecialchars((string) $video['watch_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= \App\Core\View::te('youtube.watch') ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</body></html>
