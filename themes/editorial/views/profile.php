<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/editorial/assets/theme.css"></head>
<body>
<nav class="ed-nav"><a class="ed-brand" href="/">FPDP</a><div class="ed-nav-links"><a href="/">Beranda</a><a href="/about-me">Tentang</a><a href="/youtube">YouTube</a><a href="/coretan">Coretan</a><a href="/about">About FPDP</a><a href="/dashboard">Dashboard</a><a href="/dashboard/about-me" class="owner-nav hidden">Edit About Me</a><a href="/dashboard/coretan" class="owner-nav hidden">Kelola Coretan</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a><a href="/dashboard/themes" class="owner-nav hidden">Template</a><a href="/timeline">Timeline</a></div></nav>

<header class="ed-hero">
  <p class="ed-eyebrow">Digital home &mdash; edisi editorial</p>
  <h1><?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="ed-handle">@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?></p>
  <?php if ($profile['bio'] !== null): ?><p class="ed-bio"><?= nl2br(\App\Core\View::autolink(htmlspecialchars((string) $profile['bio'], ENT_QUOTES, 'UTF-8'))) ?></p><?php endif; ?>
</header>

<main class="ed-main">
  <section class="ed-follow">
    <p class="ed-eyebrow">Federasi</p>
    <h2>Cara mengikuti (follow) <?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p>Situs ini adalah node FPDP independen &mdash; bukan platform terpusat. Untuk mengikuti, buka dashboard Federasi di node FPDP Anda sendiri, lalu kirim permintaan follow ke alamat berikut:</p>
    <label>Alamat federasi<div class="ed-field-row"><input type="text" readonly onclick="this.select()" value="@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>@<?= htmlspecialchars((string) $profile['node_domain'], ENT_QUOTES, 'UTF-8') ?>"></div></label>
    <label>Actor URI<div class="ed-field-row"><input type="text" readonly onclick="this.select()" value="https://<?= htmlspecialchars((string) $profile['node_domain'], ENT_QUOTES, 'UTF-8') ?>/@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>"></div></label>
    <p class="ed-muted">Permintaan Anda berstatus <strong>Pending</strong> sampai di-approve dari <a href="/dashboard/federation">/dashboard/federation</a>.</p>
  </section>

  <section class="ed-feed">
    <?php if ($posts === []): ?><div class="ed-empty">Belum ada tulisan yang diterbitkan.</div><?php endif; ?>
    <?php foreach ($posts as $post): \App\Core\View::partial('post-card', ['post' => $post]); endforeach; ?>
  </section>
</main>
</body></html>
