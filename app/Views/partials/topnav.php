<?php
// Single source of truth for the site nav — every page (themed or not)
// renders this same link list via \App\Core\View::partial('topnav', ...),
// so a new link only ever needs to be added here once. A theme passes its
// own navClass/brandClass/linksClass to keep its distinct branding (see
// themes/editorial and themes/minimal) without duplicating the <a> list.
use App\Core\View;

$navClass = $navClass ?? 'topbar';
$brandClass = $brandClass ?? 'brand';
$linksClass = $linksClass ?? 'nav-links';
$languages = View::languageLinks();
?>
<nav class="<?= htmlspecialchars($navClass, ENT_QUOTES, 'UTF-8') ?>">
  <a class="<?= htmlspecialchars($brandClass, ENT_QUOTES, 'UTF-8') ?>" href="/">FPDP</a>
  <div class="<?= htmlspecialchars($linksClass, ENT_QUOTES, 'UTF-8') ?>">
    <a href="/"><?= View::te('nav.home') ?></a>
    <a href="/about-me"><?= View::te('nav.about_me') ?></a>
    <a href="/youtube">YouTube</a>
    <a href="/coretan"><?= View::te('nav.coretan') ?></a>
    <a href="/shop"><?= View::te('nav.shop') ?></a>
    <a href="/about"><?= View::te('nav.about_fpdp') ?></a>
    <a href="/timeline"><?= View::te('nav.timeline') ?></a>
    <a href="/cv" class="guest-nav"><?= View::te('nav.cv') ?></a>
    <a href="/dashboard" class="guest-nav"><?= View::te('nav.dashboard') ?></a>
    <a href="/dashboard/about-me" class="owner-nav hidden"><?= View::te('nav.edit_about_me') ?></a>
    <a href="/dashboard/coretan" class="owner-nav hidden"><?= View::te('nav.manage_coretan') ?></a>
    <a href="/dashboard/posts" class="owner-nav hidden"><?= View::te('nav.post_editor') ?></a>
    <a href="/dashboard/posts/list" class="owner-nav hidden"><?= View::te('nav.my_posts') ?></a>
    <a href="/dashboard/cv" class="owner-nav hidden"><?= View::te('nav.cv') ?></a>
    <a href="/dashboard/rag" class="owner-nav hidden"><?= View::te('nav.rag') ?></a>
    <a href="/dashboard/products" class="owner-nav hidden"><?= View::te('nav.products') ?></a>
    <a href="/dashboard/orders" class="owner-nav hidden"><?= View::te('nav.orders') ?></a>
    <a href="/dashboard/purchases" class="owner-nav hidden"><?= View::te('nav.purchases') ?></a>
    <a href="/dashboard/payments" class="owner-nav hidden"><?= View::te('nav.payments') ?></a>
    <a href="/dashboard/analytics" class="owner-nav hidden"><?= View::te('nav.analytics') ?></a>
    <a href="/dashboard/integrations" class="owner-nav hidden"><?= View::te('nav.integrations') ?></a>
    <a href="/dashboard/settings" class="owner-nav hidden"><?= View::te('nav.settings') ?></a>
    <a href="/dashboard/federation" class="owner-nav hidden"><?= View::te('nav.federation') ?></a>
    <a href="/dashboard/themes" class="owner-nav hidden"><?= View::te('nav.themes') ?></a>
    <button id="logout-button" class="owner-nav hidden nav-logout" type="button"><?= View::te('nav.logout') ?></button>
    <?php if (count($languages) > 1): ?>
      <span class="lang-switch" aria-label="<?= View::te('nav.language') ?>">
        <?php foreach ($languages as $language): ?>
          <a href="<?= htmlspecialchars($language['url'], ENT_QUOTES, 'UTF-8') ?>" hreflang="<?= htmlspecialchars($language['code'], ENT_QUOTES, 'UTF-8') ?>" lang="<?= htmlspecialchars($language['code'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($language['label'], ENT_QUOTES, 'UTF-8') ?>"<?= $language['current'] ? ' aria-current="true" class="current"' : '' ?>><?= htmlspecialchars(strtoupper($language['code']), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </span>
    <?php endif; ?>
  </div>
</nav>
