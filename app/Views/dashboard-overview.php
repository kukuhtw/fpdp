<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><span class="nav-links"><a href="/dashboard">Dashboard</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/timeline">Timeline</a></span></nav>
<main class="shell dashboard-shell">
    <section class="panel">
        <p class="eyebrow">Owner dashboard</p><h1>Overview</h1>
        <p id="auth-summary" class="muted">Sign in to view your dashboard.</p>
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

    <section id="dashboard-content" class="hidden">
        <section class="node-status">
            <div>
                <p class="eyebrow" id="node-domain"></p>
                <h2 id="node-name"></h2>
            </div>
            <span class="status-badge" id="node-status-badge"></span>
        </section>

        <section class="kpi-row" id="kpi-row" aria-label="Key metrics"></section>

        <section class="panel chart-card">
            <h2>Traffic, last 7 days</h2>
            <div class="chart-legend" id="chart-legend"></div>
            <div id="traffic-chart"></div>
        </section>

        <div class="two-col">
            <section class="panel">
                <h2>Top content</h2>
                <ol class="content-list" id="top-content"></ol>
            </section>
            <section class="panel">
                <h2>Recent activity</h2>
                <ol class="activity-list" id="recent-activity"></ol>
            </section>
        </div>
    </section>

    <p id="dashboard-status" class="status" role="status" aria-live="polite"></p>
</main>
<script src="/assets/dashboard-overview.js" defer></script>
</body>
</html>
