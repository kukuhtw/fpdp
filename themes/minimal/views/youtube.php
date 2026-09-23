<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/minimal/assets/theme.css"></head>
<body>
<nav class="mn-nav"><a class="mn-brand" href="/">FPDP</a><div class="mn-nav-links"><a href="/">Home</a><a href="/about-me">About Me</a><a href="/youtube">YouTube</a><a href="/coretan">Coretan</a><a href="/about">About FPDP</a><a href="/timeline">Timeline</a><a href="/dashboard" class="owner-nav hidden">Dashboard</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a><a href="/dashboard/themes" class="owner-nav hidden">Template</a></div></nav>
<main class="mn-main">
  <header class="mn-panel">
    <p class="mn-eyebrow">Channel terhubung</p>
    <h1>Video YouTube</h1>
    <p class="mn-muted">Video terbaru hasil syndication channel <?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?>.</p>
  </header>
  <?php if (($youtubeVideos ?? []) === []): ?>
    <p class="mn-empty">Belum ada video YouTube yang tersinkronisasi.</p>
  <?php else: ?>
    <div class="mn-youtube-grid">
      <?php foreach ($youtubeVideos as $video): ?>
        <article class="mn-youtube-card">
          <div class="mn-youtube-frame"><iframe src="<?= htmlspecialchars((string) $video['embed_url'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars((string) $video['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>
          <div class="mn-youtube-copy">
            <h3><?= htmlspecialchars((string) $video['title'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars((string) $video['author_name'], ENT_QUOTES, 'UTF-8') ?><?php if ($video['published_at'] !== null): ?> · <?= htmlspecialchars(date('j M Y', strtotime((string) $video['published_at'])), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
            <a href="<?= htmlspecialchars((string) $video['watch_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Tonton di YouTube ↗</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</body></html>
