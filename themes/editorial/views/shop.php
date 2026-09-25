<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head><body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav', ['navClass' => 'ed-nav', 'brandClass' => 'ed-brand', 'linksClass' => 'ed-nav-links']); ?>
<main class="shell">
  <section class="panel">
    <p class="eyebrow">Shop</p>
    <h1>Produk dari <?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted">Barang fisik atau digital — bayar lewat gateway pembayaran yang aktif di toko ini.</p>
  </section>
  <section class="panel">
    <div id="product-grid" class="product-grid"><p class="muted">Memuat produk…</p></div>
    <button id="load-more" class="secondary hidden" type="button">Muat lebih banyak</button>
  </section>
</main>
<script src="/assets/shop.js" defer></script>
<script src="/assets/topnav-auth.js" defer></script>
</body></html>
