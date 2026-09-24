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
<main class="shell narrow">
    <?php \App\Core\View::partial('post-card', ['post' => $post]); ?>
</main>
</body>
</html>
