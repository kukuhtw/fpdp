<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Federasi</h1>
    <p id="auth-summary" class="muted">Masuk untuk mengelola follow, approve, dan reject.</p>
    <form id="login-form" class="stack">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
  </section>

  <section id="federation-content" class="hidden stack">
    <section class="panel">
      <h2>Ringkasan</h2>
      <div class="kpi-row">
        <div class="kpi-tile"><p class="kpi-label">Followers</p><p id="fed-follower-count" class="kpi-value">–</p></div>
        <div class="kpi-tile"><p class="kpi-label">Following</p><p id="fed-following-count" class="kpi-value">–</p></div>
        <div class="kpi-tile"><p class="kpi-label">Menunggu persetujuan</p><p id="fed-pending-count" class="kpi-value">–</p></div>
      </div>
      <p class="muted">Kapabilitas node: <span id="fed-capabilities">–</span></p>
    </section>

    <section class="panel">
      <h2>Cara orang lain mem-follow Anda</h2>
      <p class="muted">Bagikan alamat federasi di bawah ini. Pemilik node FPDP lain memasukkannya lewat dashboard Federasi mereka sendiri (Send Follow) untuk mengirim permintaan follow ke Anda. Permintaan yang masuk akan muncul di bagian &ldquo;Permintaan follow menunggu persetujuan&rdquo; sampai Anda approve atau reject.</p>
      <label>Alamat federasi<div class="field-row"><input id="fed-address" type="text" readonly><button type="button" class="secondary" data-copy="fed-address">Salin</button></div></label>
      <label>Actor URI<div class="field-row"><input id="fed-actor-uri" type="text" readonly><button type="button" class="secondary" data-copy="fed-actor-uri">Salin</button></div></label>
    </section>

    <section class="panel">
      <div class="section-heading"><h2>Permintaan follow menunggu persetujuan</h2></div>
      <div id="follow-requests-list" class="stack"><p class="muted">Memuat permintaan follow…</p></div>
    </section>

    <section class="panel">
      <h2>Ikuti profil lain</h2>
      <p class="muted">Minta alamat federasi (mis. <code>@handle@domain</code>) atau actor URI dari pemilik profil yang ingin Anda ikuti. Status permintaan akan &ldquo;Pending&rdquo; sampai mereka approve di node mereka.</p>
      <form id="send-follow-form" class="stack">
        <label>Actor URI target<input name="target_actor_uri" type="url" placeholder="https://contoh.domain/@handle" required></label>
        <label>Domain target<input name="target_domain" type="text" placeholder="contoh.domain" required></label>
        <label>Alamat federasi (opsional)<input name="target_federated_address" type="text" placeholder="@handle@contoh.domain"></label>
        <button type="submit">Kirim permintaan follow</button>
      </form>
      <p id="send-follow-status" class="status" role="status" aria-live="polite"></p>
    </section>

    <section class="panel">
      <div class="section-heading"><h2>Koneksi</h2></div>
      <div id="connections-list" class="stack"><p class="muted">Memuat koneksi…</p></div>
    </section>
  </section>
</main>
<script src="/assets/dashboard-federation.js" defer></script>
</body></html>
