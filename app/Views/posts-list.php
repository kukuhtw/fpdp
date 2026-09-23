<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/">Home</a><a href="/about">About</a><a href="/dashboard">Dashboard</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/timeline">Timeline</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a></div></nav>
<main class="shell">
    <section class="panel">
        <p class="eyebrow">Owner dashboard</p><h1>My posts</h1>
        <p id="auth-summary" class="muted">Sign in to view your posts.</p>
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

    <section id="posts-list" class="hidden">
        <div class="tab-bar">
            <button class="tab active" data-filter="all">All</button>
            <button class="tab" data-filter="published">Published</button>
            <button class="tab" data-filter="draft">Drafts</button>
        </div>
        <p id="list-status" class="status" role="status" aria-live="polite"></p>
        <div id="posts-container"></div>
        <button id="load-more" class="button secondary hidden">Load more</button>
    </section>
</main>
<script src="/assets/posts-list.js" defer></script>
</body>
</html>