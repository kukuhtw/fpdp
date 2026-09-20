(() => {
  const tokenKey = 'fpdp_access_token';
  const svgNS = 'http://www.w3.org/2000/svg';

  const loginForm = document.querySelector('#login-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const status = document.querySelector('#dashboard-status');
  const content = document.querySelector('#dashboard-content');
  const kpiRow = document.querySelector('#kpi-row');
  const chartLegend = document.querySelector('#chart-legend');
  const chartHost = document.querySelector('#traffic-chart');
  const topContentList = document.querySelector('#top-content');
  const activityList = document.querySelector('#recent-activity');

  const token = () => sessionStorage.getItem(tokenKey);
  const showStatus = (message, error = false) => {
    status.textContent = message;
    status.className = `status ${error ? 'error' : 'success'}`;
  };
  const setAuthenticated = (authenticated, label = '') => {
    loginForm.classList.toggle('hidden', authenticated);
    logoutButton.classList.toggle('hidden', !authenticated);
    content.classList.toggle('hidden', !authenticated);
    authSummary.textContent = authenticated ? `Signed in${label ? ` as ${label}` : ''}.` : 'Sign in to view your dashboard.';
  };
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };

  const compactNumber = (value) => {
    const n = Number(value) || 0;
    if (Math.abs(n) >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
    if (Math.abs(n) >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
    return n.toLocaleString();
  };
  const currency = (value, currencyCode) => {
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode, maximumFractionDigits: 0 }).format(Number(value) || 0);
    } catch (_) {
      return `${currencyCode} ${compactNumber(value)}`;
    }
  };
  const weekday = (isoDate) => new Date(`${isoDate}T00:00:00Z`).toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' });
  const dateLabel = (isoDate) => new Date(`${isoDate}T00:00:00Z`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', timeZone: 'UTC' });

  const kpiTile = (label, value, note) => {
    const tile = document.createElement('div');
    tile.className = 'kpi-tile';
    const labelEl = document.createElement('p');
    labelEl.className = 'kpi-label';
    labelEl.textContent = label;
    const valueEl = document.createElement('p');
    valueEl.className = 'kpi-value';
    valueEl.textContent = value;
    tile.append(labelEl, valueEl);
    if (note) {
      const noteEl = document.createElement('p');
      noteEl.className = 'kpi-note';
      noteEl.textContent = note;
      tile.append(noteEl);
    }
    return tile;
  };

  const renderKpis = (data) => {
    kpiRow.replaceChildren(
      kpiTile('Published posts', compactNumber(data.content.published), `${compactNumber(data.content.draft)} draft`),
      kpiTile('Products', compactNumber(data.commerce.product_count)),
      kpiTile('Pending orders', compactNumber(data.commerce.pending_order_count), `${compactNumber(data.commerce.order_count)} total`),
      kpiTile('Revenue this month', currency(data.commerce.revenue_this_month, data.commerce.currency)),
      kpiTile('Followers', compactNumber(data.federation.follower_count), `${compactNumber(data.federation.following_count)} following`),
      kpiTile('Unique visitors', compactNumber(data.analytics.unique_visitors), `last ${data.analytics.window_days} days`),
    );
  };

  const svgEl = (tag, attrs) => {
    const el = document.createElementNS(svgNS, tag);
    for (const [key, value] of Object.entries(attrs)) el.setAttribute(key, value);
    return el;
  };

  const barPath = (x, width, baseline, top, radius) => {
    const height = baseline - top;
    const r = Math.max(0, Math.min(radius, height, width / 2));
    if (height <= 0) return `M${x},${baseline} L${x + width},${baseline}`;
    return [
      `M${x},${baseline}`,
      `L${x},${top + r}`,
      `Q${x},${top} ${x + r},${top}`,
      `L${x + width - r},${top}`,
      `Q${x + width},${top} ${x + width},${top + r}`,
      `L${x + width},${baseline}`,
      'Z',
    ].join(' ');
  };

  const renderChart = (dailyTraffic) => {
    chartLegend.replaceChildren();
    const legendItems = [
      ['Views', 'var(--series-views)'],
      ['Unique visitors', 'var(--series-visitors)'],
    ];
    for (const [label, color] of legendItems) {
      const item = document.createElement('span');
      const swatch = document.createElement('span');
      swatch.className = 'swatch';
      swatch.style.background = color;
      item.append(swatch, document.createTextNode(label));
      chartLegend.append(item);
    }

    const width = 420;
    const height = 150;
    const baseline = 110;
    const top = 12;
    const groupWidth = width / Math.max(dailyTraffic.length, 1);
    const barWidth = 13;
    const gap = 3;
    const maxValue = Math.max(1, ...dailyTraffic.map((d) => Math.max(d.views, d.unique_visitors)));

    const svg = svgEl('svg', { viewBox: `0 0 ${width} ${height}`, role: 'img', 'aria-label': 'Daily views and unique visitors, last 7 days', style: 'width:100%;height:auto;' });
    svg.append(svgEl('line', { x1: 0, y1: baseline, x2: width, y2: baseline, stroke: 'var(--line)', 'stroke-width': 1 }));

    dailyTraffic.forEach((day, index) => {
      const groupX = index * groupWidth + (groupWidth - (barWidth * 2 + gap)) / 2;
      const viewsHeight = ((baseline - top) * day.views) / maxValue;
      const visitorsHeight = ((baseline - top) * day.unique_visitors) / maxValue;

      const hit = svgEl('rect', {
        class: 'chart-hit', x: index * groupWidth, y: top, width: groupWidth, height: baseline - top, tabindex: '0',
      });
      const title = svgEl('title', {});
      title.textContent = `${dateLabel(day.date)}: ${day.views.toLocaleString()} views, ${day.unique_visitors.toLocaleString()} unique visitors`;
      hit.append(title);
      svg.append(hit);

      const viewsBar = svgEl('path', {
        class: 'chart-bar', d: barPath(groupX, barWidth, baseline, baseline - viewsHeight, 4), fill: 'var(--series-views)',
        'aria-label': `${dateLabel(day.date)} views: ${day.views}`,
      });
      const visitorsBar = svgEl('path', {
        class: 'chart-bar', d: barPath(groupX + barWidth + gap, barWidth, baseline, baseline - visitorsHeight, 4), fill: 'var(--series-visitors)',
        'aria-label': `${dateLabel(day.date)} unique visitors: ${day.unique_visitors}`,
      });
      svg.append(viewsBar, visitorsBar);

      const dayLabel = svgEl('text', { class: 'chart-day', x: index * groupWidth + groupWidth / 2, y: baseline + 16, 'text-anchor': 'middle' });
      dayLabel.textContent = weekday(day.date);
      svg.append(dayLabel);
    });

    chartHost.replaceChildren(svg);
  };

  const renderTopContent = (topContent) => {
    topContentList.replaceChildren();
    if (topContent.length === 0) {
      const empty = document.createElement('li');
      empty.className = 'empty-row';
      empty.textContent = 'No post views yet.';
      topContentList.append(empty);
      return;
    }
    for (const item of topContent) {
      const row = document.createElement('li');
      const link = document.createElement('a');
      link.href = `/posts/${encodeURIComponent(item.subject_public_id)}`;
      link.textContent = item.subject_public_id;
      const count = document.createElement('span');
      count.className = 'count';
      count.textContent = `${item.views.toLocaleString()} views`;
      row.append(link, count);
      topContentList.append(row);
    }
  };

  const renderActivity = (recentActivity) => {
    activityList.replaceChildren();
    if (recentActivity.length === 0) {
      const empty = document.createElement('li');
      empty.className = 'empty-row';
      empty.textContent = 'No recent activity.';
      activityList.append(empty);
      return;
    }
    for (const event of recentActivity) {
      const row = document.createElement('li');
      const label = document.createElement('span');
      label.textContent = `${event.action} ${event.subject_type ? `(${event.subject_type})` : ''}`.trim();
      const time = document.createElement('time');
      time.dateTime = event.occurred_at;
      time.textContent = new Date(event.occurred_at).toLocaleString();
      row.append(label, time);
      activityList.append(row);
    }
  };

  const loadOverview = async () => {
    try {
      const result = await api('/api/v1/me/dashboard/overview');
      const data = result.data;
      document.querySelector('#node-domain').textContent = data.node.domain || '';
      document.querySelector('#node-name').textContent = data.node.name || 'This node';
      document.querySelector('#node-status-badge').textContent = data.node.status || '';
      renderKpis(data);
      renderChart(data.analytics.daily_traffic);
      renderTopContent(data.analytics.top_content);
      renderActivity(data.recent_activity);
    } catch (error) {
      showStatus(error.message, true);
    }
  };

  const verifySession = async () => {
    if (!token()) return setAuthenticated(false);
    try {
      const result = await api('/api/v1/me');
      setAuthenticated(true, result.data.profile.handle);
      await loadOverview();
    } catch (_) {
      sessionStorage.removeItem(tokenKey);
      setAuthenticated(false);
    }
  };

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = new FormData(loginForm);
    try {
      const result = await api('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: fields.get('email'), password: fields.get('password') }) });
      sessionStorage.setItem(tokenKey, result.data.token.access_token);
      loginForm.reset();
      await verifySession();
      showStatus('Signed in successfully.');
    } catch (error) { showStatus(error.message, true); }
  });
  logoutButton.addEventListener('click', async () => {
    try { await api('/api/v1/auth/logout', { method: 'POST' }); } catch (_) {}
    sessionStorage.removeItem(tokenKey);
    setAuthenticated(false);
    showStatus('Signed out.');
  });

  verifySession();
})();
