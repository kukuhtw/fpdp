(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#orders-content');
  const orderList = document.querySelector('#order-list');
  const loadMoreButton = document.querySelector('#load-more');
  const statusFilter = document.querySelector('#status-filter');
  const STATUSES = ['PENDING', 'CONFIRMED', 'PROCESSING', 'COMPLETED', 'CANCELLED', 'REFUNDED'];
  let nextCursor = null;

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

  const statusBadgeClass = (orderStatus) => (orderStatus === 'COMPLETED' ? 'badge-green' : orderStatus === 'CANCELLED' || orderStatus === 'REFUNDED' ? 'error-badge' : 'badge-yellow');

  const orderCard = (order) => {
    const card = document.createElement('article');
    card.className = 'gateway-card';

    const itemsText = (order.items || []).map((item) => {
      const snapshot = JSON.parse(item.product_snapshot || '{}');
      return `${item.quantity}x ${escapeHtml(snapshot.title || 'Produk')}`;
    }).join(', ');

    const head = document.createElement('div');
    head.className = 'gateway-card-head';
    head.innerHTML = `<strong>${escapeHtml(order.buyer_name || order.buyer_email || 'Pembeli')}</strong> <span class="muted">${order.currency} ${Number(order.total_amount).toLocaleString('id-ID')}</span> <span class="post-list-meta badge ${statusBadgeClass(order.status)}">${escapeHtml(order.status)}</span>`;

    const meta = document.createElement('p');
    meta.className = 'muted';
    meta.textContent = `${itemsText} · ${new Date(order.created_at).toLocaleString('id-ID')}${order.buyer_email ? ' · ' + order.buyer_email : ''}`;

    const parts = [head, meta];
    if (order.shipping_address) {
      const address = document.createElement('p');
      address.innerHTML = `<strong>Alamat kirim:</strong> ${escapeHtml(order.shipping_address).replace(/\n/g, '<br>')}`;
      parts.push(address);
    }
    if (order.notes) {
      const notes = document.createElement('p');
      notes.innerHTML = `<strong>Catatan:</strong> ${escapeHtml(order.notes)}`;
      parts.push(notes);
    }

    const controls = document.createElement('div');
    controls.className = 'field-row';
    const select = document.createElement('select');
    STATUSES.forEach((s) => {
      const option = document.createElement('option');
      option.value = s;
      option.textContent = s;
      option.selected = s === order.status;
      select.append(option);
    });
    const updateButton = document.createElement('button');
    updateButton.type = 'button';
    updateButton.className = 'secondary';
    updateButton.textContent = 'Ubah status';
    updateButton.addEventListener('click', async () => {
      try {
        await api(`/api/v1/orders/${encodeURIComponent(order.public_id)}/status`, { method: 'PATCH', body: JSON.stringify({ status: select.value }) });
        head.querySelector('.badge').className = `post-list-meta badge ${statusBadgeClass(select.value)}`;
        head.querySelector('.badge').textContent = select.value;
      } catch (error) {
        alert(error.message);
      }
    });
    controls.append(select, updateButton);

    card.append(...parts, controls);
    return card;
  };

  const renderOrders = (orders, append) => {
    if (!append) orderList.replaceChildren();
    if (orders.length === 0 && !append) {
      orderList.innerHTML = '<p class="muted">Belum ada pesanan.</p>';
      return;
    }
    orders.forEach((order) => orderList.append(orderCard(order)));
  };

  const loadOrders = async (append = false) => {
    try {
      const params = new URLSearchParams();
      if (append && nextCursor) params.set('cursor', nextCursor);
      if (statusFilter.value) params.set('status', statusFilter.value);
      const query = params.toString();
      const result = await api(`/api/v1/orders${query ? `?${query}` : ''}`);
      renderOrders(result.data, append);
      nextCursor = result.meta.next_cursor;
      loadMoreButton.classList.toggle('hidden', !result.meta.has_more);
    } catch (error) {
      orderList.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };
  loadMoreButton.addEventListener('click', () => loadOrders(true));
  statusFilter.addEventListener('change', () => { nextCursor = null; loadOrders(false); });

  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk melihat pesanan.';
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
      nextCursor = null;
      await loadOrders(false);
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
