(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#themes-content');
  const grid = document.querySelector('#theme-grid');
  const status = document.querySelector('#theme-status');

  const token = () => sessionStorage.getItem(tokenKey);
  const api = async (path, options = {}) => {
    const headers = { ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    if (response.status === 204) return null;
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };

  const VIEW_LABELS = {
    'profile': 'Profil',
    'about-me': 'About Me',
    'youtube': 'YouTube',
    'wall-coretan': 'Coretan',
    'post': 'Post',
    'public-cv': 'CV publik',
  };

  const themeCard = (theme, activeSlug) => {
    const card = document.createElement('article');
    card.className = 'provider-card available';

    const logo = document.createElement('div');
    logo.className = 'provider-logo';
    logo.style.background = theme.preview_color || '#185f48';
    logo.textContent = (theme.name || theme.slug).charAt(0).toUpperCase();

    const body = document.createElement('div');
    const h2 = document.createElement('h2');
    h2.textContent = theme.name;
    const desc = document.createElement('p');
    desc.textContent = theme.description || '';
    const meta = document.createElement('p');
    meta.className = 'provider-state';
    const viewLabels = (theme.overridden_views || []).map((v) => VIEW_LABELS[v] || v);
    meta.textContent = `v${theme.version || '1.0.0'}${theme.author ? ' · ' + theme.author : ''}${viewLabels.length ? ' · override: ' + viewLabels.join(', ') : ' · pakai tampilan bawaan'}`;

    body.append(h2, desc, meta);

    const isActive = theme.slug === activeSlug;
    const badge = document.createElement('span');
    badge.className = 'status-badge';
    badge.textContent = isActive ? 'Aktif' : 'Tidak aktif';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = isActive ? 'secondary' : '';
    button.textContent = isActive ? 'Aktif' : 'Aktifkan';
    button.disabled = isActive;
    button.addEventListener('click', async () => {
      button.disabled = true;
      status.classList.remove('error', 'success');
      status.textContent = `Mengaktifkan ${theme.name}…`;
      try {
        await api('/api/v1/me/theme', { method: 'PATCH', body: JSON.stringify({ slug: theme.slug }) });
        status.classList.add('success');
        status.textContent = `${theme.name} sekarang aktif. Buka halaman profil publik Anda untuk melihatnya.`;
        loadThemes();
      } catch (error) {
        status.classList.add('error');
        status.textContent = error.message;
        button.disabled = isActive;
      }
    });

    card.append(logo, body, badge, button);
    return card;
  };

  const loadThemes = async () => {
    try {
      const result = await api('/api/v1/me/themes');
      const { themes, active_theme: activeSlug } = result.data;
      grid.replaceChildren();
      if (themes.length === 0) {
        grid.innerHTML = '<p class="muted">Tidak ada template terpasang.</p>';
        return;
      }
      themes.forEach((theme) => grid.append(themeCard(theme, activeSlug)));
    } catch (error) {
      grid.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };

  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk mengganti template situs Anda.';
      loginForm.classList.remove('hidden');
      logoutButton.classList.add('hidden');
      content.classList.add('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.add('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.remove('hidden'));
      return;
    }
    try {
      const result = await api('/api/v1/me');
      authSummary.textContent = `Masuk sebagai ${result.data.user.email}.`;
      loginForm.classList.add('hidden');
      logoutButton.classList.remove('hidden');
      content.classList.remove('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.add('hidden'));
      loadThemes();
    } catch (error) {
      sessionStorage.removeItem(tokenKey);
      verify();
    }
  };

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const result = await api('/api/v1/auth/login', {
        method: 'POST',
        body: JSON.stringify({
          email: loginForm.elements.email.value,
          password: loginForm.elements.password.value,
        }),
      });
      sessionStorage.setItem(tokenKey, result.data.token.access_token);
      verify();
    } catch (error) {
      authSummary.textContent = error.message;
    }
  });

  logoutButton.addEventListener('click', async () => {
    try {
      await api('/api/v1/auth/logout', { method: 'POST' });
    } catch {
      // ignore — clear local token regardless
    }
    sessionStorage.removeItem(tokenKey);
    verify();
  });

  verify();
})();
