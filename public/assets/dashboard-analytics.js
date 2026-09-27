// Owner Analytics page: range filter, stat tiles with change vs the previous
// period, a two-series line chart (page views, visits) with crosshair
// tooltip and keyboard support, a table view of the same numbers, and the
// top pages / referrers / outbound-link lists. Everything from the API is
// rendered with textContent.
(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#analytics-content');
  const kpiRow = document.querySelector('#kpi-row');
  const chartHost = document.querySelector('#traffic-chart');
  const chartLegend = document.querySelector('#chart-legend');
  const chartTitle = document.querySelector('#chart-title');
  const tableHost = document.querySelector('#traffic-table');
  const topPagesHost = document.querySelector('#top-pages');
  const referrersList = document.querySelector('#referrers');
  const outboundList = document.querySelector('#outbound');
  const rangeButtons = document.querySelectorAll('.range-filter [data-days]');
  const SVG_NS = 'http://www.w3.org/2000/svg';

  // Same series colors as the Overview chart (validated palette, see app.css).
  const SERIES = [
    { key: 'page_views', label: 'Tayangan halaman', color: 'var(--series-views)' },
    { key: 'visits', label: 'Kunjungan', color: 'var(--series-visitors)' },
  ];
  const PAGE_LABELS = {
    home: 'Beranda', profile: 'Profil', about_me: 'Tentang Saya', coretan: 'Coretan', youtube: 'YouTube',
    timeline: 'Timeline', about_fpdp: 'Tentang FPDP', shop: 'Toko', cv: 'CV & Resume', post: 'Post', product: 'Produk',
  };

  const token = () => sessionStorage.getItem(tokenKey);
  const api = async (path, options = {}) => {
    const headers = { ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Permintaan gagal (${response.status})`);
    return payload;
  };
  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  };
  const svg = (tag, attrs = {}) => {
    const node = document.createElementNS(SVG_NS, tag);
    for (const [name, value] of Object.entries(attrs)) node.setAttribute(name, String(value));
    return node;
  };
  const number = (n) => Number(n).toLocaleString('id-ID');
  const compact = (n) => new Intl.NumberFormat('id-ID', { notation: n >= 10000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(n);
  const day = (iso, long = false) => new Date(`${iso}T00:00:00Z`).toLocaleDateString('id-ID', long ? { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' } : { day: 'numeric', month: 'short', timeZone: 'UTC' });

  // ---- Stat tiles ----
  const delta = (current, previous, days) => {
    const p = el('p', 'kpi-delta');
    if (previous === 0) {
      p.textContent = current === 0 ? `sama dengan ${days} hari sebelumnya` : `baru (0 pada ${days} hari sebelumnya)`;
      return p;
    }
    const change = Math.round(((current - previous) / previous) * 100);
    if (change === 0) {
      p.textContent = `sama dengan ${days} hari sebelumnya`;
      return p;
    }
    // Direction shown by glyph and words, never by color alone.
    const mark = el('span', change > 0 ? 'up' : 'down', `${change > 0 ? '▲' : '▼'} ${Math.abs(change)}%`);
    p.append(mark, ` ${change > 0 ? 'naik' : 'turun'} vs ${days} hari sebelumnya`);
    return p;
  };
  const renderKpis = (report) => {
    const tiles = [
      ['Kunjungan', 'visits'],
      ['Tayangan halaman', 'page_views'],
      ['Klik link keluar', 'outbound_clicks'],
      ['Pesanan dari toko', 'shop_conversions'],
    ];
    kpiRow.replaceChildren(...tiles.map(([label, key]) => {
      const tile = el('div', 'kpi-tile');
      tile.append(el('p', 'kpi-label', label), el('p', 'kpi-value', compact(report.totals[key])), delta(report.totals[key], report.previous[key], report.window_days));
      return tile;
    }));
  };

  // ---- Line chart ----
  const niceMax = (value) => {
    if (value <= 4) return 4;
    const magnitude = 10 ** Math.floor(Math.log10(value));
    for (const step of [1, 2, 2.5, 5, 10]) {
      if (step * magnitude >= value) return step * magnitude;
    }
    return 10 * magnitude;
  };

  const renderChart = (daily, days) => {
    chartTitle.textContent = `Pengunjung per hari — ${days} hari terakhir`;
    chartLegend.replaceChildren(...SERIES.map((s) => {
      const item = el('span');
      const swatch = el('span', 'swatch');
      swatch.style.background = s.color;
      item.append(swatch, s.label);
      return item;
    }));

    // Drawn at the container's real width so text stays at its real size
    // on a phone; narrow charts drop the end labels (legend + tooltip carry
    // them) and show fewer dates.
    const W = Math.max(300, Math.round(chartHost.clientWidth || 720));
    const narrow = W < 520;
    const H = narrow ? 220 : 260;
    const m = { top: 14, right: narrow ? 12 : 118, bottom: 28, left: 40 };
    const plotW = W - m.left - m.right;
    const plotH = H - m.top - m.bottom;
    const n = daily.length;
    const max = niceMax(Math.max(...daily.map((d) => Math.max(d.page_views, d.visits)), 0));
    const x = (i) => m.left + (n <= 1 ? plotW / 2 : (i * plotW) / (n - 1));
    const y = (v) => m.top + plotH - (v / max) * plotH;

    const root = svg('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': `Tayangan halaman dan kunjungan per hari, ${days} hari terakhir. Angka lengkapnya ada di tabel di bawah grafik.` });

    // Recessive hairline grid with a few ticks.
    for (let t = 0; t <= 4; t += 1) {
      const value = (max * t) / 4;
      root.append(svg('line', { class: 'an-grid', x1: m.left, x2: m.left + plotW, y1: y(value), y2: y(value) }));
      const label = svg('text', { class: 'an-axis', x: m.left - 8, y: y(value) + 4, 'text-anchor': 'end' });
      label.textContent = compact(value);
      root.append(label);
    }
    const labelEvery = Math.max(1, Math.ceil(n / (narrow ? 4 : 7)));
    daily.forEach((d, i) => {
      if (i % labelEvery !== 0 && i !== n - 1) return;
      if (i !== n - 1 && x(n - 1) - x(i) < (narrow ? 64 : 48)) return; // keep the last label clear
      // The first and last dates align inwards so they never run off the edge.
      const anchor = narrow && i === n - 1 ? 'end' : narrow && i === 0 ? 'start' : 'middle';
      const label = svg('text', { class: 'an-axis', x: x(i), y: H - 8, 'text-anchor': anchor });
      label.textContent = day(d.date);
      root.append(label);
    });

    for (const s of SERIES) {
      const d = daily.map((point, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(point[s.key]).toFixed(1)}`).join(' ');
      root.append(svg('path', { class: 'an-line', d, stroke: s.color }));
    }

    // Direct labels at the line ends (value + name, in ink with a color key);
    // skipped when the two ends would collide — legend and tooltip carry it then.
    const last = daily[n - 1];
    const ends = SERIES.map((s) => ({ s, y: y(last[s.key]) }));
    if (!narrow && Math.abs(ends[0].y - ends[1].y) >= 16) {
      for (const { s, y: ey } of ends) {
        root.append(svg('line', { x1: m.left + plotW + 6, x2: m.left + plotW + 16, y1: ey, y2: ey, stroke: s.color, 'stroke-width': 2, 'stroke-linecap': 'round' }));
        const text = svg('text', { class: 'an-end', x: m.left + plotW + 20, y: ey + 4 });
        text.textContent = `${number(last[s.key])} ${s.key === 'visits' ? 'kunjungan' : 'tayangan'}`;
        root.append(text);
      }
    }

    // Crosshair + tooltip, by pointer and by keyboard.
    const cross = svg('line', { class: 'an-cross', y1: m.top, y2: m.top + plotH, visibility: 'hidden' });
    const dots = SERIES.map((s) => svg('circle', { class: 'an-dot', r: 5, fill: s.color, visibility: 'hidden' }));
    const hit = svg('rect', { class: 'an-hit', x: m.left, y: m.top, width: plotW, height: plotH, tabindex: 0, 'aria-label': 'Grafik. Gunakan panah kiri dan kanan untuk berpindah hari.' });
    root.append(cross, ...dots, hit);

    const tooltip = el('div', 'an-tooltip');
    tooltip.hidden = true;
    let current = n - 1;
    const show = (i) => {
      current = Math.max(0, Math.min(n - 1, i));
      const point = daily[current];
      cross.setAttribute('x1', x(current));
      cross.setAttribute('x2', x(current));
      cross.setAttribute('visibility', 'visible');
      SERIES.forEach((s, k) => {
        dots[k].setAttribute('cx', x(current));
        dots[k].setAttribute('cy', y(point[s.key]));
        dots[k].setAttribute('visibility', 'visible');
      });
      tooltip.replaceChildren(el('strong', null, day(point.date, true)));
      for (const s of SERIES) {
        const row = el('div');
        const key = el('span', 'key');
        key.style.background = s.color;
        row.append(key, `${s.label}: ${number(point[s.key])}`);
        tooltip.append(row);
      }
      tooltip.hidden = false;
      const box = chartHost.getBoundingClientRect();
      const scale = box.width / W;
      const left = x(current) * scale;
      tooltip.style.left = `${Math.min(Math.max(left + 12, 0), box.width - tooltip.offsetWidth - 4)}px`;
      if (left + 12 + tooltip.offsetWidth > box.width) tooltip.style.left = `${Math.max(left - tooltip.offsetWidth - 12, 0)}px`;
      tooltip.style.top = `${m.top * scale}px`;
    };
    const hide = () => {
      cross.setAttribute('visibility', 'hidden');
      dots.forEach((dot) => dot.setAttribute('visibility', 'hidden'));
      tooltip.hidden = true;
    };
    hit.addEventListener('pointermove', (event) => {
      const box = hit.getBoundingClientRect();
      const ratio = (event.clientX - box.left) / box.width;
      show(Math.round(ratio * (n - 1)));
    });
    hit.addEventListener('pointerleave', () => {
      if (document.activeElement !== hit) hide();
    });
    hit.addEventListener('focus', () => show(current));
    hit.addEventListener('blur', hide);
    hit.addEventListener('keydown', (event) => {
      const moves = { ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: n - 1 };
      if (event.key in moves) {
        event.preventDefault();
        show(moves[event.key]);
      }
    });

    chartHost.replaceChildren(root, tooltip);
  };

  const renderTable = (daily) => {
    const table = el('table', 'an-table');
    const head = el('tr');
    head.append(el('th', null, 'Tanggal'), el('th', 'num', 'Tayangan halaman'), el('th', 'num', 'Kunjungan'));
    const thead = el('thead');
    thead.append(head);
    const tbody = el('tbody');
    for (const d of [...daily].reverse()) {
      const row = el('tr');
      row.append(el('td', null, day(d.date, true)), el('td', 'num', number(d.page_views)), el('td', 'num', number(d.visits)));
      tbody.append(row);
    }
    table.append(thead, tbody);
    tableHost.replaceChildren(table);
  };

  // ---- Lists ----
  const pageLabel = (page) => {
    const kind = PAGE_LABELS[page.kind] || page.kind;
    if (page.title) return `${kind}: ${page.title}`;
    if ((page.kind === 'post' || page.kind === 'product') && page.id) return `${kind} ${page.id} (sudah dihapus?)`;
    return kind;
  };
  const renderTopPages = (pages) => {
    if (pages.length === 0) {
      topPagesHost.replaceChildren(el('p', 'muted', 'Belum ada kunjungan pada rentang ini.'));
      return;
    }
    const table = el('table', 'an-table');
    const head = el('tr');
    head.append(el('th', null, 'Halaman'), el('th', 'num', 'Tayangan'), el('th', 'num', 'Kunjungan'));
    const thead = el('thead');
    thead.append(head);
    const tbody = el('tbody');
    for (const page of pages) {
      const row = el('tr');
      const cell = el('td');
      if (page.path) {
        const link = el('a', null, pageLabel(page));
        link.href = page.path;
        link.target = '_blank';
        link.rel = 'noopener';
        cell.append(link);
      } else {
        cell.textContent = pageLabel(page);
      }
      row.append(cell, el('td', 'num', number(page.page_views)), el('td', 'num', number(page.visits)));
      tbody.append(row);
    }
    table.append(thead, tbody);
    topPagesHost.replaceChildren(table);
  };
  const renderList = (host, items, empty, render) => {
    host.replaceChildren(...(items.length === 0 ? [el('li', 'muted', empty)] : items.map(render)));
  };

  // Re-draw at the new width when the window is resized.
  let lastDaily = null;
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => { if (lastDaily) renderChart(lastDaily.daily, lastDaily.days); }, 150);
  });

  // ---- Loading ----
  let days = 30;
  try {
    const saved = Number(localStorage.getItem('fpdp_analytics_days'));
    if ([7, 30, 90].includes(saved)) days = saved;
  } catch (_) { /* storage blocked: keep the default */ }

  const load = async () => {
    rangeButtons.forEach((button) => button.setAttribute('aria-pressed', String(Number(button.dataset.days) === days)));
    try {
      const { data } = await api(`/api/v1/me/analytics?days=${days}`);
      renderKpis(data);
      lastDaily = { daily: data.daily, days: data.window_days };
      renderChart(data.daily, data.window_days);
      renderTable(data.daily);
      renderTopPages(data.top_pages);
      renderList(referrersList, data.referrers, 'Belum ada — kunjungan langsung dan dari dalam situs ini tidak dihitung di sini.', (r) => {
        const li = el('li');
        li.append(el('span', null, r.host), el('span', 'count', `${number(r.page_views)} tayangan`));
        return li;
      });
      renderList(outboundList, data.outbound, 'Belum ada link keluar yang diklik.', (o) => {
        const li = el('li');
        const link = el('a', null, o.url.replace(/^https?:\/\//, ''));
        link.href = o.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.title = o.url;
        const label = el('span');
        label.append(link);
        li.append(label, el('span', 'count', `${number(o.clicks)} klik`));
        return li;
      });
    } catch (error) {
      kpiRow.replaceChildren(el('p', 'muted', error.message));
    }
  };

  rangeButtons.forEach((button) => button.addEventListener('click', () => {
    days = Number(button.dataset.days);
    try { localStorage.setItem('fpdp_analytics_days', String(days)); } catch (_) { /* ignore */ }
    load();
  }));

  // ---- Auth (same pattern as the other dashboard pages) ----
  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk melihat analytics.';
      loginForm.classList.remove('hidden');
      content.classList.add('hidden');
      document.querySelectorAll('.owner-nav').forEach((node) => node.classList.add('hidden'));
      document.querySelectorAll('.guest-nav').forEach((node) => node.classList.remove('hidden'));
      return;
    }
    try {
      const result = await api('/api/v1/me');
      authSummary.textContent = `Masuk sebagai ${result.data.user.email}.`;
      loginForm.classList.add('hidden');
      content.classList.remove('hidden');
      document.querySelectorAll('.owner-nav').forEach((node) => node.classList.remove('hidden'));
      document.querySelectorAll('.guest-nav').forEach((node) => node.classList.add('hidden'));
      await load();
    } catch (_) {
      sessionStorage.removeItem(tokenKey);
      verify();
    }
  };
  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const result = await api('/api/v1/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email: loginForm.elements.email.value, password: loginForm.elements.password.value }),
      });
      sessionStorage.setItem(tokenKey, result.data.token.access_token);
      verify();
    } catch (error) {
      authSummary.textContent = error.message;
    }
  });

  verify();
})();
