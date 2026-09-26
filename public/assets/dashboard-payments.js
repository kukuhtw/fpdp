(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#payments-content');
  const kpiRow = document.querySelector('#kpi-row');
  const pendingList = document.querySelector('#pending-list');
  const recentList = document.querySelector('#recent-list');
  const reconcileButton = document.querySelector('#reconcile-button');
  const reconcileResult = document.querySelector('#reconcile-result');

  const purposeLabels = {
    cv_access: 'Akses CV/Resume',
    marketplace_order: 'Pesanan produk',
    wallet_topup: 'Top up saldo chatbot',
  };

  const statusLabels = {
    PENDING: 'Menunggu',
    PAID: 'Lunas',
    PARTIALLY_REFUNDED: 'Refund sebagian',
    REFUNDED: 'Refund penuh',
    FAILED: 'Gagal',
    CANCELLED: 'Dibatalkan',
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
        kpiTile('Total refund', money(s.refunded_amount || 0, s.currency), `${s.refunded_count || 0} transaksi`),
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
      const refunded = Number(p.refunded_amount || 0);
      const refundedNote = refunded > 0 ? ` · direfund ${money(refunded, p.currency)}` : '';
      li.innerHTML = `<span>${escapeHtml(p.gateway_code)} · ${escapeHtml(statusLabels[p.status] || p.status)}<br><small class="muted">${escapeHtml(p.order_id)} · ${when}${refundedNote}</small></span><span class="count">${money(p.amount, p.currency)}</span>`;
      if (p.status === 'PAID' || p.status === 'PARTIALLY_REFUNDED') {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Refund';
        button.addEventListener('click', () => refundPayment(p, button));
        li.append(button);
      }
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

  const refundPayment = async (payment, button) => {
    const remaining = Number(payment.amount) - Number(payment.refunded_amount || 0);
    const input = prompt(`Jumlah refund (maksimal ${money(remaining, payment.currency)}). Kosongkan untuk refund penuh.`, '');
    if (input === null) return;
    const trimmed = input.trim().replace(/[.,\s]/g, '');
    const amount = trimmed === '' ? null : Number(trimmed);
    if (amount !== null && (!Number.isFinite(amount) || amount <= 0)) {
      alert('Jumlah refund tidak valid.');
      return;
    }
    const body = amount === null ? {} : { amount };
    button.disabled = true;
    button.textContent = 'Memproses…';
    try {
      let result;
      try {
        result = await api(`/api/v1/me/payments/${encodeURIComponent(payment.uuid)}/refund`, { method: 'POST', body: JSON.stringify(body) });
      } catch (error) {
        // Gateways without a refund API: offer to record a refund the owner
        // already made outside the gateway.
        if (!confirm(`${error.message}\n\nSudah mengembalikan dananya sendiri? Tekan OK untuk mencatatnya sebagai refund manual.`)) throw null;
        result = await api(`/api/v1/me/payments/${encodeURIComponent(payment.uuid)}/refund`, { method: 'POST', body: JSON.stringify({ ...body, manual: true }) });
      }
      const notes = result.data.notes || [];
      if (notes.length > 0) alert(notes.join('\n'));
      await loadSummary();
    } catch (error) {
      if (error) alert(error.message);
      button.disabled = false;
      button.textContent = 'Refund';
    }
  };

  const cancelPayment = async (uuid, button) => {
    if (!confirm('Batalkan pembayaran ini? Pesanan terkait juga akan dibatalkan.')) return;
    button.disabled = true;
    button.textContent = 'Memproses…';
    try {
      const result = await api(`/api/v1/me/payments/${encodeURIComponent(uuid)}/cancel`, { method: 'POST' });
      if (!result.data.provider_cancelled && result.data.provider_message) {
        alert(`Dibatalkan di FPDP, tetapi gateway menolak pembatalan: ${result.data.provider_message}\nHalaman bayar di gateway mungkin masih terbuka sampai kedaluwarsa.`);
      }
      await Promise.all([loadPending(), loadSummary()]);
    } catch (error) {
      alert(error.message);
      button.disabled = false;
      button.textContent = 'Batalkan';
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
    const parts = [head, meta, amount];
    // Lets the owner match an incoming bank transfer (no automatic
    // confirmation for gateways like Manual Transfer) to the right visitor.
    if (payment.buyer_name || payment.buyer_phone || payment.buyer_email) {
      const buyer = document.createElement('p');
      const bits = [];
      if (payment.buyer_name) bits.push(`<strong>Nama:</strong> ${escapeHtml(payment.buyer_name)}`);
      if (payment.buyer_phone) bits.push(`<strong>Telepon:</strong> ${escapeHtml(payment.buyer_phone)}`);
      if (payment.buyer_email) bits.push(`<strong>Email:</strong> ${escapeHtml(payment.buyer_email)}`);
      buyer.innerHTML = bits.join(' · ');
      parts.push(buyer);
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = 'Konfirmasi Lunas';
    button.addEventListener('click', () => confirmPayment(payment.uuid, button));
    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'secondary';
    cancelButton.textContent = 'Batalkan';
    cancelButton.addEventListener('click', () => cancelPayment(payment.uuid, cancelButton));
    const actions = document.createElement('p');
    actions.append(button, ' ', cancelButton);
    card.append(...parts, actions);
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

  reconcileButton.addEventListener('click', async () => {
    reconcileButton.disabled = true;
    reconcileResult.textContent = 'Memeriksa…';
    try {
      const r = (await api('/api/v1/me/payments/reconcile', { method: 'POST' })).data;
      const parts = [`${r.checked} dicek`, `${r.updated.length} diperbarui`];
      if (r.errors.length > 0) parts.push(`${r.errors.length} gagal dicek`);
      if (r.mismatches.length > 0) parts.push(`${r.mismatches.length} ternyata sudah dibayar setelah dibatalkan (kini LUNAS; refund bila tidak diinginkan)`);
      reconcileResult.textContent = parts.join(' · ');
      await Promise.all([loadPending(), loadSummary()]);
    } catch (error) {
      reconcileResult.textContent = error.message;
    } finally {
      reconcileButton.disabled = false;
    }
  });

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
