<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <style>
      .about-hero{text-align:center;padding:48px 28px;background:var(--card);border:1px solid var(--line);border-radius:18px;margin-bottom:20px;}
      .about-hero h1{font:700 clamp(2.2rem,6vw,3.8rem)/1.05 Georgia,serif;margin:.2em 0;}
      .about-hero .tagline{font-size:1.15rem;color:var(--muted);max-width:560px;margin:0 auto;}
      .about-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}
      .about-card{padding:24px;background:var(--card);border:1px solid var(--line);border-radius:14px;}
      .about-card h2{font:700 1.3rem Georgia,serif;margin:0 0 10px;}
      .about-card p,.about-card ul{margin:0 0 10px;color:var(--ink);line-height:1.6;}
      .about-card ul{padding-left:20px;}
      .about-card .icon{font-size:1.8rem;margin-bottom:8px;}
      .about-card code{background:#f0efe8;padding:2px 6px;border-radius:5px;font-size:.9rem;}
      .fed-diagram{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;align-items:center;padding:18px 0 8px;}
      .fed-node{text-align:center;padding:16px;background:var(--card);border:2px solid var(--accent);border-radius:12px;}
      .fed-node .domain{font-weight:700;font-size:1rem;}
      .fed-node .handle{color:var(--muted);font-size:.85rem;}
      .fed-arrow{text-align:center;font-size:1.5rem;color:var(--accent);}
      .status-table{width:100%;border-collapse:collapse;font-size:.92rem;}
      .status-table th,.status-table td{padding:10px 14px;border-bottom:1px solid var(--line);text-align:left;}
      .status-table th{background:#f4f2ea;font-weight:700;}
      .status-tag{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.78rem;font-weight:700;}
      .status-done{background:#e3efe9;color:var(--accent);}
      .status-wip{background:#f5edce;color:#8a6f2b;}
      .status-todo{background:#f0efe8;color:var(--muted);}
      @media (max-width:700px){.about-grid{grid-template-columns:1fr;}}
    </style>
</head>
<body>
<nav class="topbar"><a class="brand" href="/">FPDP</a><div class="nav-links"><a href="/">Home</a><a href="/about-me">About Me</a><a href="/youtube">YouTube</a><a href="/coretan">Coretan</a><a href="/about">About FPDP</a><a href="/timeline">Timeline</a><a href="/dashboard" class="owner-nav hidden">Dashboard</a><a href="/dashboard/about-me" class="owner-nav hidden">Edit About Me</a><a href="/dashboard/coretan" class="owner-nav hidden">Kelola Coretan</a><a href="/dashboard/posts" class="owner-nav hidden">Post editor</a><a href="/dashboard/posts/list" class="owner-nav hidden">My posts</a><a href="/dashboard/cv" class="owner-nav hidden">CV &amp; Resume</a><a href="/dashboard/integrations" class="owner-nav hidden">Integrations</a><a href="/dashboard/settings" class="owner-nav hidden">Settings</a><a href="/dashboard/federation" class="owner-nav hidden">Federasi</a></div></nav>
<main class="shell">
  <section class="about-hero">
    <p class="eyebrow">FPDP</p>
    <h1>Federated Personal Digital Platform</h1>
    <p class="tagline">Your domain is your digital home — not a profile on someone else's platform.</p>
  </section>

  <div class="about-grid">
    <article class="about-card">
      <div class="icon">🏠</div>
      <h2>What is FPDP?</h2>
      <p>FPDP is a self-hosted, federated personal digital platform that gives every individual their own internet node — not just another profile on a central platform. Each FPDP instance is an independent node with its own domain, identity, content, and connections.</p>
      <ul>
        <li><strong>Own your domain</strong> — Your identity lives at your domain, not on a platform you don't control.</li>
        <li><strong>Own your content</strong> — Posts, articles, media, and data stay on your node.</li>
        <li><strong>Own your connections</strong> — Follow other nodes, share content, and build a federated network.</li>
        <li><strong>Own your commerce</strong> — Choose your payment gateway, sell products, and accept payments.</li>
      </ul>
    </article>

    <article class="about-card">
      <div class="icon">🌐</div>
      <h2>How Federation Works</h2>
      <p>Every FPDP node speaks a common protocol (ActivityPub-compatible) to communicate with other nodes. This creates a <strong>federated network</strong> without a central server.</p>
      <ul>
        <li><strong>Node identity</strong> — Each node has a cryptographic key pair for signing activities.</li>
        <li><strong>Follow</strong> — User A on node A can follow User B on node B by sending a signed Follow activity.</li>
        <li><strong>Content delivery</strong> — When User B publishes a post, node B delivers it to all accepted followers.</li>
        <li><strong>Timeline</strong> — Each user sees a unified timeline of local, external, and federated content.</li>
      </ul>
    </article>
  </div>

  <article class="about-card" style="margin-bottom:20px;">
    <div class="icon">🔗</div>
    <h2>Federation in action</h2>
    <p>Imagine three independent FPDP nodes, each on their own domain:</p>
    <div class="fed-diagram">
      <div class="fed-node"><div class="domain">kukuhtw.com</div><div class="handle">@kukuh</div><div>Owner</div></div>
      <div class="fed-arrow">⇄</div>
      <div class="fed-node"><div class="domain">maya.id</div><div class="handle">@maya</div><div>Friend</div></div>
      <div class="fed-arrow">⇄</div>
      <div class="fed-node"><div class="domain">arinode.id</div><div class="handle">@ari</div><div>Colleague</div></div>
    </div>
    <ol>
<ol>
      <li><strong>Follow:</strong> @kukuh sends a Follow request to @ari@arinode.id.</li>
      <li><strong>Accept:</strong> The arinode.id node verifies the request and accepts it.</li>
      <li><strong>Receive:</strong> When @ari publishes a new post, kukuhtw.com receives it and shows it in @kukuh's timeline.</li>
      <li><strong>Engage:</strong> @kukuh can like, reply, or re-share (announce) the post to their own followers.</li>
    </ol>
  </article>

  <div class="about-grid">
    <article class="about-card">
      <div class="icon">🔌</div>
      <h2>How to Connect</h2>
      <p>To connect with other FPDP nodes or ActivityPub-compatible platforms (like Mastodon, Pixelfed):</p>
      <ol>
        <li><strong>Get a node</strong> — Deploy FPDP on your own domain (see <a href="https://github.com/kukuhtw/fpdp">GitHub</a>).</li>
        <li><strong>Set up your profile</strong> — Create your handle, display name, and bio.</li>
        <li><strong>Find other users</strong> — Each FPDP profile is accessible at <code>/@{handle}</code> on its domain.</li>
        <li><strong>Connect</strong> — Send a follow request to another user's federated address (<code>@user@domain</code>).</li>
        <li><strong>Publish</strong> — Create posts on your node. They will be delivered to your followers automatically.</li>
      </ol>
      <p>Federation is open — any compatible node can join the network without asking permission.</p>
    </article>

    <article class="about-card">
      <div class="icon">📋</div>
      <h2>Current Status</h2>
      <p>FPDP is in active development. Below is the implementation status of federation features:</p>
      <table class="status-table">
        <tr><th>Feature</th><th>Status</th></tr>
        <tr><td>Local content publishing</td><td><span class="status-tag status-done">Done</span></td></tr>
        <tr><td>Node identity &amp; keys</td><td><span class="status-tag status-done">Done</span></td></tr>
        <tr><td>Remote node discovery</td><td><span class="status-tag status-wip">In progress</span></td></tr>
        <tr><td>Follow / Accept / Reject</td><td><span class="status-tag status-wip">In progress</span></td></tr>
        <tr><td>Content delivery (federated)</td><td><span class="status-tag status-todo">Planned</span></td></tr>
        <tr><td>Federated timeline</td><td><span class="status-tag status-todo">Planned</span></td></tr>
        <tr><td>Block / mute / report</td><td><span class="status-tag status-todo">Planned</span></td></tr>
        <tr><td>ActivityPub compatibility</td><td><span class="status-tag status-todo">Planned</span></td></tr>
      </table>
    </article>
  </div>

  <article class="about-card">
    <div class="icon">📖</div>
    <h2>Technical Documentation</h2>
    <p>For developers and node operators, the full technical documentation is available in the <a href="https://github.com/kukuhtw/fpdp">FPDP repository</a>:</p>
    <ul>
      <li><a href="/documentation/FEDERATION-CONCEPT.en.md">Federation Concept</a> — Detailed federation protocol design.</li>
      <li><a href="/documentation/API-CONTRACT.en.md">API Contract</a> — Complete API specification.</li>
      <li><a href="/documentation/PRD.en.md">Product Requirements</a> — Full feature specification.</li>
      <li><a href="/documentation/BRD.en.md">Business Requirements</a> — Business context and goals.</li>
      <li><a href="/documentation/ROADMAP.en.md">Roadmap</a> — Development roadmap.</li>
    </ul>
  </article>
</main>
</body>
</html>
