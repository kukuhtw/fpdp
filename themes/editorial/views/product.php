<!doctype html><html lang="<?= \App\Core\View::lang() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head><body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>" data-product-id="<?= htmlspecialchars((string) $productId, ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav', ['navClass' => 'ed-nav', 'brandClass' => 'ed-brand', 'linksClass' => 'ed-nav-links']); ?>
<main class="shell narrow">
  <p class="eyebrow"><a href="/shop">&larr; <?= \App\Core\View::te('product.back') ?></a></p>
  <section class="panel">
    <div id="product-details"><p class="muted"><?= \App\Core\View::te('product.loading') ?></p></div>
    <div id="product-actions" class="stack hidden">
      <p id="buyer-identity" class="muted hidden"></p>
      <a id="google-login" class="button" href="/api/v1/profiles/<?= rawurlencode((string) $profile['handle']) ?>/visitor-auth/google/redirect?return_to=<?= rawurlencode('/shop/' . (string) $productId) ?>"><?= \App\Core\View::te('product.google_login') ?></a>
      <label id="quantity-field" class="hidden"><?= \App\Core\View::te('product.quantity') ?><input id="quantity-input" type="number" min="1" value="1" style="width:5rem"></label>
      <label id="recipient-name-field" class="hidden"><?= \App\Core\View::te('product.recipient_name') ?><input id="recipient-name-input" type="text" maxlength="255" placeholder="<?= \App\Core\View::te('product.recipient_name_placeholder') ?>"></label>
      <label id="recipient-phone-field" class="hidden"><?= \App\Core\View::te('product.phone') ?><input id="recipient-phone-input" type="tel" maxlength="30" placeholder="08xxxxxxxxxx"></label>
      <label id="shipping-address-field" class="hidden"><?= \App\Core\View::te('product.address') ?><textarea id="shipping-address-input" rows="3" placeholder="<?= \App\Core\View::te('product.address_placeholder') ?>"></textarea></label>
      <label id="order-notes-field" class="hidden"><?= \App\Core\View::te('product.notes') ?><textarea id="order-notes-input" rows="2" placeholder="<?= \App\Core\View::te('product.notes_placeholder') ?>"></textarea></label>
      <button id="buy-button" class="hidden" type="button"><?= \App\Core\View::te('product.buy') ?></button>
      <a id="download-button" class="button hidden" target="_blank" rel="noopener"><?= \App\Core\View::te('product.download') ?></a>
      <div id="digital-assets-buttons" class="actions"></div>
    </div>
    <p id="product-status" class="status" role="status" aria-live="polite"></p>
    <p id="payment-confirm-link"></p>
  </section>
</main>
<script src="/assets/product.js" defer></script>
<script src="/assets/topnav-auth.js" defer></script>
<script src="/assets/lightbox.js" defer></script>
</body></html>
