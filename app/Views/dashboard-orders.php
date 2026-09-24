<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Orders</h1>
    <p id="auth-summary" class="muted">Masuk untuk melihat pesanan.</p>
    <form id="login-form" class="stack">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
  </section>

  <section id="orders-content" class="hidden stack">
    <div id="order-list" class="stack"><p class="muted">Memuat pesanan…</p></div>
    <button id="load-more" class="secondary hidden" type="button">Muat lebih banyak</button>
  </section>
</main>
<script src="/assets/dashboard-orders.js" defer></script>
</body></html>
