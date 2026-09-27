// Belanja: order products from other FPDP nodes the owner follows, from the
// dashboard (node-to-node orders, FEDERATION-CONCEPT §11b), and follow the
// status of those orders. Remote data is always inserted as text, never HTML.
(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#purchases-content');
  const purchaseList = document.querySelector('#purchase-list');
  const shopList = document.querySelector('#shop-list');
  const statusLine = document.querySelector('#purchase-status');
  const refreshAll = document.querySelector('#refresh-all');
  const dialog = document.querySelector('#order-dialog');
  const orderForm = document.querySelector('#order-form');
  const orderError = orderForm.querySelector('.mfa-dialog-error');
  let ownerEmail = '';
  let current = null;

  const STATUS_LABELS = {
    SUBMITTING: 'Belum terkirim',
    PENDING: 'Menunggu pembayaran',
    CONFIRMED: 'Dibayar',
    PROCESSING: 'Diproses penjual',
    COMPLETED: 'Selesai',
    CANCELLED: 'Dibatalkan',
    REFUNDED: 'Dikembalikan',
  };

  const token = () => sessionStorage.getItem(tokenKey);
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Permintaan gagal (${response.status})`);
    return payload;
  };
  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const money = (currency, amount) => `${currency} ${Number(amount).toLocaleString('id-ID')}`;
  const safeLink = (url, text) => {
    const a = el('a', '', text);
    if (/^https:\/\//i.test(url || '')) a.href = url;
    a.rel = 'noopener noreferrer';
    a.target = '_blank';
    return a;
  };
  const setStatus = (text, isError = false) => {
    statusLine.textContent = text;
    statusLine.className = `status ${text ? (isError ? 'error' : 'success') : ''}`;
  };
  const badgeClass = (status) => (status === 'COMPLETED' || status === 'CONFIRMED' ? 'badge-green' : ['CANCELLED', 'REFUNDED', 'SUBMITTING'].includes(status) ? 'error-badge' : 'badge-yellow');

  const purchaseCard = (purchase) => {
    const card = el('article', 'gateway-card');
    const head = el('div', 'gateway-card-head');
    head.append(el('strong', '', purchase.seller_domain), ' ', el('span', 'muted', money(purchase.currency, purchase.total_amount)), ' ');
    head.append(el('span', `post-list-meta badge ${badgeClass(purchase.status)}`, STATUS_LABELS[purchase.status] || purchase.status));
    card.append(head);
    card.append(el('p', 'muted', `${(purchase.items || []).map((item) => `${item.quantity}× ${item.title || 'Produk'}`).join(', ')} · ${new Date(purchase.created_at.replace(' ', 'T')).toLocaleString('id-ID')}`));

    if (purchase.payment_url) {
      const pay = safeLink(purchase.payment_url, 'Bayar di halaman penjual ↗');
      pay.className = 'button';
      const payLine = el('p');
      payLine.append(pay);
      card.append(payLine);
    } else if (purchase.status === 'PENDING') {
      card.append(el('p', 'muted', 'Menunggu pembayaran. Ikuti instruksi dari penjual, lalu perbarui status.'));
    }
    if ((purchase.downloads || []).length > 0) {
      const list = el('ul');
      purchase.downloads.forEach((download) => {
        const li = el('li');
        li.append(safeLink(download.url, `${download.title}${download.label ? ` — ${download.label}` : ''}`));
        if (download.expires_at) li.append(el('span', 'muted', ' (link berlaku 15 menit; perbarui untuk link baru)'));
        list.append(li);
      });
      card.append(el('p', '', 'Unduhan:'), list);
    }
    if (purchase.shipping_address) card.append(el('p', 'muted', `Dikirim ke: ${purchase.shipping_address}`));
    if (purchase.last_error) card.append(el('p', 'status error', purchase.last_error));

    const refresh = el('button', 'secondary', purchase.status === 'SUBMITTING' ? 'Kirim ulang' : 'Perbarui');
    refresh.type = 'button';
    refresh.addEventListener('click', async () => {
      refresh.disabled = true;
      try {
        const { data } = await api(`/api/v1/me/purchases/${encodeURIComponent(purchase.id)}/refresh`, { method: 'POST' });
        card.replaceWith(purchaseCard(data));
      } catch (error) {
        setStatus(error.message, true);
        refresh.disabled = false;
      }
    });
    card.append(refresh);
    return card;
  };

  const productCard = (product) => {
    const card = el('article', 'product-card');
    if (product.image_url && /^https:\/\//i.test(product.image_url)) {
      const img = el('img', 'product-photo');
      img.src = product.image_url;
      img.alt = '';
      img.loading = 'lazy';
      card.append(img);
    }
    card.append(el('h2', '', product.title || 'Produk'));
    card.append(el('p', 'muted', `${product.seller.name || product.seller.address} · ${product.seller.domain}`));
    if (product.excerpt) card.append(el('p', '', product.excerpt));
    card.append(el('p', '', money(product.currency, product.price)));
    if (product.orderable) {
      const button = el('button', '', 'Pesan');
      button.type = 'button';
      button.addEventListener('click', () => openOrder(product));
      card.append(button);
    } else {
      card.append(safeLink(product.checkout_url, `Beli di ${product.seller.domain} ↗`));
    }
    return card;
  };

  const openOrder = (product) => {
    current = product;
    orderForm.reset();
    orderForm.elements.buyer_email.value = ownerEmail;
    orderError.hidden = true;
    document.querySelector('#order-product').textContent = `${product.title} — ${money(product.currency, product.price)} dari ${product.seller.domain}`;
    const physical = product.product_type === 'PHYSICAL';
    document.querySelector('#address-field').classList.toggle('hidden', !physical);
    orderForm.elements.shipping_address.required = physical;
    document.querySelector('#order-privacy').textContent = `Nama, email${physical ? ', alamat' : ''}, dan catatan dikirim ke ${product.seller.domain} untuk memproses pesanan. Harga akhir ditentukan oleh penjual.`;
    dialog.showModal();
    orderForm.elements.quantity.focus();
  };

  document.querySelector('#order-cancel').addEventListener('click', () => dialog.close());

  orderForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = orderForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    orderError.hidden = true;
    try {
      const fields = orderForm.elements;
      const { data } = await api('/api/v1/me/purchases', {
        method: 'POST',
        body: JSON.stringify({
          post_id: current.post_id,
          quantity: Number(fields.quantity.value),
          buyer_name: fields.buyer_name.value,
          buyer_email: fields.buyer_email.value,
          shipping_address: fields.shipping_address.value,
          notes: fields.notes.value,
        }),
      });
      dialog.close();
      if (data.payment_url) {
        setStatus('Pesanan terkirim. Membuka halaman pembayaran penjual…');
        window.location.assign(data.payment_url);
        return;
      }
      setStatus(data.status === 'SUBMITTING' ? 'Node penjual belum bisa dihubungi; pesanan akan dikirim ulang otomatis.' : 'Pesanan terkirim.', data.status === 'SUBMITTING');
      await loadPurchases();
    } catch (error) {
      orderError.textContent = error.message;
      orderError.hidden = false;
      orderError.scrollIntoView({ block: 'nearest' });
    } finally {
      submit.disabled = false;
    }
  });

  const loadPurchases = async () => {
    try {
      const { data } = await api('/api/v1/me/purchases');
      purchaseList.replaceChildren(...(data.items.length ? data.items.map(purchaseCard) : [el('p', 'muted', 'Belum ada pesanan.')]));
    } catch (error) {
      purchaseList.replaceChildren(el('p', 'muted', error.message));
    }
  };
  const loadShop = async () => {
    try {
      const { data } = await api('/api/v1/me/fediverse-shop');
      shopList.replaceChildren(...(data.items.length ? data.items.map(productCard) : [el('p', 'muted', 'Belum ada produk dari akun yang Anda ikuti. Ikuti toko FPDP lain di halaman Federasi.')]));
    } catch (error) {
      shopList.replaceChildren(el('p', 'muted', error.message));
    }
  };

  refreshAll.addEventListener('click', async () => {
    refreshAll.disabled = true;
    const { data } = await api('/api/v1/me/purchases').catch(() => ({ data: { items: [] } }));
    const open = data.items.filter((p) => ['SUBMITTING', 'PENDING', 'CONFIRMED', 'PROCESSING'].includes(p.status));
    for (const purchase of open) {
      try { await api(`/api/v1/me/purchases/${encodeURIComponent(purchase.id)}/refresh`, { method: 'POST' }); } catch (_) { /* shown per card */ }
    }
    await loadPurchases();
    setStatus(open.length ? `${open.length} pesanan diperbarui.` : 'Tidak ada pesanan yang masih berjalan.');
    refreshAll.disabled = false;
  });

  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk berbelanja.';
      loginForm.classList.remove('hidden');
      content.classList.add('hidden');
      document.querySelectorAll('.owner-nav').forEach((node) => node.classList.add('hidden'));
      document.querySelectorAll('.guest-nav').forEach((node) => node.classList.remove('hidden'));
      return;
    }
    try {
      const result = await api('/api/v1/me');
      ownerEmail = result.data.user.email;
      authSummary.textContent = `Masuk sebagai ${ownerEmail}.`;
      loginForm.classList.add('hidden');
      content.classList.remove('hidden');
      document.querySelectorAll('.owner-nav').forEach((node) => node.classList.remove('hidden'));
      document.querySelectorAll('.guest-nav').forEach((node) => node.classList.add('hidden'));

      // Back from the seller's payment page: refresh that purchase first.
      const ref = new URLSearchParams(window.location.search).get('ref');
      if (ref) {
        try { await api(`/api/v1/me/purchases/${encodeURIComponent(ref)}/refresh`, { method: 'POST' }); } catch (_) { /* shown on the card */ }
        history.replaceState(null, '', window.location.pathname);
      }
      await Promise.all([loadPurchases(), loadShop()]);
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
