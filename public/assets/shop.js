(() => {
  const handle = document.body.dataset.profileHandle;
  const grid = document.querySelector('#product-grid');
  const loadMoreButton = document.querySelector('#load-more');
  let nextCursor = null;

  const api = async (path) => {
    const response = await fetch(path);
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };

  const formatPrice = (price, currency) => {
    const amount = Number(price);
    if (!amount) return 'Gratis';
    return `${currency} ${amount.toLocaleString('id-ID')}`;
  };

  const productCard = (product) => {
    const card = document.createElement('article');
    card.className = 'provider-card available';
    const typeLabel = { PHYSICAL: 'Barang fisik', DIGITAL: 'Barang digital', SERVICE: 'Jasa' }[product.product_type] || product.product_type;

    const body = document.createElement('div');
    const title = document.createElement('h2');
    const link = document.createElement('a');
    link.href = `/shop/${encodeURIComponent(product.public_id)}`;
    link.textContent = product.title;
    title.append(link);
    const desc = document.createElement('p');
    desc.textContent = product.description || typeLabel;
    body.append(title, desc);

    const price = document.createElement('span');
    price.className = 'status-badge';
    price.textContent = formatPrice(product.price, product.currency);

    card.append(body, price);
    return card;
  };

  const renderProducts = (products, append) => {
    if (!append) grid.replaceChildren();
    if (products.length === 0 && !append) {
      grid.innerHTML = '<p class="muted">Belum ada produk.</p>';
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
      grid.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };

  loadMoreButton.addEventListener('click', () => loadProducts(true));
  loadProducts(false);
})();
