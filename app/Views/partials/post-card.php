<?php
$isFederated = !empty($post['is_federated']);
$remoteProduct = $isFederated && is_array($post['remote_product'] ?? null) ? $post['remote_product'] : null;
$isProduct = !empty($post['is_product']) || $remoteProduct !== null;
if ($remoteProduct !== null) {
    // Another FPDP node's product: every link goes to the seller's own
    // checkout (validated on receipt to be https on the seller's host).
    $postUrl = (string) $remoteProduct['checkout_url'];
    $profileUrl = (string) ($post['profile_link'] ?? $postUrl);
    $externalAttrs = ' target="_blank" rel="noopener noreferrer"';
} elseif ($isFederated) {
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
        <?php if ($isFederated): ?><span class="badge-fediverse" title="<?= \App\Core\View::te('card.fediverse_title') ?>"><?= \App\Core\View::te('card.fediverse_badge') ?></span><?php endif; ?>
        <?php if ($isProduct): ?><span class="badge-product" title="<?= \App\Core\View::te('card.product_title') ?>"><?= \App\Core\View::te('card.product_badge') ?></span><?php endif; ?>
        <?php if ($post['published_at'] !== null): ?>
            <time datetime="<?= htmlspecialchars((string) $post['published_at'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(date('M j, Y', strtotime((string) $post['published_at'])), ENT_QUOTES, 'UTF-8') ?>
            </time>
        <?php endif; ?>
    </header>
    <?php if ($remoteProduct !== null): ?>
        <p class="post-price"><strong><?= htmlspecialchars((string) $remoteProduct['currency'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars(number_format((float) $remoteProduct['price'], $remoteProduct['currency'] === 'IDR' ? 0 : 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?></strong></p>
    <?php endif; ?>
    <?php if (($post['title'] ?? null) !== null && $post['title'] !== ''): ?>
        <h2><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?>><?= htmlspecialchars((string) $post['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
    <?php endif; ?>
    <?php if (!empty($excerpt)): ?>
        <div class="post-content"><?= nl2br(\App\Core\View::autolink(htmlspecialchars(\App\Core\View::excerpt((string) $post['content']), ENT_QUOTES, 'UTF-8'))) ?></div>
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
    <footer><a href="<?= htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $externalAttrs ?>><?php if ($remoteProduct !== null): ?><?= \App\Core\View::te('card.buy_at', ['domain' => (string) $remoteProduct['seller_domain']]) ?><?php else: ?><?= \App\Core\View::te($isFederated ? 'card.view_original' : ($isProduct ? 'card.view_product' : 'card.permalink')) ?><?php endif; ?></a></footer>
</article>
