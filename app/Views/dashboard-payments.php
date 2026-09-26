<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Payments</h1>
    <p id="auth-summary" class="muted">Masuk untuk melihat pembayaran.</p>
    <form id="login-form" class="stack" method="post">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
  </section>

  <section id="payments-content" class="hidden stack">
    <section class="kpi-row" id="kpi-row" aria-label="Ringkasan pembayaran"></section>

    <section class="panel">
      <h2>Menunggu konfirmasi manual</h2>
      <p class="muted">Pembayaran yang belum ada konfirmasi otomatis dari gateway (misalnya transfer bank manual) — cek rekening Anda, lalu konfirmasi di sini begitu dananya masuk.</p>
      <div id="pending-list" class="stack"><p class="muted">Memuat…</p></div>
    </section>

    <section class="panel">
      <h2>Transaksi terbaru</h2>
      <p class="muted">Refund mengembalikan dana lewat gateway. Untuk gateway tanpa API refund (misalnya Paywuz, iPaymu, transfer manual), kembalikan dananya sendiri lalu catat sebagai refund manual. Refund penuh juga mencabut akses CV, menandai pesanan REFUNDED, atau menarik kembali saldo top-up.</p>
      <p><button type="button" id="reconcile-button">Cek status ke gateway</button> <span id="reconcile-result" class="muted"></span></p>
      <div id="recent-list" class="stack"><p class="muted">Memuat…</p></div>
    </section>
  </section>
</main>
<script src="/assets/dashboard-payments.js" defer></script>
</body></html>
