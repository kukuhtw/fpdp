<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><?= \App\Core\View::themeStylesheetTag() ?>
<style>
  .range-filter{justify-self:start;display:inline-flex;gap:4px;padding:4px;background:var(--card);border:1px solid var(--line);border-radius:12px;}
  .range-filter button{background:transparent;color:var(--muted);padding:7px 14px;font-size:.88rem;}
  .range-filter button[aria-pressed="true"]{background:var(--accent);color:#fff;}
  .kpi-delta{margin:6px 0 0;font-size:.82rem;color:var(--muted);}
  .kpi-delta .up,.kpi-delta .down{font-weight:700;color:var(--ink);}
  .an-chart{position:relative;}
  .an-chart svg{display:block;width:100%;height:auto;overflow:visible;}
  .an-grid{stroke:var(--line);stroke-width:1;}
  .an-axis{fill:var(--muted);font:11px system-ui,sans-serif;font-variant-numeric:tabular-nums;}
  .an-line{fill:none;stroke-width:2;stroke-linejoin:round;stroke-linecap:round;}
  .an-end{fill:var(--ink);font:600 12px system-ui,sans-serif;}
  .an-cross{stroke:var(--muted);stroke-width:1;}
  .an-dot{stroke:var(--card);stroke-width:2;}
  .an-hit{fill:transparent;cursor:crosshair;}
  .an-hit:focus-visible{outline:2px solid var(--accent);outline-offset:2px;}
  .an-tooltip{position:absolute;top:0;pointer-events:none;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:8px 10px;font-size:.82rem;box-shadow:0 6px 18px rgba(31,38,33,.12);white-space:nowrap;}
  .an-tooltip .key{display:inline-block;width:10px;height:3px;border-radius:2px;margin-right:6px;vertical-align:middle;}
  .an-table{width:100%;border-collapse:collapse;font-size:.9rem;}
  .an-table th,.an-table td{padding:7px 10px;border-bottom:1px solid var(--line);text-align:left;}
  .an-table td.num,.an-table th.num{text-align:right;font-variant-numeric:tabular-nums;}
  .an-lists{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;}
  .an-list{list-style:none;margin:0;padding:0;}
  .an-list li{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--line);}
  .an-list li span:first-child{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .an-list .count{font-variant-numeric:tabular-nums;color:var(--muted);flex:none;}
</style>
</head>
<body>
<?php \App\Core\View::partial('topnav'); ?>
<main class="shell dashboard-shell">
  <section class="panel">
    <p class="eyebrow">Owner dashboard</p><h1>Analytics</h1>
    <p id="auth-summary" class="muted">Masuk untuk melihat analytics.</p>
    <form id="login-form" class="stack" method="post">
      <label>Email<input name="email" type="email" autocomplete="email" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button>Masuk</button>
    </form>
  </section>

  <section id="analytics-content" class="hidden stack">
    <div class="range-filter" role="group" aria-label="Rentang waktu">
      <button type="button" data-days="7" aria-pressed="false">7 hari</button>
      <button type="button" data-days="30" aria-pressed="true">30 hari</button>
      <button type="button" data-days="90" aria-pressed="false">90 hari</button>
    </div>

    <section class="kpi-row" id="kpi-row" aria-label="Ringkasan"></section>

    <section class="panel">
      <h2 id="chart-title">Pengunjung per hari</h2>
      <div class="chart-legend" id="chart-legend"></div>
      <div class="an-chart" id="traffic-chart"><p class="muted">Memuat…</p></div>
      <details>
        <summary>Tampilkan sebagai tabel</summary>
        <div id="traffic-table"></div>
      </details>
      <p class="muted">Kunjungan = pengunjung unik per hari, dijumlahkan. Pengenal pengunjung diganti setiap hari dan IP tidak pernah disimpan, jadi orang yang sama di dua hari berbeda terhitung dua kali. Bot, crawler, pratinjau link dari server fediverse, dan halaman dashboard tidak dihitung.</p>
    </section>

    <section class="panel">
      <h2>Halaman teratas</h2>
      <div id="top-pages"><p class="muted">Memuat…</p></div>
    </section>

    <div class="an-lists">
      <section class="panel">
        <h2>Asal pengunjung</h2>
        <p class="muted">Situs yang mengarahkan pengunjung ke sini (hanya nama domainnya).</p>
        <ul class="an-list" id="referrers"></ul>
      </section>
      <section class="panel">
        <h2>Link keluar yang diklik</h2>
        <ul class="an-list" id="outbound"></ul>
      </section>
    </div>
  </section>
</main>
<script src="/assets/dashboard-analytics.js" defer></script>
</body></html>
