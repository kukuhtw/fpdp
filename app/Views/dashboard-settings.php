<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/dashboard">Dashboard</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/timeline">Timeline</a></div></nav>
<main class="shell dashboard-shell">
    <section class="panel">
        <p class="eyebrow">Owner dashboard</p><h1>Settings</h1>
        <p id="auth-summary" class="muted">Sign in to manage settings.</p>
        <form id="login-form" class="stack">
            <label>Email<input name="email" type="email" autocomplete="email" required></label>
            <label class="password-field">Password
                <span class="password-wrapper">
                    <input name="password" type="password" autocomplete="current-password" required>
                    <button type="button" class="toggle-password" aria-label="Show password" data-show="Show" data-hide="Hide">Show</button>
                </span>
            </label>
            <button type="submit">Sign in</button>
        </form>
        <button id="logout-button" class="secondary hidden" type="button">Sign out</button>
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
                    <div id="gateway-fields"></div>
                    <div class="field-row">
                        <label>Environment<select name="environment"><option value="SANDBOX">Sandbox</option><option value="LIVE">Live</option></select></label>
                    </div>
                    <button type="submit">Save Configuration</button>
                </form>
                <p id="settings-status" class="status" role="status" aria-live="polite"></p>
            </section>
        </div>
    </section>
</main>
<script src="/assets/dashboard-settings.js" defer></script>
</body>
</html>