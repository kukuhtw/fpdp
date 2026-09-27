<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <?= \App\Core\View::themeStylesheetTag() ?>
</head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
    <section class="panel">
        <p class="eyebrow">Owner dashboard</p><h1>Settings</h1>
        <p id="auth-summary" class="muted">Sign in to manage settings.</p>
        <form id="login-form" class="stack" method="post">
            <label>Email<input name="email" type="email" autocomplete="email" required></label>
            <label class="password-field">Password
                <span class="password-wrapper">
                    <input name="password" type="password" autocomplete="current-password" required>
                    <button type="button" class="toggle-password" aria-label="Show password" data-show="Show" data-hide="Hide">Show</button>
                </span>
            </label>
            <button type="submit">Sign in</button>
        </form>
    </section>

    <section id="settings-content" class="hidden stack">
        <div class="two-col">
            <section class="panel">
                <h2>Payment Gateways</h2>
                <p class="muted">Configure payment gateway credentials. Secrets are encrypted at rest.</p>
                <div id="gateway-list"></div>
            </section>
            <section class="panel">
                <h2>Gateway Configuration</h2>
                <p class="muted" id="config-subtitle">Select a gateway to configure.</p>
                <form id="gateway-form" class="stack hidden">
                    <input name="code" type="hidden">
                    <div class="field-row">
                        <label>Environment<select name="environment"><option value="SANDBOX">Sandbox</option><option value="LIVE">Live</option></select></label>
                    </div>
                    <p id="config-existing-note" class="muted"></p>
                    <div id="gateway-fields"></div>
                    <button type="submit">Save Configuration</button>
                </form>
                <p id="settings-status" class="status" role="status" aria-live="polite"></p>
            </section>
        </div>

        <section class="panel">
            <h2>AI / LLM Provider</h2>
            <p class="muted">Configure an LLM provider for AI features (e.g. generating a product description from a photo). The API key is encrypted at rest and never shown in full again.</p>
            <p id="llm-key-hint" class="muted hidden"></p>
            <form id="llm-form" class="stack">
                <label>Provider<select name="provider_code">
                    <option value="OPENAI">OpenAI</option>
                    <option value="ANTHROPIC">Anthropic</option>
                    <option value="OPENROUTER">OpenRouter</option>
                </select></label>
                <label>Model<input name="model" placeholder="gpt-4o-mini" required></label>
                <label>API Key<input name="api_key" type="password" placeholder="Enter to set or replace the stored key" autocomplete="off"></label>
                <label class="check"><input name="supports_vision" type="checkbox"> This model can read images (vision)</label>
                <button type="submit">Save LLM Settings</button>
            </form>
            <p id="llm-status" class="status" role="status" aria-live="polite"></p>
        </section>

        <section class="panel" id="language-panel">
            <h2>Bahasa halaman publik</h2>
            <p class="muted">Bahasa yang dipakai halaman untuk pengunjung (beranda, profil, toko, timeline). Pengunjung bisa berpindah di antara bahasa yang aktif lewat tombol di navigasi; tanpa pilihan, dipakai bahasa browser mereka bila aktif, lalu bahasa default. Isi yang Anda tulis sendiri (bio, post, produk) tidak diterjemahkan.</p>
            <form id="language-form" class="stack">
                <fieldset class="stack">
                    <legend>Bahasa aktif</legend>
                    <div id="language-options" class="stack"><p class="muted">Memuat…</p></div>
                </fieldset>
                <label>Bahasa default<select name="default_locale" id="default-locale"></select></label>
                <button type="submit">Simpan bahasa</button>
            </form>
            <p id="language-status" class="status" role="status" aria-live="polite"></p>
        </section>

        <section class="panel" id="security-panel">
            <h2>Keamanan akun</h2>
            <p class="muted">Perangkat tempat Anda sedang masuk. Keluarkan yang tidak Anda kenali, lalu ganti password. IP hanya disimpan sebagian (mis. <code>203.0.113.x</code>).</p>
            <div id="session-list" class="stack"><p class="muted">Memuat sesi…</p></div>
            <p><button type="button" id="revoke-others" class="secondary">Keluarkan semua perangkat lain</button></p>
            <p id="session-status" class="status" role="status" aria-live="polite"></p>

            <h3>Ganti password</h3>
            <form id="password-form" class="stack">
                <label>Password saat ini<input name="current_password" type="password" autocomplete="current-password" required></label>
                <label>Password baru (12–128 karakter)<input name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
                <label>Ulangi password baru<input name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
                <button type="submit">Ganti password</button>
            </form>
            <p class="muted">Mengganti password juga mengeluarkan semua perangkat lain.</p>
            <p id="password-status" class="status" role="status" aria-live="polite"></p>

            <h3 id="twofa-heading">Verifikasi dua langkah (2FA)</h3>
            <div id="twofa-panel" class="stack" aria-labelledby="twofa-heading">
                <p id="twofa-summary" class="muted">Memuat status…</p>

                <div id="twofa-off" class="stack hidden">
                    <p class="muted">Selain password, login meminta kode 6 digit dari aplikasi authenticator di ponsel Anda (Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden, Aegis, dan sejenisnya). Password yang bocor saja tidak cukup untuk masuk.</p>
                    <p><button type="button" id="twofa-start">Aktifkan 2FA</button></p>
                </div>

                <div id="twofa-setup" class="stack hidden">
                    <p>1. Pindai kode QR ini dengan aplikasi authenticator.</p>
                    <div id="twofa-qr" class="twofa-qr" role="img" aria-label="Kode QR untuk aplikasi authenticator"></div>
                    <p class="muted">Tidak bisa memindai? Masukkan kunci ini secara manual (jenis: berbasis waktu):</p>
                    <p><code id="twofa-secret" class="twofa-secret"></code></p>
                    <form id="twofa-confirm-form" class="stack">
                        <label>2. Masukkan kode 6 digit yang muncul di aplikasi<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></label>
                        <div class="actions">
                            <button type="submit">Aktifkan</button>
                            <button type="button" id="twofa-setup-cancel" class="secondary">Batal</button>
                        </div>
                    </form>
                </div>

                <div id="twofa-codes" class="stack hidden">
                    <p><strong>Simpan kode pemulihan ini sekarang.</strong> Kode ini hanya ditampilkan sekali. Jika ponsel hilang, setiap kode bisa dipakai satu kali sebagai pengganti kode 6 digit.</p>
                    <ul id="twofa-code-list" class="recovery-codes"></ul>
                    <div class="actions">
                        <button type="button" id="twofa-copy" class="secondary">Salin</button>
                        <button type="button" id="twofa-download" class="secondary">Unduh .txt</button>
                        <button type="button" id="twofa-codes-done">Sudah saya simpan</button>
                    </div>
                </div>

                <div id="twofa-on" class="stack hidden">
                    <form id="twofa-regenerate-form" class="stack">
                        <p class="muted">Buat kode pemulihan baru (kode lama langsung tidak berlaku).</p>
                        <label>Kode 6 digit dari aplikasi<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></label>
                        <button type="submit" class="secondary">Buat kode pemulihan baru</button>
                    </form>
                    <form id="twofa-disable-form" class="stack">
                        <p class="muted">Menonaktifkan 2FA membuat login kembali hanya memakai password.</p>
                        <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
                        <label>Kode 6 digit atau kode pemulihan<input name="code" autocomplete="one-time-code" maxlength="20" required></label>
                        <button type="submit" class="danger">Nonaktifkan 2FA</button>
                    </form>
                </div>
                <p id="twofa-status" class="status" role="status" aria-live="polite"></p>
            </div>
        </section>

        <section class="panel">
            <h2>Install another gateway (plugin)</h2>
            <p class="muted">Beyond the built-in gateways above, you can add your own by copying a folder to <code>/gateways/&lt;slug&gt;/</code> on the server — the same no-upload-from-browser model as Template. No restart needed; reload this page and it appears in the list above, ready to configure and activate. Full guide: <code>documentation/PAYMENT-GATEWAY-PLUGIN-GUIDE.id.md</code>. A working example ships at <code>gateways/manual-transfer/</code>.</p>
        </section>
    </section>
</main>
<script src="/assets/dashboard-settings.js" defer></script>
<script src="/assets/dashboard-security.js" defer></script>
<script src="/assets/vendor/qrcode-generator-2.0.4.js" defer></script>
<script src="/assets/dashboard-2fa.js" defer></script>
<script src="/assets/dashboard-language.js" defer></script>
</body>
</html>