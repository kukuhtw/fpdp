<?php
$postUrl = '/posts/' . rawurlencode((string) $post['public_id']);
$profileUrl = '/@' . rawurlencode((string) $post['handle']);
?>
<article class="post-card">
    <header class="post-meta">
        <a href="<?= htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars((string) $post['display_name'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <span>@<?= htmlspecialchars((string) $post['handle'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($post['published_at'] !== null): ?>
            <time datetime="<?= htmlspecialchars((string) $post['published_at'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(date('M j, Y', strtotime((string) $post['published_at'])), ENT_QUOTES, 'UTF-8') ?>
            </time>
        <?php endif; ?>
    </header>
    <?php if ($post['title'] !== null && $post['title'] !== ''): ?>
        <h2><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $post['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
    <?php endif; ?>
    <div class="post-content"><?= nl2br(htmlspecialchars((string) $post['content'], ENT_QUOTES, 'UTF-8')) ?></div>
    <footer><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>">Permalink</a></footer>
</article>
