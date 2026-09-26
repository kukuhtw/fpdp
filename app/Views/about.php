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
<main class="shell">
  <?php \App\Core\View::partial('about-content'); ?>
</main>
<script src="/assets/topnav-auth.js" defer></script>
</body>
</html>
