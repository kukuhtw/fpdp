<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/minimal/assets/theme.css"></head>
<body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav', ['navClass' => 'mn-nav', 'brandClass' => 'mn-brand', 'linksClass' => 'mn-nav-links']); ?>

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

  <section id="chatbot-widget" class="panel hidden">
    <p class="eyebrow">Tanya AI</p>
    <h2>Chatbot <?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p id="chatbot-price-note" class="muted"></p>
    <div id="chatbot-signin" class="hidden"><a id="chatbot-google-login" class="button" href="#">Masuk dengan Google untuk chat</a></div>
    <div id="chatbot-chat" class="hidden stack">
      <p id="chatbot-buyer-identity" class="muted hidden"></p>
      <p id="chatbot-wallet-note" class="muted"></p>
      <div class="field-row"><input id="chatbot-topup-name" type="text" maxlength="255" placeholder="Nama"><input id="chatbot-topup-phone" type="tel" maxlength="30" placeholder="Nomor telepon"></div>
      <div class="field-row"><input id="chatbot-topup-amount" type="number" min="1000" step="1000" value="10000" style="width:8rem"><button id="chatbot-topup-button" type="button" class="secondary">Top up saldo</button></div>
      <div id="chatbot-log"></div>
      <form id="chatbot-ask-form" class="field-row"><input id="chatbot-question-input" type="text" placeholder="Tulis pertanyaan Anda…" maxlength="1000" required style="flex:1"><button type="submit">Kirim</button></form>
    </div>
    <p id="chatbot-status" class="status" role="status" aria-live="polite"></p>
    <p id="chatbot-payment-confirm-link"></p>
  </section>

  <section class="mn-feed">
    <?php if ($posts === []): ?><p class="mn-empty">No published posts yet.</p><?php endif; ?>
    <?php foreach ($posts as $post): \App\Core\View::partial('post-card', ['post' => $post]); endforeach; ?>
  </section>
</main>
<script src="/assets/topnav-auth.js" defer></script>
<script src="/assets/lightbox.js" defer></script>
<script src="/assets/chatbot-widget.js" defer></script>
</body></html>
