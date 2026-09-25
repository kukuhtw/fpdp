<?php
$isFederated = !empty($post['is_federated']);
$isProduct = !empty($post['is_product']);
if ($isFederated) {
    $postUrl = (string) ($post['permalink'] ?? '#');
    $profileUrl = (string) ($post['profile_link'] ?? $postUrl);
    $externalAttrs = ' target="_blank" rel="noopener noreferrer"';
} elseif ($isProduct) {
    $postUrl = (string) ($post['permalink'] ?? '#');
    $profileUrl = '/@' . rawurlencode((string) $post['handle']);
    $externalAttrs = '';
} else {
    $slug = ($post['slug'] ?? '') !== '' ? '-' . rawurlencode((string) $post['slug']) : '';
    $postUrl = '/posts/' . (int) $post['id'] . $slug;
    $profileUrl = '/@' . rawurlencode((string) $post['handle']);
    $externalAttrs = '';
}
?>
<article class="post-card<?= $isFederated ? ' post-card-federated' : '' ?><?= $isProduct ? ' post-card-product' : '' ?>">
    <header class="post-meta">
        <a href="<?= htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?>>
            <?= htmlspecialchars((string) $post['display_name'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <span>@<?= htmlspecialchars((string) $post['handle'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($isFederated): ?><span class="badge-fediverse" title="Post dari akun yang Anda follow di fediverse">Fediverse</span><?php endif; ?>
        <?php if ($isProduct): ?><span class="badge-product" title="Produk yang dipromosikan">Produk</span><?php endif; ?>
        <?php if ($post['published_at'] !== null): ?>
            <time datetime="<?= htmlspecialchars((string) $post['published_at'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(date('M j, Y', strtotime((string) $post['published_at'])), ENT_QUOTES, 'UTF-8') ?>
            </time>
        <?php endif; ?>
    </header>
    <?php if (($post['title'] ?? null) !== null && $post['title'] !== ''): ?>
        <h2><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?>><?= htmlspecialchars((string) $post['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
    <?php endif; ?>
    <?php if (!empty($excerpt)): ?>
        <div class="post-content"><?= nl2br(htmlspecialchars(\App\Core\View::excerpt((string) $post['content']), ENT_QUOTES, 'UTF-8')) ?></div>
    <?php else: ?>
        <div class="post-content"><?= (string) $post['content'] ?></div>
    <?php endif; ?>
    <?php if (($post['media'] ?? []) !== []): ?><div class="post-media">
        <?php foreach ($post['media'] as $media): ?>
            <?php if ($media['media_type'] === 'IMAGE'): ?>
                <img src="<?= htmlspecialchars((string) $media['url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($media['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" referrerpolicy="no-referrer">
            <?php else: ?>
                <a class="media-link" href="<?= htmlspecialchars((string) $media['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string) $media['media_type'], ENT_QUOTES, 'UTF-8') ?><?= $media['alt_text'] ? ' · ' . htmlspecialchars((string) $media['alt_text'], ENT_QUOTES, 'UTF-8') : '' ?> ↗</a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div><?php endif; ?>
    <footer><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?>><?= $isFederated ? 'Lihat postingan asli ↗' : ($isProduct ? 'Lihat produk & beli →' : 'Permalink') ?></a></footer>
</article>
