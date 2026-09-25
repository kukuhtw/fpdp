(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#payments-content');
  const kpiRow = document.querySelector('#kpi-row');
  const pendingList = document.querySelector('#pending-list');
  const recentList = document.querySelector('#recent-list');

  const purposeLabels = {
    cv_access: 'Akses CV/Resume',
    marketplace_order: 'Pesanan produk',
    wallet_topup: 'Top up saldo chatbot',
  };

  const token = () => sessionStorage.getItem(tokenKey);
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };

  const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  const money = (amount, currency) => `${currency || 'IDR'} ${Number(amount).toLocaleString('id-ID')}`;

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

  const loadSummary = async () => {
    try {
      const result = await api('/api/v1/me/dashboard/payments');
      const s = result.data;
      kpiRow.replaceChildren(
        kpiTile('Saldo tersedia', money(s.available_balance, s.currency)),
        kpiTile('Menunggu settlement', money(s.pending_settlement, s.currency)),
        kpiTile('Pembayaran lunas', String(s.paid_count)),
        kpiTile('Tingkat sukses', `${s.success_rate}%`),
        kpiTile('Pendapatan bulan ini', money(s.revenue_this_month, s.currency)),
      );
      renderRecent(s.recent_transactions || []);
    } catch (error) {
      kpiRow.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };

  const renderRecent = (payments) => {
    recentList.replaceChildren();
    if (payments.length === 0) {
      recentList.innerHTML = '<p class="muted">Belum ada transaksi.</p>';
      return;
    }
    const list = document.createElement('ul');
    list.className = 'content-list';
    payments.forEach((p) => {
      const li = document.createElement('li');
      const when = p.created_at ? new Date(p.created_at.replace(' ', 'T') + 'Z').toLocaleString('id-ID') : '';
      li.innerHTML = `<span>${escapeHtml(p.gateway_code)} · ${escapeHtml(p.status)}<br><small class="muted">${escapeHtml(p.order_id)} · ${when}</small></span><span class="count">${money(p.amount, p.currency)}</span>`;
      list.append(li);
    });
    recentList.append(list);
  };

  const confirmPayment = async (uuid, button) => {
    if (!confirm('Tandai pembayaran ini sebagai LUNAS? Tindakan ini akan langsung memberikan akses/menyelesaikan pesanan terkait.')) return;
    button.disabled = true;
    button.textContent = 'Memproses…';
    try {
      await api(`/api/v1/me/payments/${encodeURIComponent(uuid)}/confirm`, { method: 'POST' });
      await Promise.all([loadPending(), loadSummary()]);
    } catch (error) {
      alert(error.message);
      button.disabled = false;
      button.textContent = 'Konfirmasi Lunas';
    }
  };

  const pendingCard = (payment) => {
    const card = document.createElement('article');
    card.className = 'gateway-card';
    const when = payment.created_at ? new Date(payment.created_at.replace(' ', 'T') + 'Z').toLocaleString('id-ID') : '';
    const head = document.createElement('div');
    head.className = 'gateway-card-head';
    head.innerHTML = `<strong>${purposeLabels[payment.purpose] || 'Pembayaran'}</strong> <span class="muted">${escapeHtml(payment.gateway_code)}</span>`;
    const meta = document.createElement('p');
    meta.className = 'muted';
    meta.textContent = `${payment.order_id} · dibuat ${when}`;
    const amount = document.createElement('p');
    amount.innerHTML = `<strong>${money(payment.amount, payment.currency)}</strong>`;
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = 'Konfirmasi Lunas';
    button.addEventListener('click', () => confirmPayment(payment.uuid, button));
    card.append(head, meta, amount, button);
    return card;
  };

  const loadPending = async () => {
    try {
      const result = await api('/api/v1/me/payments/pending');
      pendingList.replaceChildren();
      if (result.data.length === 0) {
        pendingList.innerHTML = '<p class="muted">Tidak ada pembayaran yang menunggu konfirmasi.</p>';
        return;
      }
      result.data.forEach((payment) => pendingList.append(pendingCard(payment)));
    } catch (error) {
      pendingList.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };

  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk melihat pembayaran.';
      loginForm.classList.remove('hidden');
      content.classList.add('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.add('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.remove('hidden'));
      return;
    }
    try {
      const result = await api('/api/v1/me');
      authSummary.textContent = `Masuk sebagai ${result.data.user.email}.`;
      loginForm.classList.add('hidden');
      content.classList.remove('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.add('hidden'));
      await Promise.all([loadPending(), loadSummary()]);
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
