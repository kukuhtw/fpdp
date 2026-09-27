<!doctype html><html lang="<?= \App\Core\View::lang() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head><body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav', ['navClass' => 'mn-nav', 'brandClass' => 'mn-brand', 'linksClass' => 'mn-nav-links']); ?>
<main class="shell">
  <section class="panel">
    <p class="eyebrow"><?= \App\Core\View::te('shop.eyebrow') ?></p>
    <h1><?= \App\Core\View::te('shop.heading', ['name' => (string) $profile['display_name']]) ?></h1>
    <p class="muted"><?= \App\Core\View::te('shop.intro') ?></p>
  </section>
  <section class="panel">
    <div id="product-grid" class="product-grid"><p class="muted"><?= \App\Core\View::te('shop.loading') ?></p></div>
    <button id="load-more" class="secondary hidden" type="button"><?= \App\Core\View::te('shop.load_more') ?></button>
  </section>
</main>
<script src="/assets/shop.js" defer></script>
<script src="/assets/topnav-auth.js" defer></script>
</body></html>
