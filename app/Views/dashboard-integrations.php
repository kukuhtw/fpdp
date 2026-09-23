<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/">Home</a><a href="/about">About</a><a href="/dashboard">Dashboard</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/timeline">Timeline</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a></div></nav>
<main class="shell dashboard-shell">
    <section class="panel">
        <p class="eyebrow">Owner dashboard</p><h1>Integrasi konten</h1>
        <p id="auth-summary" class="muted">Masuk untuk mengelola sumber konten.</p>
        <form id="login-form" class="stack">
            <label>Email<input name="email" type="email" autocomplete="email" required></label>
            <label class="password-field">Password<span class="password-wrapper"><input name="password" type="password" autocomplete="current-password" required><button type="button" class="toggle-password" data-show="Tampilkan" data-hide="Sembunyikan">Tampilkan</button></span></label>
            <button type="submit">Masuk</button>
        </form>
        <button id="logout-button" class="secondary hidden" type="button">Keluar</button>
    </section>
    <section id="integration-content" class="hidden stack">
        <section class="provider-grid" aria-label="Platform konten">
            <article class="provider-card available"><span class="provider-logo youtube">YT</span><div><h2>YouTube</h2><p>Ambil upload publik melalui feed resmi channel, tanpa OAuth.</p></div><span class="status-badge">Tersedia</span></article>
            <article class="provider-card available"><span class="provider-logo linkedin">in</span><div><h2>LinkedIn Organizations</h2><p>Sinkronkan post Company Page yang Anda administrasikan.</p></div><button id="linkedin-connect" type="button">Hubungkan LinkedIn</button></article>
        </section>
        <section class="panel"><div class="section-heading"><h2>LinkedIn Organizations</h2><span class="muted">Community Management API</span></div><div id="linkedin-accounts" class="source-list"><p class="muted">Belum ada LinkedIn Organization terhubung.</p></div></section>
        <div class="two-col integration-columns">
            <section class="panel">
                <h2>Hubungkan sumber</h2>
                <form id="source-form" class="stack">
                    <label>Jenis sumber<select name="provider" id="provider"><option value="YOUTUBE">YouTube channel</option><option value="RSS">RSS</option><option value="ATOM">Atom</option><option value="CUSTOM_API">Custom JSON API</option></select></label>
                    <label id="source-url-label"><span id="source-label-text">Channel ID atau URL channel YouTube</span><input name="source_url" type="text" required placeholder="UC... atau https://youtube.com/channel/UC..."><small class="muted" id="source-help">URL /@handle belum didukung; masukkan channel ID.</small></label>
                    <label>Interval sinkronisasi<select name="sync_interval"><option value="3600">Setiap jam</option><option value="21600">Setiap 6 jam</option><option value="86400">Setiap hari</option></select></label>
                    <button type="submit">Hubungkan sumber</button>
                </form>
            </section>
            <section class="panel">
                <div class="section-heading"><h2>Sumber terhubung</h2><button id="sync-button" class="secondary" type="button">Sinkronkan sekarang</button></div>
                <div id="source-list" class="source-list"></div>
            </section>
        </div>
    </section>
    <p id="integration-status" class="status" role="status" aria-live="polite"></p>
</main>
<script src="/assets/dashboard-integrations.js" defer></script>
</body>
</html>
