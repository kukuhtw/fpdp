(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#products-content');
  const form = document.querySelector('#product-form');
  const formHeading = document.querySelector('#form-heading');
  const digitalUrlField = document.querySelector('#digital-url-field');
  const cancelEditButton = document.querySelector('#cancel-edit');
  const productList = document.querySelector('#product-list');
  const status = document.querySelector('#product-status');

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

  const toggleDigitalField = () => {
    digitalUrlField.classList.toggle('hidden', form.elements.product_type.value !== 'DIGITAL');
  };
  form.elements.product_type.addEventListener('change', toggleDigitalField);

  const resetForm = () => {
    form.reset();
    form.elements.public_id.value = '';
    formHeading.textContent = 'Tambah produk';
    cancelEditButton.classList.add('hidden');
    toggleDigitalField();
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
    formHeading.textContent = `Edit: ${product.title}`;
    cancelEditButton.classList.remove('hidden');
    toggleDigitalField();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const productCard = (product) => {
    const card = document.createElement('article');
    card.className = 'gateway-card';
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
    };
    if (body.product_type === 'DIGITAL') {
      body.digital_asset_url = form.elements.digital_asset_url.value.trim();
    }
    try {
      if (publicId) {
        await api(`/api/v1/products/${encodeURIComponent(publicId)}`, { method: 'PATCH', body: JSON.stringify(body) });
        message('Produk diperbarui.');
      } else {
        await api('/api/v1/products', { method: 'POST', body: JSON.stringify(body) });
        message('Produk ditambahkan.');
      }
      resetForm();
      await loadProducts();
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
