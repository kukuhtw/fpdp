<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><link rel="stylesheet" href="/assets/app.css"></head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/">Home</a><a href="/about-me">About Me</a><a href="/youtube">YouTube</a><a href="/coretan">Coretan</a><a href="/about">About FPDP</a><a href="/dashboard">Dashboard</a><a href="/dashboard/about-me" class="owner-nav hidden">Edit About Me</a><a href="/dashboard/coretan" class="owner-nav hidden">Kelola Coretan</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a><a href="/dashboard/themes" class="owner-nav hidden">Template</a><a href="/timeline">Timeline</a></div></nav>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Template</h1>
    <p id="auth-summary" class="muted">Masuk untuk mengganti template situs Anda.</p>
    <form id="login-form" class="stack">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
    <button id="logout-button" class="secondary hidden" type="button">Keluar</button>
  </section>

  <section id="themes-content" class="hidden stack">
    <section class="panel">
      <h2>Pilih template</h2>
      <p class="muted">Template mengubah tampilan halaman publik Anda (profil/beranda, dan lainnya bila template menyediakannya). Klik &ldquo;Aktifkan&rdquo; untuk berpindah — perubahan langsung berlaku, tidak perlu deploy ulang.</p>
      <div id="theme-grid" class="provider-grid"><p class="muted">Memuat daftar template…</p></div>
      <p id="theme-status" class="status" role="status" aria-live="polite"></p>
    </section>

    <section class="panel">
      <h2>Cara membuat template sendiri</h2>
      <p class="muted">Ringkasan singkat &mdash; panduan lengkap ada di <code>documentation/THEME-GUIDE.id.md</code> di repository.</p>
      <ol>
        <li>Buat folder baru di <code>/themes/&lt;slug-anda&gt;/</code> (slug: huruf kecil, angka, <code>-</code>/<code>_</code>).</li>
        <li>Tambahkan <code>theme.json</code> berisi <code>name</code>, <code>description</code>, <code>author</code>, <code>version</code>, <code>preview_color</code>.</li>
        <li>Tambahkan file view PHP di <code>views/</code> untuk halaman yang ingin Anda override &mdash; nama file harus persis salah satu dari: <code>profile.php</code>, <code>about-me.php</code>, <code>youtube.php</code>, <code>wall-coretan.php</code>, <code>post.php</code>, <code>public-cv.php</code>. Halaman yang tidak Anda sediakan otomatis memakai tampilan bawaan.</li>
        <li>Tambahkan CSS Anda di <code>assets/theme.css</code> (dan aset lain seperti gambar/font bila perlu) &mdash; diakses publik lewat <code>/themes/&lt;slug&gt;/assets/&lt;file&gt;</code>.</li>
        <li>Muat ulang halaman ini &mdash; template baru otomatis terdeteksi dan muncul di daftar di atas.</li>
      </ol>
    </section>

    <section class="panel">
      <h2>Cara install template dari orang lain</h2>
      <p class="muted">Salin folder template yang Anda terima (mis. lewat FTP, file manager hosting, atau SSH/SCP) ke <code>/themes/</code> di server Anda, sehingga strukturnya menjadi <code>/themes/&lt;slug-theme&gt;/theme.json</code>. Tidak ada upload lewat browser &mdash; ini sengaja, supaya tidak ada kode dari luar yang bisa masuk lewat dashboard. Setelah folder tersalin, muat ulang halaman ini untuk melihatnya di daftar.</p>
    </section>
  </section>
</main>
<script src="/assets/dashboard-themes.js" defer></script>
</body></html>
