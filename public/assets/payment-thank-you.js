(() => {
  const tokenKey = 'fpdp_visitor_token';
  const heading = document.querySelector('#payment-heading');
  const message = document.querySelector('#payment-message');
  const details = document.querySelector('#payment-details');
  const actions = document.querySelector('#payment-actions');
  const status = document.querySelector('#payment-status');

  const INTERVAL_MS = 3000;
  const MAX_ATTEMPTS = 15; // ~45s of polling before falling back to a manual re-check

  const api = async (path, options = {}, binary = false) => {
    const headers = { ...(options.headers || {}) };
    const token = sessionStorage.getItem(tokenKey);
    if (token) headers.Authorization = `Bearer ${token}`;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    if (binary) {
      if (!response.ok) throw new Error(`Request failed (${response.status})`);
      return response.blob();
    }
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };

  const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

  const downloadBlob = async (path, filename) => {
    try {
      const blob = await api(path, {}, true);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename || 'download';
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
      status.textContent = error.message;
      status.className = 'status error';
    }
  };

  const addAction = (label, href, onClick) => {
    const el = document.createElement(onClick ? 'button' : 'a');
    el.className = 'button';
    el.textContent = label;
    if (href) el.href = href;
    if (onClick) { el.type = 'button'; el.addEventListener('click', onClick); }
    actions.append(el);
    actions.classList.remove('hidden');
  };

  // ---- Order (product purchase) ----
  const kindLabels = { PDF: 'Download PDF', SOURCE_CODE: 'Download source code' };

  const renderOrderDetails = async (order, handle) => {
    details.replaceChildren();
    const list = document.createElement('ul');
    list.className = 'content-list';
    (order.items || []).forEach((item) => {
      const snapshot = JSON.parse(item.product_snapshot || '{}');
      const li = document.createElement('li');
      li.innerHTML = `<span>${item.quantity}x ${escapeHtml(snapshot.title || 'Produk')}</span><span class="count">${order.currency} ${Number(item.subtotal).toLocaleString('id-ID')}</span>`;
      list.append(li);
    });
    details.append(list);
    const total = document.createElement('p');
    total.innerHTML = `<strong>Total: ${order.currency} ${Number(order.total_amount).toLocaleString('id-ID')}</strong>`;
    details.append(total);

    if (order.status !== 'COMPLETED') return;

    for (const item of order.items || []) {
      const snapshot = JSON.parse(item.product_snapshot || '{}');
      if (!snapshot.public_id) continue;
      try {
        const product = (await api(`/api/v1/products/${encodeURIComponent(snapshot.public_id)}`)).data;
        if (product.product_type !== 'DIGITAL') continue;
        const download = await api(`/api/v1/products/${encodeURIComponent(snapshot.public_id)}/download`);
        if (download.data.digital_asset_url) {
          addAction(`Download: ${snapshot.title}`, download.data.digital_asset_url);
        }
        (download.data.digital_assets || []).forEach((asset) => {
          addAction(
            `${kindLabels[asset.kind] || asset.kind}: ${snapshot.title}`,
            null,
            () => downloadBlob(`/api/v1/products/${encodeURIComponent(snapshot.public_id)}/digital-assets/${encodeURIComponent(asset.kind)}/download`, asset.original_filename),
          );
        });
      } catch (_) {
        // Not a digital product, or download not yet authorized — skip silently, physical items need no download button.
      }
    }
    addAction('Lihat halaman toko', `/@${encodeURIComponent(handle)}`);
  };

  const checkOrder = async (ref, handle) => {
    const result = await api(`/api/v1/orders/${encodeURIComponent(ref)}`);
    const order = result.data;
    if (order.status === 'COMPLETED') {
      heading.textContent = 'Pembayaran berhasil!';
      message.textContent = 'Terima kasih, pesanan Anda sudah kami terima.';
      await renderOrderDetails(order, handle);
      return true;
    }
    if (order.status === 'CANCELLED' || order.status === 'REFUNDED') {
      heading.textContent = 'Pesanan tidak selesai';
      message.textContent = `Status pesanan: ${order.status}.`;
      addAction('Kembali ke toko', `/@${encodeURIComponent(handle)}`);
      return true;
    }
    heading.textContent = 'Menunggu konfirmasi pembayaran…';
    message.textContent = 'Status saat ini: PENDING. Halaman ini akan otomatis diperbarui begitu pembayaran dikonfirmasi.';
    return false;
  };

  // ---- CV / Resume ----
  const checkCv = async (handle) => {
    const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/cv/access`, { method: 'POST' });
    if (result.data.granted) {
      heading.textContent = 'Pembayaran berhasil!';
      message.textContent = 'Akses CV/Resume Anda sudah aktif.';
      addAction('Download CV', null, () => downloadBlob(`/api/v1/profiles/${encodeURIComponent(handle)}/cv/download`, 'cv'));
      return true;
    }
    heading.textContent = 'Menunggu konfirmasi pembayaran…';
    message.textContent = 'Halaman ini akan otomatis diperbarui begitu pembayaran dikonfirmasi.';
    return false;
  };

  // ---- Chatbot wallet top-up ----
  const checkWallet = async (handle, before) => {
    const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/wallet`);
    const balance = Number(result.data.balance_amount);
    if (before === null || balance > before) {
      heading.textContent = 'Top up berhasil!';
      message.textContent = `Saldo Anda sekarang: ${result.data.currency} ${balance.toLocaleString('id-ID')}.`;
      addAction('Kembali ke chat', `/@${encodeURIComponent(handle)}`);
      return true;
    }
    heading.textContent = 'Menunggu konfirmasi pembayaran…';
    message.textContent = 'Halaman ini akan otomatis diperbarui begitu top up dikonfirmasi.';
    return false;
  };

  // ---- Driver ----
  const params = new URLSearchParams(location.search);
  const type = params.get('type');
  const handle = params.get('handle');
  const ref = params.get('ref');
  const before = params.get('before') !== null ? Number(params.get('before')) : null;

  let attempts = 0;

  const showTimeout = () => {
    heading.textContent = 'Masih diproses';
    message.textContent = 'Pembayaran Anda mungkin masih diproses (misalnya transfer manual yang menunggu konfirmasi pemilik toko). Coba cek ulang beberapa saat lagi.';
    actions.replaceChildren();
    addAction('Cek ulang', null, () => { attempts = 0; actions.replaceChildren(); actions.classList.add('hidden'); tick(); });
  };

  const tick = async () => {
    attempts += 1;
    try {
      let done = false;
      if (type === 'order' && ref) done = await checkOrder(ref, handle);
      else if (type === 'cv' && handle) done = await checkCv(handle);
      else if (type === 'wallet' && handle) done = await checkWallet(handle, before);
      else {
        heading.textContent = 'Terima kasih';
        message.textContent = 'Status pembayaran tidak dapat ditentukan dari halaman ini.';
        return;
      }
      if (done) return;
    } catch (error) {
      status.textContent = error.message;
      status.className = 'status error';
    }
    if (attempts >= MAX_ATTEMPTS) {
      showTimeout();
      return;
    }
    setTimeout(tick, INTERVAL_MS);
  };

  tick();
})();
