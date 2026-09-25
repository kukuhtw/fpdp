<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell narrow">
  <section class="panel">
    <p class="eyebrow">Pembayaran</p>
    <h1 id="payment-heading">Memeriksa status pembayaran…</h1>
    <p id="payment-message" class="muted">Mohon tunggu sebentar, kami sedang mengecek status pembayaran Anda.</p>
    <div id="payment-details" class="stack"></div>
    <div id="payment-actions" class="actions hidden"></div>
    <p id="payment-status" class="status" role="status" aria-live="polite"></p>
  </section>
</main>
<script src="/assets/payment-thank-you.js" defer></script>
<script src="/assets/topnav-auth.js" defer></script>
</body></html>
