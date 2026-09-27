<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Belanja</h1>
    <p class="muted">Beli produk dari node FPDP lain yang Anda ikuti, langsung dari sini. Pesanan dikirim ke node penjual atas nama akun Anda (ditandatangani dengan kunci federasi node ini). Pembayaran tetap dilakukan di halaman bayar milik penjual.</p>
    <p id="auth-summary" class="muted">Masuk untuk berbelanja.</p>
    <form id="login-form" class="stack" method="post">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
  </section>

  <section id="purchases-content" class="hidden stack">
    <section class="panel" aria-labelledby="purchases-heading">
      <div class="section-heading"><h2 id="purchases-heading">Pesanan saya</h2><button type="button" id="refresh-all" class="secondary">Perbarui status</button></div>
      <p id="purchase-status" class="status" role="status" aria-live="polite"></p>
      <div id="purchase-list" class="stack"><p class="muted">Memuat…</p></div>
    </section>

    <section class="panel" aria-labelledby="shop-heading">
      <h2 id="shop-heading">Produk dari akun yang Anda ikuti</h2>
      <p class="muted">Produk yang hanya bisa dibeli lewat halaman toko penjual ditandai "Beli di …".</p>
      <div id="shop-list" class="product-grid"><p class="muted">Memuat…</p></div>
    </section>
  </section>

  <dialog id="order-dialog" class="mfa-dialog" aria-labelledby="order-dialog-title">
    <form id="order-form" class="mfa-dialog-form">
      <h2 id="order-dialog-title">Pesan produk</h2>
      <p id="order-product" class="mfa-dialog-help"></p>
      <label>Jumlah<input name="quantity" type="number" min="1" max="100" value="1" required></label>
      <label>Nama pemesan<input name="buyer_name" autocomplete="name" maxlength="128" required></label>
      <label>Email<input name="buyer_email" type="email" autocomplete="email" maxlength="254" required></label>
      <label id="address-field">Alamat pengiriman (dengan nomor telepon)<textarea name="shipping_address" rows="3" maxlength="1000" autocomplete="street-address"></textarea></label>
      <label>Catatan untuk penjual (opsional)<textarea name="notes" rows="2" maxlength="1000"></textarea></label>
      <p class="muted" id="order-privacy"></p>
      <p class="mfa-dialog-error" role="alert" hidden></p>
      <div class="mfa-dialog-actions">
        <button type="button" class="secondary" id="order-cancel">Batal</button>
        <button type="submit">Kirim pesanan</button>
      </div>
    </form>
  </dialog>
</main>
<script src="/assets/dashboard-purchases.js" defer></script>
</body></html>
