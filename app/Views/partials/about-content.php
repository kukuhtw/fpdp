<?php
/**
 * Body of the public /about page, shared by every theme's about.php so the
 * description of what FPDP does stays in one place. Keep it in line with
 * documentation/PROGRESS-REPORT.en.md: only claim what the code does.
 *
 * Text comes from app/Lang/{id,en}/about.php. The about.* strings are
 * trusted, author-controlled static HTML (some hold <strong>, <code>, <em>
 * or <a href> markup), so they are printed unescaped with $t(). Anything
 * dynamic — visitor or owner data — must still be escaped (View::te() or
 * htmlspecialchars()).
 */
$t = static fn (string $key): string => \App\Core\View::t($key);
?>
<style>
  .about-hero{text-align:center;padding:48px 28px;background:var(--card);border:1px solid var(--line);border-radius:18px;margin-bottom:20px;}
  .about-hero h1{font:700 clamp(2.2rem,6vw,3.8rem)/1.05 Georgia,serif;margin:.2em 0;}
  .about-hero .tagline{font-size:1.15rem;color:var(--muted);max-width:560px;margin:0 auto;}
  .about-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}
  .about-features{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin:14px 0 4px;}
  .about-feature{padding:16px;border:1px solid var(--line);border-radius:12px;}
  .about-feature h3{margin:0 0 6px;font-size:1rem;}
  .about-feature p{margin:0;color:var(--muted);line-height:1.5;font-size:.94rem;}
  .about-card{padding:24px;background:var(--card);border:1px solid var(--line);border-radius:14px;}
  .about-card h2{font:700 1.3rem Georgia,serif;margin:0 0 10px;}
  .about-card p,.about-card ul,.about-card ol{margin:0 0 10px;color:var(--ink);line-height:1.6;}
  .about-card ul,.about-card ol{padding-left:20px;}
  .about-card .icon{font-size:1.8rem;margin-bottom:8px;}
  .about-card code{background:#f0efe8;padding:2px 6px;border-radius:5px;font-size:.9rem;}
  .fed-diagram{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;align-items:center;padding:18px 0 8px;}
  .fed-node{text-align:center;padding:16px;background:var(--card);border:2px solid var(--accent);border-radius:12px;}
  .fed-node .domain{font-weight:700;font-size:1rem;}
  .fed-node .handle{color:var(--muted);font-size:.85rem;}
  .fed-arrow{text-align:center;font-size:1.5rem;color:var(--accent);}
  .status-table{width:100%;border-collapse:collapse;font-size:.92rem;}
  .status-table th,.status-table td{padding:10px 14px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top;}
  .status-table th{background:#f4f2ea;font-weight:700;}
  .status-tag{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.78rem;font-weight:700;white-space:nowrap;}
  .status-done{background:#e3efe9;color:var(--accent);}
  .status-wip{background:#f5edce;color:#8a6f2b;}
  .status-todo{background:#f0efe8;color:var(--muted);}
  @media (max-width:700px){.about-grid{grid-template-columns:1fr;}.status-table th,.status-table td{padding:8px;}}
</style>


<section class="about-hero">
  <p class="eyebrow">FPDP</p>
  <h1><?= $t('about.hero.title') ?></h1>
  <p class="tagline"><?= $t('about.hero.tagline') ?></p>
</section>

<div class="about-grid">
  <article class="about-card">
    <div class="icon">🏠</div>
    <h2><?= $t('about.what.heading') ?></h2>
    <p><?= $t('about.what.intro') ?></p>
    <ul>
      <li><?= $t('about.what.domain') ?></li>
      <li><?= $t('about.what.content') ?></li>
      <li><?= $t('about.what.connections') ?></li>
      <li><?= $t('about.what.shop') ?></li>
    </ul>
  </article>

  <article class="about-card">
    <div class="icon">🌐</div>
    <h2><?= $t('about.how.heading') ?></h2>
    <p><?= $t('about.how.intro') ?></p>
    <ul>
      <li><?= $t('about.how.identity') ?></li>
      <li><?= $t('about.how.follow') ?></li>
      <li><?= $t('about.how.delivery') ?></li>
      <li><?= $t('about.how.timeline') ?></li>
    </ul>
  </article>
</div>

<article class="about-card" style="margin-bottom:20px;">
  <div class="icon">🧰</div>
  <h2><?= $t('about.features.heading') ?></h2>
  <div class="about-features">
<?php foreach (['publishing', 'shop', 'payments', 'cv', 'chatbot', 'feeds', 'themes', 'dashboard'] as $feature): ?>
    <div class="about-feature"><h3><?= $t('about.features.' . $feature . '.title') ?></h3><p><?= $t('about.features.' . $feature . '.text') ?></p></div>
<?php endforeach; ?>
  </div>
</article>

<article class="about-card" style="margin-bottom:20px;">
  <div class="icon">🔗</div>
  <h2><?= $t('about.action.heading') ?></h2>
  <p><?= $t('about.action.intro') ?></p>
  <div class="fed-diagram">
    <div class="fed-node"><div class="domain">kukuhtw.com</div><div class="handle">@kukuh</div><div><?= $t('about.action.role_owner') ?></div></div>
    <div class="fed-arrow">⇄</div>
    <div class="fed-node"><div class="domain">maya.id</div><div class="handle">@maya</div><div><?= $t('about.action.role_friend') ?></div></div>
    <div class="fed-arrow">⇄</div>
    <div class="fed-node"><div class="domain">mastodon.social</div><div class="handle">@ari</div><div><?= $t('about.action.role_colleague') ?></div></div>
  </div>
  <ol>
    <li><?= $t('about.action.find') ?></li>
    <li><?= $t('about.action.follow') ?></li>
    <li><?= $t('about.action.receive') ?></li>
    <li><?= $t('about.action.share') ?></li>
  </ol>
</article>

<div class="about-grid">
  <article class="about-card">
    <div class="icon">🔌</div>
    <h2><?= $t('about.connect.heading') ?></h2>
    <ol>
      <li><?= $t('about.connect.node') ?></li>
      <li><?= $t('about.connect.address') ?></li>
      <li><?= $t('about.connect.find') ?></li>
      <li><?= $t('about.connect.approve') ?></li>
      <li><?= $t('about.connect.publish') ?></li>
    </ol>
    <p><?= $t('about.connect.open') ?></p>
  </article>

  <article class="about-card">
    <div class="icon">📋</div>
    <h2><?= $t('about.status.heading') ?></h2>
    <p><?= $t('about.status.intro') ?></p>
    <table class="status-table">
      <tr><th><?= $t('about.status.col_feature') ?></th><th><?= $t('about.status.col_status') ?></th></tr>
<?php
// row key => status (the CSS class and the label key share the name suffix)
$statusRows = [
    'identity' => 'done',
    'follow' => 'done',
    'delivery' => 'done',
    'incoming' => 'done',
    'promoted' => 'done',
    'discovery' => 'done',
    'block' => 'done',
    'mastodon' => 'done',
    'interactions' => 'planned',
    'flag' => 'planned',
    'products' => 'done',
    'native_order' => 'deferred',
];
$statusClass = ['done' => 'status-done', 'planned' => 'status-todo', 'deferred' => 'status-todo'];
foreach ($statusRows as $row => $status): ?>
      <tr><td><?= $t('about.status.row_' . $row) ?></td><td><span class="status-tag <?= $statusClass[$status] ?>"><?= $t('about.status.' . $status) ?></span></td></tr>
<?php endforeach; ?>
    </table>
  </article>
</div>

<article class="about-card">
  <div class="icon">📖</div>
  <h2><?= $t('about.docs.heading') ?></h2>
  <p><?= $t('about.docs.intro') ?></p>
  <ul>
    <li><?= $t('about.docs.progress') ?></li>
    <li><?= $t('about.docs.federation') ?></li>
    <li><?= $t('about.docs.api') ?></li>
    <li><?= $t('about.docs.deployment') ?></li>
    <li><?= $t('about.docs.roadmap') ?></li>
    <li><?= $t('about.docs.requirements') ?></li>
  </ul>
</article>
