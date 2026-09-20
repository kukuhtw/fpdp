<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="https://unpkg.com/trix@2.1.12/dist/trix.css">
    <style>
      trix-editor { min-height:300px; border:1px solid var(--line); border-radius:9px; overflow:hidden; }
      trix-editor:focus-visible { outline:2px solid var(--accent); outline-offset:-1px; }
      trix-toolbar { background:var(--card); border:1px solid var(--line); border-radius:9px 9px 0 0; margin-bottom:0; }
      trix-toolbar .trix-button-group { border:0; margin:2px; }
      trix-toolbar .trix-button { border:0; border-radius:6px; }
      trix-toolbar .trix-button.trix-active { background:var(--accent); color:white; }
      .post-content h1,.post-content h2,.post-content h3{font-family:Georgia,serif;}
      .post-content img{max-width:100%;height:auto;border-radius:12px;}
      .post-content iframe{max-width:100%;aspect-ratio:16/9;border-radius:12px;border:1px solid var(--line);}
      .post-content blockquote{border-left:4px solid var(--accent);margin:1em 0;padding:.5em 1em;background:#f4f2ea;border-radius:0 9px 9px 0;}
      .post-content pre{background:#1e2a25;color:#e6ede8;padding:16px;border-radius:12px;overflow-x:auto;}
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/dashboard">Dashboard</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/timeline">Timeline</a></div></nav>
<main class="shell editor-grid">
    <section class="panel">
        <p class="eyebrow">Owner dashboard</p><h1>Post editor</h1>
        <p id="auth-summary" class="muted">Sign in to create and manage local posts.</p>
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
    <section class="panel">
        <form id="post-form" class="stack">
            <input name="post_id" type="hidden">
            <label>Title <span class="muted">optional</span><input name="title" maxlength="255"></label>
            <label>Content<input type="hidden" name="content" id="post-content"><trix-editor input="post-content"></trix-editor></label>
            <fieldset class="media-fields"><legend>Media attachment <span class="muted">optional</span></legend>
                <div class="field-row"><label>Type<select name="media_type"><option>IMAGE</option><option>VIDEO</option><option>AUDIO</option><option>FILE</option></select></label><label>HTTPS URL<input name="media_url" type="url" inputmode="url" placeholder="https://cdn.example.com/media.jpg"></label></div>
                <div class="field-row upload-row">
                    <label class="upload-picker">Or upload a file<input id="media-file-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg,audio/wav,application/pdf"></label>
                    <button id="media-upload-button" class="secondary" type="button" disabled>Upload</button>
                </div>
                <p id="media-upload-status" class="status" role="status" aria-live="polite"></p>
                <label>Alternative text<input name="media_alt_text" maxlength="500" placeholder="Describe the media for accessibility"></label>
            </fieldset>
            <div class="field-row">
                <label>Type<select name="post_type"><option>NOTE</option><option>ARTICLE</option><option>MEDIA</option></select></label>
                <label>Visibility<select name="visibility"><option>PUBLIC</option><option>UNLISTED</option><option>PRIVATE</option></select></label>
            </div>
            <label class="check"><input name="publish" type="checkbox" checked> Publish immediately</label>
            <div class="actions"><button type="submit">Save post</button><button id="reset-button" class="secondary" type="button">New draft</button></div>
        </form>
        <p id="editor-status" class="status" role="status" aria-live="polite"></p>
        <div id="saved-post" class="saved-post hidden"></div>
    </section>
</main>
<script src="https://unpkg.com/trix@2.1.12/dist/trix.umd.min.js"></script>
<script src="/assets/post-editor.js" defer></script>
</body>
</html>
