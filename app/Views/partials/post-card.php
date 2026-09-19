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
    <?php if (($post['media'] ?? []) !== []): ?><div class="post-media">
        <?php foreach ($post['media'] as $media): ?>
            <?php if ($media['media_type'] === 'IMAGE'): ?>
                <img src="<?= htmlspecialchars((string) $media['url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($media['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" referrerpolicy="no-referrer">
            <?php else: ?>
                <a class="media-link" href="<?= htmlspecialchars((string) $media['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string) $media['media_type'], ENT_QUOTES, 'UTF-8') ?><?= $media['alt_text'] ? ' · ' . htmlspecialchars((string) $media['alt_text'], ENT_QUOTES, 'UTF-8') : '' ?> ↗</a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div><?php endif; ?>
    <footer><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>">Permalink</a></footer>
</article>
