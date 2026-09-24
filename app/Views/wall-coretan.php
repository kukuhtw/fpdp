<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell">
  <section class="panel">
    <p class="eyebrow">Wall</p>
    <h1>Coretan untuk <?= htmlspecialchars((string) $profile['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted">Tinggalkan komentar atau sapaan di sini. Masuk dengan Google diperlukan agar komentar bisa diverifikasi. Tautan (URL) yang Anda tulis akan otomatis menjadi tautan yang bisa diklik.</p>
    <div id="composer-guest"><a class="button" href="/api/v1/profiles/<?= rawurlencode((string) $profile['handle']) ?>/visitor-auth/google/redirect?return_to=%2Fcoretan">Masuk dengan Google untuk berkomentar</a></div>
    <form id="comment-form" class="stack hidden">
      <label>Tulis coretan<textarea name="content" rows="4" maxlength="1000" required placeholder="Tulis pesan Anda..."></textarea></label>
      <div class="actions"><button type="submit">Kirim coretan</button><button id="comment-logout" class="secondary" type="button">Ganti akun</button></div>
    </form>
    <p id="comment-status" class="status" role="status" aria-live="polite"></p>
  </section>
  <section class="panel">
    <h2>Coretan pengunjung</h2>
    <div id="comment-list" class="stack"><p class="muted">Memuat coretan…</p></div>
    <button id="load-more" class="secondary hidden" type="button">Muat lebih banyak</button>
  </section>
</main>
<script src="/assets/wall-coretan.js" defer></script>
<script src="/assets/topnav-auth.js" defer></script>
</body></html>
