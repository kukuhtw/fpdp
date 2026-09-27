<!doctype html>
<html lang="<?= \App\Core\View::lang() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/themes/editorial/assets/theme.css"><?= \App\Core\View::i18nHead() ?></head>
<body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav', ['navClass' => 'ed-nav', 'brandClass' => 'ed-brand', 'linksClass' => 'ed-nav-links']); ?>

<header class="ed-hero">
  <p class="ed-eyebrow"><?= \App\Core\View::te('profile.editorial_eyebrow') ?></p>
  <h1><?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="ed-handle">@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?></p>
  <?php if ($profile['bio'] !== null): ?><details class="collapsible profile-bio"><summary><?= \App\Core\View::te('profile.about_toggle') ?></summary><p class="ed-bio"><?= nl2br(\App\Core\View::autolink(htmlspecialchars((string) $profile['bio'], ENT_QUOTES, 'UTF-8'))) ?></p></details><?php endif; ?>
</header>

<main class="ed-main">
  <section class="ed-follow">
    <p class="ed-eyebrow"><?= \App\Core\View::te('profile.federation_eyebrow') ?></p>
    <details class="collapsible"><summary><h2><?= \App\Core\View::te('profile.follow_heading', ['name' => (string) $profile['display_name']]) ?></h2></summary>
    <p><?= \App\Core\View::te('profile.follow_intro_short') ?></p>
    <label><?= \App\Core\View::te('profile.federation_address') ?><div class="ed-field-row"><input type="text" readonly onclick="this.select()" value="@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>@<?= htmlspecialchars((string) $profile['node_domain'], ENT_QUOTES, 'UTF-8') ?>"></div></label>
    <label><?= \App\Core\View::te('profile.actor_uri') ?><div class="ed-field-row"><input type="text" readonly onclick="this.select()" value="https://<?= htmlspecialchars((string) $profile['node_domain'], ENT_QUOTES, 'UTF-8') ?>/@<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>"></div></label>
    <p class="ed-muted"><?= \App\Core\View::te('profile.pending_short_before') ?><strong><?= \App\Core\View::te('profile.pending_status') ?></strong><?= \App\Core\View::te('profile.pending_short_middle') ?><a href="/dashboard/federation">/dashboard/federation</a>.</p></details>
  </section>

  <section id="chatbot-widget" class="panel hidden">
    <p class="eyebrow"><?= \App\Core\View::te('profile.chatbot_eyebrow') ?></p>
    <details class="collapsible" id="chatbot-details"><summary><h2><?= \App\Core\View::te('profile.chatbot_heading', ['name' => (string) $profile['display_name']]) ?></h2></summary>
    <p id="chatbot-price-note" class="muted"></p>
    <div id="chatbot-signin" class="hidden"><a id="chatbot-google-login" class="button" href="#"><?= \App\Core\View::te('profile.chatbot_signin') ?></a></div>
    <div id="chatbot-chat" class="hidden stack">
      <p id="chatbot-buyer-identity" class="muted hidden"></p>
      <p id="chatbot-wallet-note" class="muted"></p>
      <div class="field-row"><input id="chatbot-topup-name" type="text" maxlength="255" placeholder="<?= \App\Core\View::te('profile.chatbot_name_placeholder') ?>"><input id="chatbot-topup-phone" type="tel" maxlength="30" placeholder="<?= \App\Core\View::te('profile.chatbot_phone_placeholder') ?>"></div>
      <div class="field-row"><input id="chatbot-topup-amount" type="number" min="1000" step="1000" value="10000" style="width:8rem"><button id="chatbot-topup-button" type="button" class="secondary"><?= \App\Core\View::te('profile.chatbot_topup') ?></button></div>
      <div id="chatbot-log"></div>
      <form id="chatbot-ask-form" class="field-row"><input id="chatbot-question-input" type="text" placeholder="<?= \App\Core\View::te('profile.chatbot_question_placeholder') ?>" maxlength="1000" required style="flex:1"><button type="submit"><?= \App\Core\View::te('profile.chatbot_send') ?></button></form>
    </div>
    <p id="chatbot-status" class="status" role="status" aria-live="polite"></p>
    <p id="chatbot-payment-confirm-link"></p></details>
  </section>

  <section class="ed-feed">
    <?php if ($posts === []): ?><div class="ed-empty"><?= \App\Core\View::te('profile.empty') ?></div><?php endif; ?>
    <?php foreach ($posts as $post): \App\Core\View::partial('post-card', ['post' => $post]); endforeach; ?>
  </section>
</main>
<script src="/assets/topnav-auth.js" defer></script>
<script src="/assets/lightbox.js" defer></script>
<script src="/assets/chatbot-widget.js" defer></script>
</body></html>
