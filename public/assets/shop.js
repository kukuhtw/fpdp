(() => {
  // Translated UI text (window.fpdpT from the page head); the Indonesian fallback keeps the page readable without it.
  const t = window.fpdpT || ((k, p, f) => Object.keys(p || {}).reduce((s, n) => s.split(`:${n}`).join(p[n]), f));
  const numberLocale = window.FPDP_LOCALE === 'en' ? 'en-US' : 'id-ID';
  const handle = document.body.dataset.profileHandle;
  const grid = document.querySelector('#product-grid');
  const loadMoreButton = document.querySelector('#load-more');
  let nextCursor = null;

  const api = async (path) => {
    const response = await fetch(path);
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || t('common.request_failed', { status: response.status }, `Request failed (${response.status})`));
    return payload;
  };

  const formatPrice = (price, currency) => {
    const amount = Number(price);
    if (!amount) return t('checkout.free', {}, 'Gratis');
    return `${currency} ${amount.toLocaleString(numberLocale)}`;
  };

  const productCard = (product) => {
    const card = document.createElement('article');
    card.className = 'product-card';
    const typeLabel = {
      PHYSICAL: t('checkout.type_physical', {}, 'Barang fisik'),
      DIGITAL: t('checkout.type_digital', {}, 'Barang digital'),
      SERVICE: t('checkout.type_service', {}, 'Jasa'),
    }[product.product_type] || product.product_type;

    const photoUrl = product.media && product.media[0] && product.media[0].url;
    if (photoUrl) {
      const img = document.createElement('img');
      img.className = 'product-photo';
      img.src = photoUrl;
      img.alt = product.title;
      card.append(img);
    }

    const title = document.createElement('h2');
    const link = document.createElement('a');
    link.href = `/shop/${encodeURIComponent(product.public_id)}`;
    link.textContent = product.title;
    title.append(link);
    const desc = document.createElement('p');
    desc.textContent = product.description || typeLabel;

    const price = document.createElement('span');
    price.className = 'status-badge';
    price.textContent = formatPrice(product.price, product.currency);

    card.append(title, desc, price);
    return card;
  };

  const mutedParagraph = (text) => {
    const p = document.createElement('p');
    p.className = 'muted';
    p.textContent = text;
    return p;
  };

  const renderProducts = (products, append) => {
    if (!append) grid.replaceChildren();
    if (products.length === 0 && !append) {
      grid.replaceChildren(mutedParagraph(t('shop.empty', {}, 'Belum ada produk.')));
      return;
    }
    products.forEach((product) => grid.append(productCard(product)));
  };

  const loadProducts = async (append = false) => {
    try {
      const cursorParam = append && nextCursor ? `?cursor=${encodeURIComponent(nextCursor)}` : '';
      const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/products${cursorParam}`);
      renderProducts(result.data, append);
      nextCursor = result.meta.next_cursor;
      loadMoreButton.classList.toggle('hidden', !result.meta.has_more);
    } catch (error) {
      grid.replaceChildren(mutedParagraph(error.message));
    }
  };

  loadMoreButton.addEventListener('click', () => loadProducts(true));
  loadProducts(false);
})();
