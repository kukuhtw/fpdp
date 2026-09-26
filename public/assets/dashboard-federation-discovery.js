// Fediverse account discovery on the Federation dashboard: search with a
// profile preview, local suggestions, a server's profile directory, and
// hashtag authors. Every field comes from other servers, so everything is
// rendered with textContent / attribute setters — never innerHTML — and the
// server already reduced remote HTML to plain text and non-http(s) URLs to null.
(() => {
  const tokenKey = 'fpdp_access_token';
  const panel = document.querySelector('#discover-panel');
  if (!panel) return;

  const tabs = panel.querySelectorAll('[data-tab]');
  const panes = panel.querySelectorAll('[data-panel]');
  const statusEl = panel.querySelector('#discover-status');
  const results = panel.querySelector('#discover-results');
  const moreButton = panel.querySelector('#discover-more');
  const searchForm = panel.querySelector('#discover-search-form');
  const directoryForm = panel.querySelector('#discover-directory-form');
  const hashtagForm = panel.querySelector('#discover-hashtag-form');
  const suggestionsRefresh = panel.querySelector('#discover-suggestions-refresh');

  const token = () => sessionStorage.getItem(tokenKey);
  const api = async (path, options = {}) => {
    const headers = { ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Permintaan gagal (${response.status})`);
    return payload;
  };

  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  };
  const number = (value) => (value === null || value === undefined ? '–' : Number(value).toLocaleString('id-ID'));
  const setStatus = (text, kind) => {
    statusEl.classList.remove('error', 'success');
    if (kind) statusEl.classList.add(kind);
    statusEl.textContent = text || '';
  };
  const externalLink = (href, text) => {
    const a = el('a', null, text);
    a.href = href;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    return a;
  };

  const followingLabels = {
    NONE: null,
    PENDING: 'Permintaan follow terkirim (pending)',
    ACCEPTED: 'Sudah Anda ikuti',
    BLOCKED: 'Anda memblokir akun ini',
    REJECTED: 'Permintaan follow sebelumnya ditolak',
    DISCONNECTED: 'Pernah diikuti, sekarang terputus',
  };

  const followButton = (account, alreadyFollowing) => {
    const button = el('button', null, alreadyFollowing ? 'Sudah diikuti' : 'Follow');
    button.type = 'button';
    button.disabled = alreadyFollowing;
    button.addEventListener('click', async () => {
      button.disabled = true;
      button.textContent = 'Mengirim…';
      try {
        const result = await api('/api/v1/federation/send-follow', { method: 'POST', body: JSON.stringify({ account }) });
        button.textContent = result.data.status === 'PENDING' ? 'Pending' : 'Diikuti';
        document.dispatchEvent(new CustomEvent('fpdp:federation-changed'));
      } catch (error) {
        button.disabled = false;
        button.textContent = 'Follow';
        alert(error.message);
      }
    });
    return button;
  };

  // One card layout for every source; fields a source lacks are skipped.
  const accountCard = (account, extra = {}) => {
    const card = el('article', 'panel comment-card');

    const head = el('div', 'field-row');
    if (account.avatar_url) {
      const img = el('img');
      img.src = account.avatar_url;
      img.alt = '';
      img.width = 48;
      img.height = 48;
      img.loading = 'lazy';
      img.referrerPolicy = 'no-referrer';
      img.style.cssText = 'flex:0 0 48px;width:48px;height:48px;border-radius:50%;object-fit:cover';
      head.append(img);
    }
    const names = el('div');
    names.append(el('strong', null, account.display_name || account.address));
    names.append(el('p', 'muted', account.address));
    head.append(names);
    card.append(head);

    const badges = el('p');
    const addBadge = (text, cls) => { badges.append(el('span', `badge ${cls}`, text), ' '); };
    if (extra.reason === 'follows_you' || account.relationship?.followed_by) addBadge('Mengikuti Anda', 'badge-green');
    if (extra.reason === 'posts_seen') addBadge(`${account.post_count} post pernah masuk`, 'badge-yellow');
    if (account.locked) addBadge('Perlu persetujuan', 'badge-yellow');
    if (account.bot || account.type === 'Service') addBadge('Bot', 'badge-yellow');
    if (account.domain_blocked) addBadge('Domain diblokir', 'error-badge');
    if (badges.childNodes.length) card.append(badges);

    if (account.summary) card.append(el('p', null, account.summary));

    const counts = [];
    if ('followers_count' in account) counts.push(`${number(account.followers_count)} followers`);
    if ('following_count' in account) counts.push(`${number(account.following_count)} following`);
    if ('posts_count' in account) counts.push(`${number(account.posts_count)} post`);
    if (counts.length) card.append(el('p', 'muted', counts.join(' · ')));

    const posts = account.recent_posts || (account.sample_post ? [account.sample_post] : []);
    posts.filter((post) => post.content).forEach((post) => {
      const block = el('div', 'latest-post');
      block.append(el('p', 'muted', post.published_at ? new Date(post.published_at).toLocaleString('id-ID') : 'Post'));
      block.append(el('p', null, post.content));
      if (post.url) block.append(externalLink(post.url, 'Buka post asli →'));
      card.append(block);
    });

    const followingState = account.relationship?.following || 'NONE';
    if (followingLabels[followingState]) card.append(el('p', 'muted', followingLabels[followingState]));

    const actions = el('div', 'actions');
    const canFollow = !account.domain_blocked && followingState !== 'BLOCKED';
    if (canFollow) actions.append(followButton(account.actor_uri || account.address, ['ACCEPTED', 'PENDING'].includes(followingState)));
    if (!extra.isPreview && account.address) {
      const preview = el('button', 'secondary', 'Pratinjau');
      preview.type = 'button';
      preview.addEventListener('click', () => lookup(account.actor_uri || account.address));
      actions.append(preview);
    }
    if (account.profile_url) actions.append(externalLink(account.profile_url, 'Profil asli →'));
    card.append(actions);

    return card;
  };

  // ---- Tabs ----
  let activeTab = 'search';
  let loadMore = null;
  const showTab = (name) => {
    activeTab = name;
    tabs.forEach((tab) => {
      const on = tab.dataset.tab === name;
      tab.classList.toggle('active', on);
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panes.forEach((pane) => pane.classList.toggle('hidden', pane.dataset.panel !== name));
    results.replaceChildren();
    setStatus('');
    setMore(null);
    if (name === 'suggestions') loadSuggestions();
  };
  tabs.forEach((tab) => tab.addEventListener('click', () => showTab(tab.dataset.tab)));

  const setMore = (fn) => {
    loadMore = fn;
    moreButton.classList.toggle('hidden', !fn);
  };
  moreButton.addEventListener('click', () => loadMore && loadMore());

  const run = async (message, fn) => {
    setStatus(message);
    try {
      await fn();
    } catch (error) {
      setStatus(error.message, 'error');
    }
  };

  // ---- Search & preview ----
  const lookup = (query) => run('Mencari akun…', async () => {
    if (activeTab !== 'search') {
      showTab('search');
      searchForm.elements.q.value = query;
    }
    const result = await api(`/api/v1/me/federation/discover/lookup?q=${encodeURIComponent(query)}`);
    results.replaceChildren(accountCard(result.data, { isPreview: true }));
    setStatus('');
  });
  searchForm.addEventListener('submit', (event) => {
    event.preventDefault();
    results.replaceChildren();
    lookup(searchForm.elements.q.value.trim());
  });

  // ---- Suggestions ----
  const loadSuggestions = () => run('Memuat saran…', async () => {
    const { data } = await api('/api/v1/me/federation/discover/suggestions');
    results.replaceChildren();
    const section = (title, items, reason) => {
      if (items.length === 0) return;
      results.append(el('h3', null, title));
      items.forEach((item) => results.append(accountCard(item, { reason })));
    };
    section('Follow balik', data.follow_back, 'follows_you');
    section('Pernah muncul di timeline Anda', data.seen_before, 'posts_seen');
    setStatus(data.follow_back.length + data.seen_before.length === 0 ? 'Belum ada saran. Saran muncul setelah ada yang mengikuti Anda atau post federasi masuk ke node ini.' : '');
  });
  suggestionsRefresh.addEventListener('click', loadSuggestions);

  // ---- Server directory ----
  const loadDirectory = (domain, offset) => run(`Memuat direktori ${domain}…`, async () => {
    const { data } = await api(`/api/v1/me/federation/discover/directory?domain=${encodeURIComponent(domain)}&offset=${offset}`);
    if (offset === 0) results.replaceChildren();
    data.accounts.forEach((account) => results.append(accountCard(account)));
    setStatus(offset === 0 && data.accounts.length === 0 ? `Direktori ${data.domain} kosong.` : '');
    setMore(data.next_offset !== null ? () => loadDirectory(data.domain, data.next_offset) : null);
  });
  directoryForm.addEventListener('submit', (event) => {
    event.preventDefault();
    setMore(null);
    loadDirectory(directoryForm.elements.domain.value.trim(), 0);
  });

  // ---- Hashtag ----
  hashtagForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const tag = hashtagForm.elements.tag.value.trim().replace(/^#/, '');
    const domain = hashtagForm.elements.domain.value.trim();
    run(`Mencari #${tag} di ${domain}…`, async () => {
      const { data } = await api(`/api/v1/me/federation/discover/hashtag?domain=${encodeURIComponent(domain)}&tag=${encodeURIComponent(tag)}`);
      results.replaceChildren();
      data.accounts.forEach((account) => results.append(accountCard(account)));
      setStatus(data.accounts.length === 0 ? `Belum ada post publik bertagar #${data.tag} di ${data.domain}.` : '');
    });
  });
})();
