<?php
// Single source of truth for the site nav — every page (themed or not)
// renders this same link list via \App\Core\View::partial('topnav', ...),
// so a new link only ever needs to be added here once. A theme passes its
// own navClass/brandClass/linksClass to keep its distinct branding (see
// themes/editorial and themes/minimal) without duplicating the <a> list.
$navClass = $navClass ?? 'topbar';
$brandClass = $brandClass ?? 'brand';
$linksClass = $linksClass ?? 'nav-links';
?>
<nav class="<?= htmlspecialchars($navClass, ENT_QUOTES, 'UTF-8') ?>">
  <a class="<?= htmlspecialchars($brandClass, ENT_QUOTES, 'UTF-8') ?>" href="/">FPDP</a>
  <div class="<?= htmlspecialchars($linksClass, ENT_QUOTES, 'UTF-8') ?>">
    <a href="/">Home</a>
    <a href="/about-me">About Me</a>
    <a href="/youtube">YouTube</a>
    <a href="/coretan">Coretan</a>
    <a href="/shop">Shop</a>
    <a href="/about">About FPDP</a>
    <a href="/timeline">Timeline</a>
    <a href="/cv" class="guest-nav">CV &amp; Resume</a>
    <a href="/dashboard" class="guest-nav">Dashboard</a>
    <a href="/dashboard/about-me" class="owner-nav hidden">Edit About Me</a>
    <a href="/dashboard/coretan" class="owner-nav hidden">Kelola Coretan</a>
    <a href="/dashboard/posts" class="owner-nav hidden">Post editor</a>
    <a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a>
    <a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a>
    <a href="/dashboard/rag" class="owner-nav hidden">RAG Documents</a>
    <a href="/dashboard/products" class="owner-nav hidden">Products</a>
    <a href="/dashboard/orders" class="owner-nav hidden">Orders</a>
    <a href="/dashboard/payments" class="owner-nav hidden">Payments</a>
    <a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a>
    <a href="/dashboard/settings" class="owner-nav hidden">Settings</a>
    <a href="/dashboard/federation" class="owner-nav hidden">Federasi</a>
    <a href="/dashboard/themes" class="owner-nav hidden">Template</a>
    <button id="logout-button" class="owner-nav hidden nav-logout" type="button">LogOut</button>
  </div>
</nav>
