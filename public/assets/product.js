(() => {
  const tokenKey = 'fpdp_visitor_token';
  const handle = document.body.dataset.profileHandle;
  const productId = document.body.dataset.productId;
  const details = document.querySelector('#product-details');
  const actions = document.querySelector('#product-actions');
  const status = document.querySelector('#product-status');
  const googleLogin = document.querySelector('#google-login');
  const quantityField = document.querySelector('#quantity-field');
  const quantityInput = document.querySelector('#quantity-input');
  const shippingAddressField = document.querySelector('#shipping-address-field');
  const shippingAddressInput = document.querySelector('#shipping-address-input');
  const buyButton = document.querySelector('#buy-button');
  const downloadButton = document.querySelector('#download-button');
  const digitalAssetsButtons = document.querySelector('#digital-assets-buttons');
  let product = null;

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

  const kindLabels = { PDF: 'Download PDF', SOURCE_CODE: 'Download source code' };
  const downloadGatedAsset = async (kind, filename) => {
    try {
      const blob = await api(`/api/v1/products/${encodeURIComponent(productId)}/digital-assets/${encodeURIComponent(kind)}/download`, {}, true);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename || kind.toLowerCase();
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
      message(error.message, true);
    }
  };

  const message = (text, error = false) => {
    status.textContent = text;
    status.className = `status ${error ? 'error' : 'success'}`;
  };

  const formatPrice = (price, currency) => {
    const amount = Number(price);
    if (!amount) return 'Gratis';
    return `${currency} ${amount.toLocaleString('id-ID')}`;
  };

  const typeLabel = { PHYSICAL: 'Barang fisik', DIGITAL: 'Barang digital', SERVICE: 'Jasa' };

  const renderDetails = () => {
    details.innerHTML = '';
    const photoUrl = product.media && product.media[0] && product.media[0].url;
    if (photoUrl) {
      const img = document.createElement('img');
      img.src = photoUrl;
      img.alt = product.title;
      img.style.cssText = 'width:100%;max-height:420px;object-fit:cover;border-radius:12px;border:1px solid var(--line);margin-bottom:16px';
      details.append(img);
    }
    const title = document.createElement('h1');
    title.textContent = product.title;
    const price = document.createElement('p');
    price.innerHTML = `<strong>${formatPrice(product.price, product.currency)}</strong> · ${typeLabel[product.product_type] || product.product_type}`;
    details.append(title, price);
    if (product.description) {
      const desc = document.createElement('p');
      desc.textContent = product.description;
      details.append(desc);
    }
  };

  const updateAuthUi = async () => {
    const signedIn = Boolean(sessionStorage.getItem(tokenKey));
    googleLogin.classList.toggle('hidden', signedIn);
    actions.classList.remove('hidden');

    if (!signedIn) {
      quantityField.classList.add('hidden');
      shippingAddressField.classList.add('hidden');
      buyButton.classList.add('hidden');
      downloadButton.classList.add('hidden');
      return;
    }

    if (product.product_type === 'DIGITAL') {
      try {
        const download = await api(`/api/v1/products/${encodeURIComponent(productId)}/download`);
        digitalAssetsButtons.replaceChildren();
        if (download.data.digital_asset_url) {
          downloadButton.href = download.data.digital_asset_url;
          downloadButton.classList.remove('hidden');
        } else {
          downloadButton.classList.add('hidden');
        }
        (download.data.digital_assets || []).forEach((asset) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'button';
          button.textContent = kindLabels[asset.kind] || `Download ${asset.kind}`;
          button.addEventListener('click', () => downloadGatedAsset(asset.kind, asset.original_filename));
          digitalAssetsButtons.append(button);
        });
        quantityField.classList.add('hidden');
        shippingAddressField.classList.add('hidden');
        buyButton.classList.add('hidden');
        return;
      } catch (_) {
        // not purchased yet — fall through to showing the buy button
      }
    }

    downloadButton.classList.add('hidden');
    digitalAssetsButtons.replaceChildren();
    quantityField.classList.remove('hidden');
    shippingAddressField.classList.toggle('hidden', product.product_type !== 'PHYSICAL');
    buyButton.classList.remove('hidden');
  };

  const load = async () => {
    try {
      product = (await api(`/api/v1/products/${encodeURIComponent(productId)}`)).data;
      renderDetails();
      await updateAuthUi();
    } catch (error) {
      details.innerHTML = '<p class="muted">Produk tidak ditemukan.</p>';
      message(error.message, true);
    }
  };

  buyButton.addEventListener('click', async () => {
    const quantity = Math.max(1, parseInt(quantityInput.value, 10) || 1);
    const shippingAddress = shippingAddressInput.value.trim();
    if (product.product_type === 'PHYSICAL' && !shippingAddress) {
      return message('Isi alamat pengiriman terlebih dahulu.', true);
    }
    buyButton.disabled = true;
    message('Memproses pembelian…');
    try {
      const body = { items: [{ product_id: productId, quantity }] };
      if (shippingAddress) body.shipping_address = shippingAddress;
      const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/orders`, {
        method: 'POST',
        body: JSON.stringify(body),
      });
      const { order, payment } = result.data;
      if (order.status === 'COMPLETED') {
        message('Pembayaran berhasil. Terima kasih!');
        await updateAuthUi();
        return;
      }
      if (payment?.payment_url) {
        message('Mengalihkan ke halaman pembayaran…');
        location.href = payment.payment_url;
        return;
      }
      if (payment?.instructions) {
        message(payment.instructions);
        return;
      }
      message('Pesanan dibuat, menunggu konfirmasi pembayaran.');
    } catch (error) {
      message(error.message, true);
    } finally {
      buyButton.disabled = false;
    }
  });

  load();
})();
