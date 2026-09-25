<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/editorial/assets/theme.css"></head>
<body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav', ['navClass' => 'ed-nav', 'brandClass' => 'ed-brand', 'linksClass' => 'ed-nav-links']); ?>

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

  <section id="chatbot-widget" class="panel hidden">
    <p class="eyebrow">Tanya AI</p>
    <h2>Chatbot <?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p id="chatbot-price-note" class="muted"></p>
    <div id="chatbot-signin" class="hidden"><a id="chatbot-google-login" class="button" href="#">Masuk dengan Google untuk chat</a></div>
    <div id="chatbot-chat" class="hidden stack">
      <p id="chatbot-wallet-note" class="muted"></p>
      <div class="field-row"><input id="chatbot-topup-amount" type="number" min="1000" step="1000" value="10000" style="width:8rem"><button id="chatbot-topup-button" type="button" class="secondary">Top up saldo</button></div>
      <div id="chatbot-log"></div>
      <form id="chatbot-ask-form" class="field-row"><input id="chatbot-question-input" type="text" placeholder="Tulis pertanyaan Anda…" maxlength="1000" required style="flex:1"><button type="submit">Kirim</button></form>
    </div>
    <p id="chatbot-status" class="status" role="status" aria-live="polite"></p>
    <p id="chatbot-payment-confirm-link"></p>
  </section>

  <section class="ed-feed">
    <?php if ($posts === []): ?><div class="ed-empty">Belum ada tulisan yang diterbitkan.</div><?php endif; ?>
    <?php foreach ($posts as $post): \App\Core\View::partial('post-card', ['post' => $post]); endforeach; ?>
  </section>
</main>
<script src="/assets/topnav-auth.js" defer></script>
<script src="/assets/lightbox.js" defer></script>
<script src="/assets/chatbot-widget.js" defer></script>
</body></html>
