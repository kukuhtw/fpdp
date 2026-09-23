<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/assets/app.css"></head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/">Home</a><a href="/about-me">About Me</a><a href="/youtube">YouTube</a><a href="/coretan">Coretan</a><a href="/about">About FPDP</a><a href="/dashboard">Dashboard</a><a href="/dashboard/about-me" class="owner-nav hidden">Edit About Me</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/coretan" class="owner-nav hidden">Kelola Coretan</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a><a href="/dashboard/themes" class="owner-nav hidden">Template</a></div></nav>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Kelola Coretan</h1>
    <p id="auth-summary" class="muted">Masuk untuk mengelola coretan pengunjung.</p>
    <form id="login-form" class="stack">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
    <button id="logout-button" class="secondary hidden" type="button">Keluar</button>
  </section>
  <section id="coretan-content" class="panel hidden">
    <div id="coretan-list" class="stack"><p class="muted">Memuat coretan…</p></div>
    <button id="load-more" class="secondary hidden" type="button">Muat lebih banyak</button>
  </section>
</main>
<script src="/assets/dashboard-wall-coretan.js" defer></script>
</body></html>
