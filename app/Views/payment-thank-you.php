<!doctype html>
<html lang="<?= \App\Core\View::lang() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell narrow">
  <section class="panel">
    <p class="eyebrow"><?= \App\Core\View::te('thankyou.eyebrow') ?></p>
    <h1 id="payment-heading"><?= \App\Core\View::te('thankyou.checking') ?></h1>
    <p id="payment-message" class="muted"><?= \App\Core\View::te('thankyou.checking_message') ?></p>
    <div id="payment-details" class="stack"></div>
    <div id="payment-actions" class="actions hidden"></div>
    <p id="payment-status" class="status" role="status" aria-live="polite"></p>
  </section>
</main>
<script src="/assets/payment-thank-you.js" defer></script>
<script src="/assets/topnav-auth.js" defer></script>
</body></html>
