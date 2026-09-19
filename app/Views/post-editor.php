<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><a href="/timeline">View timeline</a></nav>
<main class="shell editor-grid">
    <section class="panel">
        <p class="eyebrow">Owner dashboard</p><h1>Post editor</h1>
        <p id="auth-summary" class="muted">Sign in to create and manage local posts.</p>
        <form id="login-form" class="stack">
            <label>Email<input name="email" type="email" autocomplete="email" required></label>
            <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
            <button type="submit">Sign in</button>
        </form>
        <button id="logout-button" class="secondary hidden" type="button">Sign out</button>
    </section>
    <section class="panel">
        <form id="post-form" class="stack">
            <input name="post_id" type="hidden">
            <label>Title <span class="muted">optional</span><input name="title" maxlength="255"></label>
            <label>Content<textarea name="content" rows="12" maxlength="100000" required></textarea></label>
            <div class="field-row">
                <label>Type<select name="post_type"><option>NOTE</option><option>ARTICLE</option><option>MEDIA</option></select></label>
                <label>Visibility<select name="visibility"><option>PUBLIC</option><option>UNLISTED</option><option>PRIVATE</option></select></label>
            </div>
            <label class="check"><input name="publish" type="checkbox"> Publish immediately</label>
            <div class="actions"><button type="submit">Save post</button><button id="reset-button" class="secondary" type="button">New draft</button></div>
        </form>
        <p id="editor-status" class="status" role="status" aria-live="polite"></p>
        <div id="saved-post" class="saved-post hidden"></div>
    </section>
</main>
<script src="/assets/post-editor.js" defer></script>
</body>
</html>
