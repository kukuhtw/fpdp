(() => {
  // Translated UI text (window.fpdpT from the page head); the Indonesian fallback keeps the page readable without it.
  const t = window.fpdpT || ((k, p, f) => Object.keys(p || {}).reduce((s, n) => s.split(`:${n}`).join(p[n]), f));
  const numberLocale = window.FPDP_LOCALE === 'en' ? 'en-US' : 'id-ID';
  const tokenKey = 'fpdp_visitor_token';
  const handle = document.body.dataset.profileHandle;
  const productId = document.body.dataset.productId;
  const details = document.querySelector('#product-details');
  const actions = document.querySelector('#product-actions');
  const status = document.querySelector('#product-status');
  const googleLogin = document.querySelector('#google-login');
  const buyerIdentity = document.querySelector('#buyer-identity');
  const quantityField = document.querySelector('#quantity-field');
  const quantityInput = document.querySelector('#quantity-input');
  const recipientNameField = document.querySelector('#recipient-name-field');
  const recipientNameInput = document.querySelector('#recipient-name-input');
  const recipientPhoneField = document.querySelector('#recipient-phone-field');
  const recipientPhoneInput = document.querySelector('#recipient-phone-input');
  const shippingAddressField = document.querySelector('#shipping-address-field');
  const shippingAddressInput = document.querySelector('#shipping-address-input');
  const orderNotesField = document.querySelector('#order-notes-field');
  const orderNotesInput = document.querySelector('#order-notes-input');
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
      if (!response.ok) throw new Error(t('common.request_failed', { status: response.status }, `Request failed (${response.status})`));
      return response.blob();
    }
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || t('common.request_failed', { status: response.status }, `Request failed (${response.status})`));
    return payload;
  };

  const kindLabels = {
    PDF: t('checkout.download_pdf', {}, 'Download PDF'),
    SOURCE_CODE: t('checkout.download_source', {}, 'Download source code'),
  };
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

  const confirmLink = document.querySelector('#payment-confirm-link');
  const showConfirmLink = (orderPublicId) => {
    if (!confirmLink) return;
    confirmLink.innerHTML = '';
    const link = document.createElement('a');
    link.className = 'button secondary';
    link.href = `/payment/thank-you?type=order&ref=${encodeURIComponent(orderPublicId)}&handle=${encodeURIComponent(handle)}`;
    link.textContent = t('checkout.confirm_link', {}, 'Sudah transfer? Cek status pembayaran →');
    confirmLink.append(link);
  };

  const formatPrice = (price, currency) => {
    const amount = Number(price);
    if (!amount) return t('checkout.free', {}, 'Gratis');
    return `${currency} ${amount.toLocaleString(numberLocale)}`;
  };

  const typeLabel = {
    PHYSICAL: t('checkout.type_physical', {}, 'Barang fisik'),
    DIGITAL: t('checkout.type_digital', {}, 'Barang digital'),
    SERVICE: t('checkout.type_service', {}, 'Jasa'),
  };

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
      buyerIdentity.classList.add('hidden');
      quantityField.classList.add('hidden');
      recipientNameField.classList.add('hidden');
      recipientPhoneField.classList.add('hidden');
      shippingAddressField.classList.add('hidden');
      orderNotesField.classList.add('hidden');
      buyButton.classList.add('hidden');
      downloadButton.classList.add('hidden');
      return;
    }

    const visitorName = sessionStorage.getItem('fpdp_visitor_name');
    const visitorEmail = sessionStorage.getItem('fpdp_visitor_email');
    if (visitorEmail) {
      const who = `${visitorName ? `${visitorName} ` : ''}(${visitorEmail})`;
      buyerIdentity.textContent = t('checkout.signed_in_as', { who }, `Masuk sebagai: ${who}`);
      buyerIdentity.classList.remove('hidden');
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
          const label = kindLabels[asset.kind] || t('checkout.download_kind', { kind: asset.kind }, `Download ${asset.kind}`);
          if (asset.download_url) {
            // A plain link to a short-lived signed URL: the browser downloads
            // it natively, which also works on phones and in-app browsers
            // where saving a JavaScript blob silently does nothing.
            const link = document.createElement('a');
            link.className = 'button';
            link.href = asset.download_url;
            link.textContent = label;
            if (asset.original_filename) link.setAttribute('download', asset.original_filename);
            digitalAssetsButtons.append(link);
            return;
          }
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'button';
          button.textContent = label;
          button.addEventListener('click', () => downloadGatedAsset(asset.kind, asset.original_filename));
          digitalAssetsButtons.append(button);
        });
        quantityField.classList.add('hidden');
        recipientNameField.classList.add('hidden');
        recipientPhoneField.classList.add('hidden');
        shippingAddressField.classList.add('hidden');
        orderNotesField.classList.add('hidden');
        buyButton.classList.add('hidden');
        return;
      } catch (_) {
        // not purchased yet — fall through to showing the buy button
      }
    }

    downloadButton.classList.add('hidden');
    digitalAssetsButtons.replaceChildren();
    quantityField.classList.remove('hidden');
    const isPhysical = product.product_type === 'PHYSICAL';
    recipientNameField.classList.toggle('hidden', !isPhysical);
    recipientPhoneField.classList.toggle('hidden', !isPhysical);
    shippingAddressField.classList.toggle('hidden', !isPhysical);
    orderNotesField.classList.remove('hidden');
    buyButton.classList.remove('hidden');
  };

  const load = async () => {
    try {
      product = (await api(`/api/v1/products/${encodeURIComponent(productId)}`)).data;
      renderDetails();
      await updateAuthUi();
    } catch (error) {
      const notFound = document.createElement('p');
      notFound.className = 'muted';
      notFound.textContent = t('product.not_found', {}, 'Produk tidak ditemukan.');
      details.replaceChildren(notFound);
      message(error.message, true);
    }
  };

  buyButton.addEventListener('click', async () => {
    const quantity = Math.max(1, parseInt(quantityInput.value, 10) || 1);
    const notes = orderNotesInput.value.trim();
    let shippingAddress = '';
    if (product.product_type === 'PHYSICAL') {
      const recipientName = recipientNameInput.value.trim();
      const recipientPhone = recipientPhoneInput.value.trim();
      const addressLine = shippingAddressInput.value.trim();
      if (!recipientName || !recipientPhone || !addressLine) {
        return message(t('product.fill_shipping', {}, 'Isi nama penerima, nomor telepon, dan alamat lengkap terlebih dahulu.'), true);
      }
      shippingAddress = `Nama: ${recipientName}\nTelepon: ${recipientPhone}\nAlamat: ${addressLine}`;
    }
    buyButton.disabled = true;
    message(t('product.processing', {}, 'Memproses pembelian…'));
    try {
      const body = { items: [{ product_id: productId, quantity }] };
      if (shippingAddress) body.shipping_address = shippingAddress;
      if (notes) body.notes = notes;
      const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/orders`, {
        method: 'POST',
        body: JSON.stringify(body),
      });
      const { order, payment } = result.data;
      if (order.status === 'COMPLETED') {
        message(t('product.paid', {}, 'Pembayaran berhasil. Terima kasih!'));
        await updateAuthUi();
        return;
      }
      if (payment?.payment_url) {
        message(t('checkout.redirecting', {}, 'Mengalihkan ke halaman pembayaran…'));
        location.href = payment.payment_url;
        return;
      }
      if (payment?.instructions) {
        message(payment.instructions);
        showConfirmLink(order.public_id);
        return;
      }
      message(t('product.order_pending', {}, 'Pesanan dibuat, menunggu konfirmasi pembayaran.'));
      showConfirmLink(order.public_id);
    } catch (error) {
      message(error.message, true);
    } finally {
      buyButton.disabled = false;
    }
  });

  load();
})();
