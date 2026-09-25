<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/minimal/assets/theme.css"></head>
<body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav', ['navClass' => 'mn-nav', 'brandClass' => 'mn-brand', 'linksClass' => 'mn-nav-links']); ?>
<main class="mn-main">
  <header class="profile-hero">
    <?php if (trim((string) ($profile['avatar_url'] ?? '')) !== ''): ?>
      <img class="avatar-photo" src="<?= htmlspecialchars((string) $profile['avatar_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Foto profil <?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?>">
    <?php else: ?>
      <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) $profile['display_name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div>
      <p class="eyebrow">@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?></p>
      <h1>CV / Resume</h1>
      <p><?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </header>
  <section class="panel">
    <div id="cv-details"><p class="muted">Memuat informasi CV…</p></div>
    <div id="cv-actions" class="stack hidden">
      <a id="google-login" class="button" href="/api/v1/profiles/<?= rawurlencode((string) $profile['handle']) ?>/visitor-auth/google/redirect">Masuk dengan Google</a>
      <button id="access-button" class="hidden">Beli akses</button>
      <button id="download-button" class="hidden">Download CV</button>
    </div>
    <p id="cv-status" class="status" role="status" aria-live="polite"></p>
    <p id="payment-confirm-link"></p>
  </section>
</main>
<script src="/assets/public-cv.js" defer></script>
</body></html>
