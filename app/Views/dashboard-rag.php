<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>RAG Documents</h1>
    <p class="muted">Upload dokumen tentang diri Anda (teks/markdown), lalu generate FAQ darinya. FAQ akan dipakai sebagai grounding untuk fitur chatbot pengunjung.</p>
    <p id="auth-summary" class="muted">Masuk untuk mengelola dokumen RAG.</p>
    <form id="login-form" class="stack" method="post">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
  </section>

  <section id="rag-content" class="hidden two-col">
    <section class="panel">
      <h2>Upload dokumen</h2>
      <form id="document-form" class="stack">
        <label>Judul dokumen<input name="title" maxlength="255" required></label>
        <label>File (.txt atau .md)<input id="document-file-input" type="file" accept=".txt,.md,text/plain,text/markdown" required></label>
        <button type="submit">Upload dokumen</button>
      </form>
      <p id="document-status" class="status" role="status" aria-live="polite"></p>
      <h2>Dokumen Anda</h2>
      <div id="document-list" class="stack"><p class="muted">Memuat dokumen…</p></div>
    </section>
    <section class="panel">
      <h2 id="faq-heading">FAQ</h2>
      <div id="faq-empty" class="muted">Pilih dokumen di sebelah kiri untuk mengelola FAQ-nya.</div>
      <div id="faq-content" class="hidden stack">
        <div class="field-row">
          <label>Jumlah FAQ<input id="faq-count-input" type="number" min="1" max="20" value="5" style="width:6rem"></label>
          <button id="generate-faqs-button" type="button">Generate FAQ dengan AI</button>
        </div>
        <p id="faq-status" class="status" role="status" aria-live="polite"></p>
        <div id="faq-list" class="stack"></div>
      </div>
    </section>
  </section>

  <section id="chatbot-settings-content" class="hidden">
    <section class="panel">
      <h2>Pengaturan Chatbot Pengunjung</h2>
      <p class="muted">Aktifkan agar tombol chat muncul di halaman profil publik Anda. Chatbot menjawab memakai FAQ di atas sebagai konteks, dan membutuhkan LLM provider yang sudah dikonfigurasi di Settings.</p>
      <form id="chatbot-settings-form" class="stack">
        <label class="check"><input id="chatbot-enabled-input" type="checkbox"> Aktifkan chatbot untuk pengunjung</label>
        <div class="field-row">
          <label>Harga per pertanyaan<input id="chatbot-price-input" type="number" min="0" step="0.01" value="0"></label>
          <label>Mata uang<select id="chatbot-currency-input"><option value="IDR">IDR</option><option value="USD">USD</option></select></label>
        </div>
        <p class="muted">Isi 0 untuk gratis. Pengunjung tetap harus masuk dengan Google sebelum bertanya.</p>
        <button type="submit">Simpan pengaturan</button>
      </form>
      <p id="chatbot-settings-status" class="status" role="status" aria-live="polite"></p>
    </section>
  </section>
</main>
<template id="faq-card-template">
  <article class="gateway-card faq-card">
    <label>Pertanyaan<textarea class="faq-question" rows="2"></textarea></label>
    <label>Jawaban<textarea class="faq-answer" rows="3"></textarea></label>
    <p class="muted faq-embedding-status"></p>
    <div class="actions">
      <button type="button" class="faq-save">Simpan</button>
      <button type="button" class="secondary faq-delete">Hapus</button>
    </div>
  </article>
</template>
<script src="/assets/dashboard-rag.js" defer></script>
</body></html>
