<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Kelola Coretan</h1>
    <p id="auth-summary" class="muted">Masuk untuk mengelola coretan pengunjung.</p>
    <form id="login-form" class="stack" method="post">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
  </section>
  <section id="coretan-content" class="panel hidden">
    <div id="coretan-list" class="stack"><p class="muted">Memuat coretan…</p></div>
    <button id="load-more" class="secondary hidden" type="button">Muat lebih banyak</button>
  </section>
</main>
<script src="/assets/dashboard-wall-coretan.js" defer></script>
</body></html>
