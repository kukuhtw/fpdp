<?php
/**
 * Body of the public /about page, shared by every theme's about.php so the
 * description of what FPDP does stays in one place. Keep it in line with
 * documentation/PROGRESS-REPORT.en.md: only claim what the code does.
 */
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
  <h1>Federated Personal Digital Platform</h1>
  <p class="tagline">Your domain is your digital home — not a profile on someone else's platform.</p>
</section>

<div class="about-grid">
  <article class="about-card">
    <div class="icon">🏠</div>
    <h2>What is FPDP?</h2>
    <p>FPDP is a self-hosted personal digital platform. Each installation is one independent node, on one domain, owned by one person: their website, social profile, shop, and inbox in one place, connected to the fediverse.</p>
    <ul>
      <li><strong>Own your domain</strong> — your identity is <code>@you@your-domain</code>, not an account on a platform you don't control.</li>
      <li><strong>Own your content</strong> — posts, media, CV, and data stay on your node.</li>
      <li><strong>Own your connections</strong> — follow and be followed by Mastodon users and other FPDP nodes.</li>
      <li><strong>Own your shop</strong> — sell your own products to visitors and pick the payment gateway yourself.</li>
    </ul>
  </article>

  <article class="about-card">
    <div class="icon">🌐</div>
    <h2>How federation works</h2>
    <p>FPDP speaks <strong>ActivityPub</strong>, the protocol behind Mastodon and the wider fediverse, so there is no central server.</p>
    <ul>
      <li><strong>Identity</strong> — every node publishes WebFinger and an actor document, and signs what it sends with its own RSA key (HTTP Signatures).</li>
      <li><strong>Follow</strong> — Follow, Accept, Reject, and Undo, including follow requests you approve yourself.</li>
      <li><strong>Delivery</strong> — your posts and promoted products are delivered to followers, with retry and backoff.</li>
      <li><strong>Timeline</strong> — incoming posts (created, edited, deleted, with images) join your local posts, external feeds, and products in one timeline.</li>
    </ul>
  </article>
</div>

<article class="about-card" style="margin-bottom:20px;">
  <div class="icon">🧰</div>
  <h2>What a node includes today</h2>
  <div class="about-features">
    <div class="about-feature"><h3>📝 Publishing</h3><p>Posts and articles with media upload, drafts, visibility, and a public profile at <code>/@handle</code>.</p></div>
    <div class="about-feature"><h3>🛍️ Personal online shop</h3><p>Physical and digital products, public checkout, and downloads after payment. Promoted products appear in followers' fediverse timelines.</p></div>
    <div class="about-feature"><h3>💳 Payments</h3><p>Midtrans, PayPal, Paywuz, iPaymu, or manual bank transfer; confirmation, cancel, refund, and reconciliation from the dashboard.</p></div>
    <div class="about-feature"><h3>📄 Paid CV access</h3><p>Visitors sign in with Google and pay once to download the owner's CV.</p></div>
    <div class="about-feature"><h3>🤖 AI chatbot</h3><p>A chatbot answering from the owner's own documents and FAQ, using the owner's LLM provider, paid from a visitor wallet.</p></div>
    <div class="about-feature"><h3>🔁 External feeds</h3><p>RSS, Atom, YouTube, custom APIs, and LinkedIn pages, shown with attribution and links to the original.</p></div>
    <div class="about-feature"><h3>🎨 Themes</h3><p>Default, editorial, and minimal themes, switchable from the dashboard.</p></div>
    <div class="about-feature"><h3>🔐 Owner dashboard</h3><p>One owner per node, with an audit trail of sensitive actions such as payments, credentials, and federation trust.</p></div>
  </div>
</article>

<article class="about-card" style="margin-bottom:20px;">
  <div class="icon">🔗</div>
  <h2>Federation in action</h2>
  <p>Three independent nodes, each on its own domain — any of them could just as well be a Mastodon server:</p>
  <div class="fed-diagram">
    <div class="fed-node"><div class="domain">kukuhtw.com</div><div class="handle">@kukuh</div><div>FPDP owner</div></div>
    <div class="fed-arrow">⇄</div>
    <div class="fed-node"><div class="domain">maya.id</div><div class="handle">@maya</div><div>Friend</div></div>
    <div class="fed-arrow">⇄</div>
    <div class="fed-node"><div class="domain">mastodon.social</div><div class="handle">@ari</div><div>Colleague</div></div>
  </div>
  <ol>
    <li><strong>Find:</strong> @kukuh looks up <code>@ari@mastodon.social</code> in the dashboard and previews the profile.</li>
    <li><strong>Follow:</strong> kukuhtw.com sends a signed Follow; ari's server accepts it.</li>
    <li><strong>Receive:</strong> when @ari posts, edits, or deletes a post, kukuhtw.com gets it and updates @kukuh's timeline.</li>
    <li><strong>Share:</strong> when @kukuh promotes a product, it reaches @ari's timeline as a post linking back to the shop.</li>
  </ol>
</article>

<div class="about-grid">
  <article class="about-card">
    <div class="icon">🔌</div>
    <h2>How to connect</h2>
    <ol>
      <li><strong>Get a node</strong> — deploy FPDP on your own domain (Docker/Dokploy, a VPS, or shared hosting — see <a href="https://github.com/kukuhtw/fpdp">GitHub</a>).</li>
      <li><strong>Share your address</strong> — people follow you at <code>@handle@your-domain</code>, from Mastodon or another FPDP node.</li>
      <li><strong>Find people</strong> — in <em>Dashboard → Federation</em>, search an address, see follow-back suggestions, or browse a Mastodon server's directory and hashtags.</li>
      <li><strong>Approve followers</strong> — incoming follow requests wait for your approval.</li>
      <li><strong>Publish</strong> — new posts and promoted products are delivered to your followers automatically.</li>
    </ol>
    <p>Federation is open — any ActivityPub server can connect without asking permission, and you can block any server or account.</p>
  </article>

  <article class="about-card">
    <div class="icon">📋</div>
    <h2>Federation status</h2>
    <p>FPDP is in active development. What the code does today:</p>
    <table class="status-table">
      <tr><th>Feature</th><th>Status</th></tr>
      <tr><td>WebFinger, actor documents, RSA keys &amp; HTTP Signatures</td><td><span class="status-tag status-done">Done</span></td></tr>
      <tr><td>Follow / Accept / Reject / Undo, follow approval</td><td><span class="status-tag status-done">Done</span></td></tr>
      <tr><td>Signed delivery with retry</td><td><span class="status-tag status-done">Done</span></td></tr>
      <tr><td>Incoming posts (create, edit, delete, images)</td><td><span class="status-tag status-done">Done</span></td></tr>
      <tr><td>Promoted products to the fediverse</td><td><span class="status-tag status-done">Done</span></td></tr>
      <tr><td>Account discovery (search, suggestions, directory, hashtags)</td><td><span class="status-tag status-done">Done</span></td></tr>
      <tr><td>Block servers and accounts, mute connections</td><td><span class="status-tag status-done">Done</span></td></tr>
      <tr><td>Works with Mastodon (mastodon.social, mastodon.world)</td><td><span class="status-tag status-done">Done</span></td></tr>
      <tr><td>Likes, boosts, and replies</td><td><span class="status-tag status-todo">Planned</span></td></tr>
      <tr><td>Reporting accounts (Flag)</td><td><span class="status-tag status-todo">Planned</span></td></tr>
      <tr><td>Federated commerce — ordering from another node</td><td><span class="status-tag status-todo">Planned</span></td></tr>
    </table>
  </article>
</div>

<article class="about-card">
  <div class="icon">📖</div>
  <h2>Documentation</h2>
  <p>For developers and node operators — source code on <a href="https://github.com/kukuhtw/fpdp">GitHub</a>, licensed under Apache-2.0:</p>
  <ul>
    <li><a href="/documentation/PROGRESS-REPORT.en.md">Progress Report</a> — what is done, partial, and not started.</li>
    <li><a href="/documentation/FEDERATION-CONCEPT.en.md">Federation Concept</a> — federation protocol design.</li>
    <li><a href="/documentation/API-CONTRACT.en.md">API Contract</a> — API specification.</li>
    <li><a href="/documentation/DEPLOYMENT-GUIDE.en.md">Deployment Guide</a> — VPS, shared hosting, and Docker.</li>
    <li><a href="/documentation/ROADMAP.en.md">Roadmap</a> — development phases.</li>
    <li><a href="/documentation/PRD.en.md">Product Requirements</a> and <a href="/documentation/BRD.en.md">Business Requirements</a>.</li>
  </ul>
</article>
