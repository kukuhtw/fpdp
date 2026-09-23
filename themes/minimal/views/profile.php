<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/minimal/assets/theme.css"></head>
<body>
<nav class="mn-nav"><a class="mn-brand" href="/">FPDP</a><div class="mn-nav-links"><a href="/">Home</a><a href="/about-me">About Me</a><a href="/youtube">YouTube</a><a href="/coretan">Coretan</a><a href="/about">About FPDP</a><a href="/dashboard">Dashboard</a><a href="/dashboard/about-me" class="owner-nav hidden">Edit About Me</a><a href="/dashboard/coretan" class="owner-nav hidden">Kelola Coretan</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a><a href="/dashboard/themes" class="owner-nav hidden">Template</a><a href="/timeline">Timeline</a></div></nav>

<main class="mn-main">
  <header class="mn-hero">
    <div class="mn-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) $profile['display_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
    <div>
      <h1><?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="mn-handle">@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?></p>
      <?php if ($profile['bio'] !== null): ?><p class="mn-bio"><?= nl2br(\App\Core\View::autolink(htmlspecialchars((string) $profile['bio'], ENT_QUOTES, 'UTF-8'))) ?></p><?php endif; ?>
    </div>
  </header>

  <section class="mn-follow">
    <h2>Cara mengikuti (follow)</h2>
    <p>Node FPDP independen &mdash; kirim permintaan follow dari dashboard Federasi milik Anda sendiri ke:</p>
    <label>Alamat federasi<div class="mn-field-row"><input type="text" readonly onclick="this.select()" value="@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>@<?= htmlspecialchars((string) $profile['node_domain'], ENT_QUOTES, 'UTF-8') ?>"></div></label>
    <label>Actor URI<div class="mn-field-row"><input type="text" readonly onclick="this.select()" value="https://<?= htmlspecialchars((string) $profile['node_domain'], ENT_QUOTES, 'UTF-8') ?>/@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>"></div></label>
    <p class="mn-muted">Status permintaan: Pending sampai disetujui di <a href="/dashboard/federation">/dashboard/federation</a>.</p>
  </section>

  <section class="mn-feed">
    <?php if ($posts === []): ?><p class="mn-empty">No published posts yet.</p><?php endif; ?>
    <?php foreach ($posts as $post): \App\Core\View::partial('post-card', ['post' => $post]); endforeach; ?>
  </section>
</main>
</body></html>
