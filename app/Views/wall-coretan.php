<!doctype html>
<html lang="<?= \App\Core\View::lang() ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?></head>
<body data-profile-handle="<?= htmlspecialchars((string) $profile['handle'], ENT_QUOTES, 'UTF-8') ?>">
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell">
  <section class="panel">
    <p class="eyebrow"><?= \App\Core\View::te('coretan.eyebrow') ?></p>
    <h1><?= \App\Core\View::te('coretan.heading', ['name' => (string) $profile['display_name']]) ?></h1>
    <p class="muted"><?= \App\Core\View::te('coretan.intro') ?></p>
    <div id="composer-guest"><a class="button" href="/api/v1/profiles/<?= rawurlencode((string) $profile['handle']) ?>/visitor-auth/google/redirect?return_to=%2Fcoretan"><?= \App\Core\View::te('coretan.google_login') ?></a></div>
    <form id="comment-form" class="stack hidden">
      <label><?= \App\Core\View::te('coretan.write_label') ?><textarea name="content" rows="4" maxlength="1000" required placeholder="<?= \App\Core\View::te('coretan.placeholder') ?>"></textarea></label>
      <div class="actions"><button type="submit"><?= \App\Core\View::te('coretan.submit') ?></button><button id="comment-logout" class="secondary" type="button"><?= \App\Core\View::te('coretan.switch_account') ?></button></div>
    </form>
    <p id="comment-status" class="status" role="status" aria-live="polite"></p>
  </section>
  <section class="panel">
    <h2><?= \App\Core\View::te('coretan.list_heading') ?></h2>
    <div id="comment-list" class="stack"><p class="muted"><?= \App\Core\View::te('coretan.loading') ?></p></div>
    <button id="load-more" class="secondary hidden" type="button"><?= \App\Core\View::te('coretan.load_more') ?></button>
  </section>
</main>
<script src="/assets/wall-coretan.js" defer></script>
<script src="/assets/topnav-auth.js" defer></script>
</body></html>
