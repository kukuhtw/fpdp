(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#products-content');
  const form = document.querySelector('#product-form');
  const formHeading = document.querySelector('#form-heading');
  const digitalUrlField = document.querySelector('#digital-url-field');
  const digitalAssetsField = document.querySelector('#digital-assets-field');
  const digitalAssetsHint = document.querySelector('#digital-assets-hint');
  const digitalAssetsUploaders = document.querySelector('#digital-assets-uploaders');
  const digitalAssetsList = document.querySelector('#digital-assets-list');
  const digitalAssetStatus = document.querySelector('#digital-asset-status');
  const digitalAssetProgress = document.querySelector('#digital-asset-progress');
  const digitalPdfInput = document.querySelector('#digital-pdf-input');
  const digitalPdfUploadButton = document.querySelector('#digital-pdf-upload-button');
  const digitalSourceInput = document.querySelector('#digital-source-input');
  const digitalSourceUploadButton = document.querySelector('#digital-source-upload-button');
  const cancelEditButton = document.querySelector('#cancel-edit');
  const productList = document.querySelector('#product-list');
  const status = document.querySelector('#product-status');
  const photoPreview = document.querySelector('#product-photo-preview');
  const photoInput = document.querySelector('#product-photo-input');
  const photoUploadButton = document.querySelector('#product-photo-upload-button');
  const photoProgress = document.querySelector('#product-photo-progress');
  const photoStatus = document.querySelector('#product-photo-status');
  const describeWithAiButton = document.querySelector('#describe-with-ai-button');

  let lastUploadedPhotoStorageKey = null;
  let llmVisionEnabled = false;

  const token = () => sessionStorage.getItem(tokenKey);
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };
  const message = (text, error = false) => {
    status.textContent = text;
    status.className = `status ${error ? 'error' : 'success'}`;
  };
  const formatPrice = (price, currency) => `${currency} ${Number(price).toLocaleString('id-ID')}`;

  const readAsBase64 = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
    reader.onerror = () => reject(new Error('Could not read the selected file.'));
    reader.readAsDataURL(file);
  });
  const postJsonWithProgress = (path, body, onProgress) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', path);
    xhr.setRequestHeader('Content-Type', 'application/json');
    if (token()) xhr.setRequestHeader('Authorization', `Bearer ${token()}`);
    xhr.upload.addEventListener('progress', (event) => { if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100)); });
    xhr.addEventListener('load', () => {
      let payload = null;
      let parseFailed = false;
      try { payload = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch (_) { parseFailed = true; }
      if (xhr.status >= 200 && xhr.status < 300 && !parseFailed) resolve(payload);
      else if (parseFailed) reject(new Error(`The server returned an invalid response (HTTP ${xhr.status}). Check the server's error log — a stray warning before the JSON output is the usual cause.`));
      else reject(new Error(payload?.error?.message || `Request failed (${xhr.status})`));
    });
    xhr.addEventListener('error', () => reject(new Error('Network error while uploading.')));
    xhr.send(JSON.stringify(body));
  });

  // ---- Product photo upload ----
  const setPhotoPreview = (url) => {
    if (url) { photoPreview.src = url; photoPreview.classList.remove('hidden'); } else { photoPreview.removeAttribute('src'); photoPreview.classList.add('hidden'); }
  };
  const showPhotoStatus = (text, error = false) => { photoStatus.textContent = text; photoStatus.className = `status ${error ? 'error' : 'success'}`; };
  const photoMaxBytes = Number(photoInput.dataset.maxBytes) || 10485760;
  photoInput.addEventListener('change', () => {
    const file = photoInput.files[0];
    if (file && file.size > photoMaxBytes) {
      photoUploadButton.disabled = true;
      showPhotoStatus(`File terlalu besar (${(file.size / 1048576).toFixed(1)} MB). Maks ${(photoMaxBytes / 1048576).toFixed(0)} MB.`, true);
      return;
    }
    photoUploadButton.disabled = photoInput.files.length === 0;
    showPhotoStatus('');
  });
  photoUploadButton.addEventListener('click', async () => {
    if (!token()) return showPhotoStatus('Masuk terlebih dahulu.', true);
    const file = photoInput.files[0];
    if (!file) return;
    photoUploadButton.disabled = true;
    photoProgress.value = 0;
    photoProgress.classList.remove('hidden');
    showPhotoStatus('Membaca file…');
    try {
      const contentBase64 = await readAsBase64(file);
      showPhotoStatus('Mengunggah… 0%');
      const result = await postJsonWithProgress('/api/v1/me/media', { media_type: 'IMAGE', content_base64: contentBase64 }, (percent) => {
        photoProgress.value = percent;
        showPhotoStatus(`Mengunggah… ${percent}%`);
      });
      form.elements.media_url.value = result.data.url;
      lastUploadedPhotoStorageKey = result.data.storage_key;
      setPhotoPreview(result.data.url);
      updateDescribeButtonVisibility();
      showPhotoStatus('Foto terunggah. Klik "Simpan produk" untuk menerapkannya.');
    } catch (error) {
      showPhotoStatus(error.message, true);
    } finally {
      photoUploadButton.disabled = photoInput.files.length === 0;
      photoProgress.classList.add('hidden');
    }
  });

  // ---- AI-generated description (Part C) ----
  const updateDescribeButtonVisibility = () => {
    describeWithAiButton.classList.toggle('hidden', !(llmVisionEnabled && lastUploadedPhotoStorageKey));
  };
  const loadLlmVisionAvailability = async () => {
    try {
      const result = await api('/api/v1/me/llm-config');
      llmVisionEnabled = !!(result.data.key_configured && result.data.supports_vision);
    } catch (_) {
      llmVisionEnabled = false;
    }
    updateDescribeButtonVisibility();
  };
  describeWithAiButton.addEventListener('click', async () => {
    if (!lastUploadedPhotoStorageKey) return;
    describeWithAiButton.disabled = true;
    describeWithAiButton.textContent = 'Membuat deskripsi…';
    try {
      const result = await api('/api/v1/me/llm-config/describe-image', {
        method: 'POST',
        body: JSON.stringify({ storage_key: lastUploadedPhotoStorageKey }),
      });
      form.elements.description.value = result.data.description;
      message('Deskripsi AI ditambahkan. Anda bisa mengeditnya sebelum menyimpan.');
    } catch (error) {
      message(error.message, true);
    } finally {
      describeWithAiButton.disabled = false;
      describeWithAiButton.textContent = 'Buat deskripsi dengan AI';
    }
  });

  // ---- Digital assets (PDF / source code) ----
  const kindLabels = { PDF: 'PDF', SOURCE_CODE: 'Source code' };
  const showDigitalAssetStatus = (text, error = false) => { digitalAssetStatus.textContent = text; digitalAssetStatus.className = `status ${error ? 'error' : 'success'}`; };
  const renderDigitalAssetsList = (assets) => {
    digitalAssetsList.replaceChildren();
    if (!assets || assets.length === 0) {
      digitalAssetsList.textContent = 'Belum ada file yang diunggah.';
      return;
    }
    assets.forEach((asset) => {
      const p = document.createElement('p');
      p.textContent = `${kindLabels[asset.kind] || asset.kind}: ${asset.original_filename || '(tanpa nama)'} (${(asset.size_bytes / 1048576).toFixed(2)} MB)`;
      digitalAssetsList.append(p);
    });
  };
  const loadDigitalAssets = async (publicId) => {
    if (!publicId) { renderDigitalAssetsList([]); return; }
    try {
      const result = await api(`/api/v1/me/products/${encodeURIComponent(publicId)}/digital-assets`);
      renderDigitalAssetsList(result.data);
    } catch (error) {
      showDigitalAssetStatus(error.message, true);
    }
  };
  const uploadDigitalAsset = async (kind, fileInput, uploadButton) => {
    const publicId = form.elements.public_id.value;
    if (!publicId) return;
    const file = fileInput.files[0];
    if (!file) return;
    uploadButton.disabled = true;
    digitalAssetProgress.value = 0;
    digitalAssetProgress.classList.remove('hidden');
    showDigitalAssetStatus('Mengunggah…');
    try {
      const contentBase64 = await readAsBase64(file);
      const result = await postJsonWithProgress(`/api/v1/me/products/${encodeURIComponent(publicId)}/digital-assets`, {
        kind,
        content_base64: contentBase64,
        filename: file.name,
      }, (percent) => {
        digitalAssetProgress.value = percent;
        showDigitalAssetStatus(`Mengunggah… ${percent}%`);
      });
      renderDigitalAssetsList(result.data);
      showDigitalAssetStatus(`${kindLabels[kind] || kind} berhasil diunggah.`);
    } catch (error) {
      showDigitalAssetStatus(error.message, true);
    } finally {
      uploadButton.disabled = fileInput.files.length === 0;
      digitalAssetProgress.classList.add('hidden');
    }
  };
  [digitalPdfInput, digitalSourceInput].forEach((input, index) => {
    const button = index === 0 ? digitalPdfUploadButton : digitalSourceUploadButton;
    input.addEventListener('change', () => { button.disabled = input.files.length === 0; });
  });
  digitalPdfUploadButton.addEventListener('click', () => uploadDigitalAsset('PDF', digitalPdfInput, digitalPdfUploadButton));
  digitalSourceUploadButton.addEventListener('click', () => uploadDigitalAsset('SOURCE_CODE', digitalSourceInput, digitalSourceUploadButton));

  const toggleDigitalField = () => {
    const isDigital = form.elements.product_type.value === 'DIGITAL';
    digitalUrlField.classList.toggle('hidden', !isDigital);
    digitalAssetsField.classList.toggle('hidden', !isDigital);
    if (!isDigital) return;
    const hasPublicId = !!form.elements.public_id.value;
    digitalAssetsHint.classList.toggle('hidden', hasPublicId);
    digitalAssetsUploaders.classList.toggle('hidden', !hasPublicId);
  };
  form.elements.product_type.addEventListener('change', toggleDigitalField);

  const resetForm = () => {
    form.reset();
    form.elements.public_id.value = '';
    form.elements.media_url.value = '';
    setPhotoPreview(null);
    lastUploadedPhotoStorageKey = null;
    updateDescribeButtonVisibility();
    formHeading.textContent = 'Tambah produk';
    cancelEditButton.classList.add('hidden');
    toggleDigitalField();
    renderDigitalAssetsList([]);
  };
  cancelEditButton.addEventListener('click', resetForm);

  const editProduct = (product) => {
    form.elements.public_id.value = product.public_id;
    form.elements.title.value = product.title;
    form.elements.description.value = product.description || '';
    form.elements.price.value = product.price;
    form.elements.currency.value = product.currency;
    form.elements.product_type.value = product.product_type;
    form.elements.digital_asset_url.value = product.digital_asset_url || '';
    form.elements.status.value = product.status;
    form.elements.visibility.value = product.visibility;
    form.elements.is_promoted.checked = !!product.is_promoted;
    const mediaUrl = (product.media && product.media[0] && product.media[0].url) || '';
    form.elements.media_url.value = mediaUrl;
    setPhotoPreview(mediaUrl || null);
    lastUploadedPhotoStorageKey = null;
    updateDescribeButtonVisibility();
    formHeading.textContent = `Edit: ${product.title}`;
    cancelEditButton.classList.remove('hidden');
    toggleDigitalField();
    loadDigitalAssets(product.public_id);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const productCard = (product) => {
    const card = document.createElement('article');
    card.className = 'gateway-card';
    const photoUrl = product.media && product.media[0] && product.media[0].url;
    if (photoUrl) {
      const img = document.createElement('img');
      img.src = photoUrl;
      img.alt = product.title;
      img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:9px;border:1px solid var(--line);float:left;margin-right:12px';
      card.append(img);
    }
    const head = document.createElement('div');
    head.className = 'gateway-card-head';
    head.innerHTML = `<strong>${escapeHtml(product.title)}</strong> <span class="muted">${formatPrice(product.price, product.currency)} · ${escapeHtml(product.product_type)} · ${escapeHtml(product.status)}</span>`;
    const editButton = document.createElement('button');
    editButton.type = 'button';
    editButton.className = 'secondary';
    editButton.textContent = 'Edit';
    editButton.addEventListener('click', () => editProduct(product));
    card.append(head, editButton);
    return card;
  };

  const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

  const loadProducts = async () => {
    try {
      const result = await api('/api/v1/products?mine=1');
      productList.replaceChildren();
      if (result.data.length === 0) {
        productList.innerHTML = '<p class="muted">Belum ada produk.</p>';
        return;
      }
      result.data.forEach((product) => productList.append(productCard(product)));
    } catch (error) {
      productList.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const publicId = form.elements.public_id.value;
    const body = {
      title: form.elements.title.value.trim(),
      description: form.elements.description.value.trim() || null,
      price: Number(form.elements.price.value),
      currency: form.elements.currency.value,
      product_type: form.elements.product_type.value,
      status: form.elements.status.value,
      visibility: form.elements.visibility.value,
      is_promoted: form.elements.is_promoted.checked,
      media: form.elements.media_url.value ? [{ type: 'IMAGE', url: form.elements.media_url.value }] : [],
    };
    if (body.product_type === 'DIGITAL' && form.elements.digital_asset_url.value.trim()) {
      body.digital_asset_url = form.elements.digital_asset_url.value.trim();
    }
    try {
      let savedProduct;
      if (publicId) {
        savedProduct = (await api(`/api/v1/products/${encodeURIComponent(publicId)}`, { method: 'PATCH', body: JSON.stringify(body) })).data;
        message('Produk diperbarui.');
      } else {
        savedProduct = (await api('/api/v1/products', { method: 'POST', body: JSON.stringify(body) })).data;
        message('Produk ditambahkan. Sekarang Anda bisa upload file PDF/source code jika produk digital.');
      }
      await loadProducts();
      editProduct(savedProduct);
    } catch (error) {
      message(error.message, true);
    }
  });

  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk mengelola produk.';
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
      await loadProducts();
      await loadLlmVisionAvailability();
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
