(() => {
  const tokenKey = 'fpdp_access_token';

  const loginForm = document.querySelector('#login-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const status = document.querySelector('#list-status');
  const postsContainer = document.querySelector('#posts-container');
  const postsSection = document.querySelector('#posts-list');
  const loadMoreButton = document.querySelector('#load-more');
  const filterButtons = document.querySelectorAll('.tab[data-filter]');

  const token = () => sessionStorage.getItem(tokenKey);
  const showStatus = (message, error = false) => {
    status.textContent = message;
    status.className = `status ${error ? 'error' : 'success'}`;
  };
const showOwnerNav = () => document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
  const setAuthenticated = (authenticated, label = '') => {
    loginForm.classList.toggle('hidden', authenticated);
    logoutButton.classList.toggle('hidden', !authenticated);
    postsSection.classList.toggle('hidden', !authenticated);
    authSummary.textContent = authenticated ? `Signed in${label ? ` as ${label}` : ''}.` : 'Sign in to view your posts.';
    if (authenticated) showOwnerNav();
  };
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };

  let allPosts = [];
  let currentFilter = 'all';
  let nextCursor = null;
  let hasMore = false;
  let loading = false;

  const postStatusLabel = (post) => {
    if (post.published_at) return { text: 'Published', cls: 'badge-green' };
    return { text: 'Draft', cls: 'badge-yellow' };
  };

  const statusIcon = (visibility) => {
    if (visibility === 'PUBLIC') return '🌐';
    if (visibility === 'UNLISTED') return '🔗';
    return '🔒';
  };
const renderPost = (post) => {
    const s = postStatusLabel(post);
    const div = document.createElement('div');
    div.className = 'post-list-item';
    div.innerHTML = `
      <div class="post-list-main">
        <h3><a href="/posts/${encodeURIComponent(post.id)}">${post.title ? htmlEsc(post.title) : '(no title)'}</a></h3>
        <p class="post-list-excerpt">${htmlEsc((post.content || '').slice(0, 200))}${(post.content || '').length > 200 ? '…' : ''}</p>
      </div>
      <div class="post-list-meta">
        <span class="badge ${s.cls}">${s.text}</span>
        <span title="${post.visibility}">${statusIcon(post.visibility)}</span>
        <span class="muted">${post.published_at ? new Date(post.published_at).toLocaleDateString() : 'Not published'}</span>
        <a class="button secondary small" href="/dashboard/posts?edit=${encodeURIComponent(post.id)}">Edit</a>
      </div>
    `;
    return div;
  };

  const htmlEsc = (str) => {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  };

  const renderFiltered = () => {
    postsContainer.replaceChildren();
    const filtered = allPosts.filter((p) => {
      if (currentFilter === 'published') return p.published_at !== null;
      if (currentFilter === 'draft') return p.published_at === null;
      return true;
    });
    if (filtered.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'empty muted';
      empty.textContent = currentFilter === 'draft' ? 'No drafts yet.' : 'No posts yet.';
      postsContainer.append(empty);
      return;
    }
    filtered.forEach((p) => postsContainer.append(renderPost(p)));
  };

  const loadPosts = async (append = false) => {
    if (loading) return;
    loading = true;
    showStatus('Loading…');
    try {
      const url = '/api/v1/me/posts?limit=50' + (nextCursor && append ? `&cursor=${encodeURIComponent(nextCursor)}` : '');
      const result = await api(url);
      const items = result.data || [];

      if (!append) allPosts = items;
      else allPosts = allPosts.concat(items);

      hasMore = result.meta?.has_more || false;
      nextCursor = result.meta?.next_cursor || null;
      loadMoreButton.classList.toggle('hidden', !hasMore);
      renderFiltered();
      showStatus(`${allPosts.length} post${allPosts.length !== 1 ? 's' : ''} total.`);
    } catch (error) {
      showStatus(error.message, true);
    } finally {
      loading = false;
    }
  };

  const verifySession = async () => {
    if (!token()) { setAuthenticated(false); return false; }
    try {
      const result = await api('/api/v1/me');
      setAuthenticated(true, result.data.profile.handle);
      return true;
    } catch (error) {
      sessionStorage.removeItem(tokenKey);
      setAuthenticated(false);
      console.error('verifySession failed:', error);
      return false;
    }
  };

  /* ── Event listeners ── */

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = new FormData(loginForm);
    try {
      const result = await api('/api/v1/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email: fields.get('email'), password: fields.get('password') }),
      });
      sessionStorage.setItem(tokenKey, result.data.token.access_token);
      loginForm.reset();
      if (await verifySession()) {
        showStatus('Signed in successfully.');
        loadPosts();
      } else {
        showStatus('Session verification failed. Please try again.', true);
      }
    } catch (error) { showStatus(error.message, true); }
  });

  logoutButton.addEventListener('click', async () => {
    try { await api('/api/v1/auth/logout', { method: 'POST' }); } catch (_) { /* ignore */ }
    sessionStorage.removeItem(tokenKey);
    setAuthenticated(false);
    allPosts = [];
    postsContainer.replaceChildren();
    showStatus('Signed out.');
  });

  loadMoreButton.addEventListener('click', () => loadPosts(true));

  filterButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      filterButtons.forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      currentFilter = btn.dataset.filter;
      renderFiltered();
    });
  });

  /* ── Init ── */

  (async () => {
    if (await verifySession()) {
      await loadPosts();
    }
  })();
})();

/* ── Toggle password visibility ── */
document.querySelectorAll('.toggle-password').forEach((btn) => {
  btn.addEventListener('click', () => {
    const wrapper = btn.closest('.password-wrapper');
    const input = wrapper.querySelector('input');
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.textContent = isPassword ? btn.dataset.hide : btn.dataset.show;
    btn.setAttribute('aria-label', (isPassword ? 'Hide' : 'Show') + ' password');
  });
});