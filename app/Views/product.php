<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head><body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>" data-product-id="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell narrow">
  <p class="eyebrow"><a href="/shop">&larr; Shop</a></p>
  <section class="panel">
    <div id="product-details"><p class="muted">Memuat produk…</p></div>
    <div id="product-actions" class="actions hidden">
      <a id="google-login" class="button" href="/api/v1/profiles/<?= rawurlencode((string) $profile['handle']) ?>/visitor-auth/google/redirect?return_to=<?= rawurlencode('/shop/' . (string) $productId) ?>">Masuk dengan Google untuk membeli</a>
      <label id="quantity-field" class="hidden">Jumlah<input id="quantity-input" type="number" min="1" value="1" style="width:5rem"></label>
      <button id="buy-button" class="hidden" type="button">Beli</button>
      <a id="download-button" class="button hidden" target="_blank" rel="noopener">Download</a>
    </div>
    <p id="product-status" class="status" role="status" aria-live="polite"></p>
  </section>
</main>
<script src="/assets/product.js" defer></script>
<script src="/assets/topnav-auth.js" defer></script>
</body></html>
