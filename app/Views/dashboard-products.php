<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Products</h1>
    <p id="auth-summary" class="muted">Masuk untuk mengelola produk.</p>
    <form id="login-form" class="stack">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
  </section>

  <section id="products-content" class="hidden two-col">
    <section class="panel">
      <h2 id="form-heading">Tambah produk</h2>
      <form id="product-form" class="stack">
        <input name="public_id" type="hidden">
        <input name="media_url" type="hidden">
        <label>Foto produk<div class="field-row">
          <img id="product-photo-preview" class="hidden" alt="Pratinjau foto produk" style="width:96px;height:96px;object-fit:cover;border-radius:9px;border:1px solid var(--line)">
          <input id="product-photo-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-max-bytes="<?= (int) ($mediaMaxFileSizeBytes ?? 10485760) ?>">
          <button id="product-photo-upload-button" class="secondary" type="button" disabled>Upload foto</button>
        </div>
        <progress id="product-photo-progress" class="hidden" value="0" max="100"></progress>
        <p id="product-photo-status" class="status" role="status" aria-live="polite"></p></label>
        <label>Nama produk<input name="title" maxlength="255" required></label>
        <label>Deskripsi<textarea name="description" rows="3"></textarea>
          <button id="describe-with-ai-button" class="secondary hidden" type="button">Buat deskripsi dengan AI</button>
        </label>
        <div class="field-row">
          <label>Harga<input name="price" type="number" min="0" step="0.01" value="0" required></label>
          <label>Mata uang<select name="currency"><option value="IDR">IDR</option><option value="USD">USD</option></select></label>
        </div>
        <label>Jenis produk<select name="product_type">
          <option value="PHYSICAL">Barang fisik</option>
          <option value="DIGITAL">Barang digital</option>
          <option value="SERVICE">Jasa</option>
        </select></label>
        <label id="digital-url-field" class="hidden">URL file digital (opsional, tautan eksternal)<input name="digital_asset_url" type="url" placeholder="https://cdn.example.com/file.zip"></label>
        <div id="digital-assets-field" class="hidden">
          <p class="muted" id="digital-assets-hint">Simpan produk terlebih dahulu untuk bisa upload file PDF / source code.</p>
          <div id="digital-assets-uploaders" class="hidden stack">
            <label>Upload PDF<div class="field-row">
              <input id="digital-pdf-input" type="file" accept="application/pdf" data-max-bytes="<?= (int) ($productAssetMaxFileSizeBytes ?? 20971520) ?>">
              <button id="digital-pdf-upload-button" class="secondary" type="button" disabled>Upload PDF</button>
            </div></label>
            <label>Upload source code (.zip)<div class="field-row">
              <input id="digital-source-input" type="file" accept="application/zip" data-max-bytes="<?= (int) ($productAssetMaxFileSizeBytes ?? 20971520) ?>">
              <button id="digital-source-upload-button" class="secondary" type="button" disabled>Upload source code</button>
            </div></label>
            <progress id="digital-asset-progress" class="hidden" value="0" max="100"></progress>
            <div id="digital-assets-list" class="muted"></div>
            <p id="digital-asset-status" class="status" role="status" aria-live="polite"></p>
          </div>
        </div>
        <div class="field-row">
          <label>Status<select name="status"><option value="ACTIVE">Aktif (bisa dibeli)</option><option value="INACTIVE">Nonaktif</option><option value="ARCHIVED">Diarsipkan</option></select></label>
          <label>Visibilitas<select name="visibility"><option value="PUBLIC">Publik</option><option value="UNLISTED">Unlisted</option><option value="PRIVATE">Privat</option></select></label>
        </div>
        <div class="actions"><button type="submit">Simpan produk</button><button id="cancel-edit" class="secondary hidden" type="button">Batal edit</button></div>
      </form>
      <p id="product-status" class="status" role="status" aria-live="polite"></p>
    </section>
    <section class="panel">
      <h2>Produk Anda</h2>
      <div id="product-list" class="stack"><p class="muted">Memuat produk…</p></div>
    </section>
  </section>
</main>
<script src="/assets/dashboard-products.js" defer></script>
</body></html>
