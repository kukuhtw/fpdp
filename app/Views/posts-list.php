<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
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