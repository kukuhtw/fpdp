<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Federasi</h1>
    <p id="auth-summary" class="muted">Masuk untuk mengelola follow, approve, dan reject.</p>
    <form id="login-form" class="stack" method="post">
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

    <section class="panel" id="discover-panel">
      <h2>Temukan &amp; ikuti akun fediverse</h2>
      <p class="muted">Fediverse tidak punya mesin pencari pusat. Cari akun lewat alamatnya, lihat saran dari koneksi Anda sendiri, atau jelajahi direktori dan hashtag sebuah server. Permintaan follow berstatus &ldquo;Pending&rdquo; sampai pemilik akun menyetujuinya.</p>
      <div class="tab-bar" role="tablist" aria-label="Cara menemukan akun">
        <button type="button" class="tab active" role="tab" data-tab="search" aria-selected="true">Cari akun</button>
        <button type="button" class="tab" role="tab" data-tab="suggestions" aria-selected="false">Saran</button>
        <button type="button" class="tab" role="tab" data-tab="directory" aria-selected="false">Direktori server</button>
        <button type="button" class="tab" role="tab" data-tab="hashtag" aria-selected="false">Hashtag</button>
      </div>

      <div data-panel="search" class="stack">
        <form id="discover-search-form" class="stack">
          <label>Alamat atau URL profil<input name="q" type="text" placeholder="@handle@mastodon.social atau https://contoh.domain/@handle" required></label>
          <button type="submit">Cari &amp; pratinjau</button>
        </form>
        <p class="muted">Alamat node FPDP lain juga bisa, misalnya <code>@owner@domain-mereka.com</code>.</p>
      </div>

      <div data-panel="suggestions" class="stack hidden">
        <p class="muted">Dari data node Anda sendiri, tanpa menghubungi server lain: follower yang belum Anda follow balik, dan akun yang pernah mengirim post ke node Anda tetapi sekarang tidak Anda ikuti.</p>
        <button type="button" id="discover-suggestions-refresh" class="secondary">Muat ulang saran</button>
      </div>

      <div data-panel="directory" class="stack hidden">
        <form id="discover-directory-form" class="stack">
          <label>Domain server<input name="domain" type="text" value="mastodon.social" placeholder="mastodon.social" required></label>
          <button type="submit">Tampilkan direktori</button>
        </form>
        <p class="muted">Menampilkan profil yang memilih tampil di direktori publik server tersebut. Hanya server berbasis Mastodon yang membuka direktorinya.</p>
      </div>

      <div data-panel="hashtag" class="stack hidden">
        <form id="discover-hashtag-form" class="stack">
          <div class="field-row">
            <label>Hashtag<input name="tag" type="text" placeholder="#indonesia" required></label>
            <label>Server<input name="domain" type="text" value="mastodon.social" placeholder="mastodon.social" required></label>
          </div>
          <button type="submit">Cari penulis</button>
        </form>
        <p class="muted">Menampilkan penulis post publik terbaru bertagar tersebut, sebagaimana terlihat dari server yang dipilih. Hanya server berbasis Mastodon yang membuka timeline hashtag publik.</p>
      </div>

      <p id="discover-status" class="status" role="status" aria-live="polite"></p>
      <div id="discover-results" class="stack"></div>
      <button type="button" id="discover-more" class="secondary hidden">Muat lebih banyak</button>
    </section>

    <section class="panel">
      <div class="section-heading"><h2>Koneksi</h2></div>
      <div id="connections-list" class="stack"><p class="muted">Memuat koneksi…</p></div>
    </section>
  </section>
</main>
<script src="/assets/dashboard-federation.js" defer></script>
<script src="/assets/dashboard-federation-discovery.js" defer></script>
</body></html>
