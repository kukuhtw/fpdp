(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#federation-content');

  const followerCount = document.querySelector('#fed-follower-count');
  const followingCount = document.querySelector('#fed-following-count');
  const pendingCount = document.querySelector('#fed-pending-count');
  const capabilitiesEl = document.querySelector('#fed-capabilities');
  const addressInput = document.querySelector('#fed-address');
  const actorUriInput = document.querySelector('#fed-actor-uri');

  const requestsList = document.querySelector('#follow-requests-list');
  const connectionsList = document.querySelector('#connections-list');

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

  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.getElementById(button.dataset.copy);
      if (!target) return;
      try {
        await navigator.clipboard.writeText(target.value);
        const original = button.textContent;
        button.textContent = 'Tersalin!';
        setTimeout(() => { button.textContent = original; }, 1500);
      } catch {
        target.select();
      }
    });
  });

  const relativeTime = (iso) => {
    if (!iso) return '';
    return new Date(iso).toLocaleString('id-ID');
  };

  // ---- Follow requests (approve/reject) ----

  const requestRow = (item) => {
    const row = document.createElement('article');
    row.className = 'panel comment-card';
    row.dataset.followId = item.id;

    const meta = document.createElement('p');
    meta.className = 'muted';
    meta.textContent = `${item.actor.display_name || item.actor.federated_address || item.actor.actor_uri} · diminta ${relativeTime(item.requested_at)}`;

    const address = document.createElement('p');
    address.textContent = item.actor.federated_address || item.actor.actor_uri;

    const actions = document.createElement('div');
    actions.className = 'actions';

    const approveButton = document.createElement('button');
    approveButton.type = 'button';
    approveButton.textContent = 'Approve';
    approveButton.addEventListener('click', async () => {
      approveButton.disabled = true;
      try {
        await api(`/api/v1/me/federation/follow-requests/${encodeURIComponent(item.id)}/approve`, { method: 'POST' });
        row.remove();
        loadSummary();
        maybeShowEmptyRequests();
        loadConnections();
      } catch (error) {
        alert(error.message);
        approveButton.disabled = false;
      }
    });

    const rejectButton = document.createElement('button');
    rejectButton.type = 'button';
    rejectButton.className = 'secondary';
    rejectButton.textContent = 'Reject';
    rejectButton.addEventListener('click', async () => {
      if (!confirm('Tolak permintaan follow ini?')) return;
      rejectButton.disabled = true;
      try {
        await api(`/api/v1/me/federation/follow-requests/${encodeURIComponent(item.id)}/reject`, { method: 'POST' });
        row.remove();
        loadSummary();
        maybeShowEmptyRequests();
      } catch (error) {
        alert(error.message);
        rejectButton.disabled = false;
      }
    });

    actions.append(approveButton, rejectButton);
    row.append(meta, address, actions);
    return row;
  };

  const maybeShowEmptyRequests = () => {
    if (!requestsList.querySelector('[data-follow-id]')) {
      requestsList.innerHTML = '<p class="muted">Tidak ada permintaan follow yang menunggu.</p>';
    }
  };

  const loadFollowRequests = async () => {
    try {
      const result = await api('/api/v1/me/federation/follow-requests');
      const items = result.data.follow_requests;
      requestsList.replaceChildren();
      if (items.length === 0) {
        requestsList.innerHTML = '<p class="muted">Tidak ada permintaan follow yang menunggu.</p>';
        return;
      }
      items.forEach((item) => requestsList.append(requestRow(item)));
    } catch (error) {
      requestsList.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };

  // ---- Connections ----

  const statusBadgeClass = (status) => (status === 'CONNECTED' || status === 'FOLLOWING' ? 'badge-green' : status === 'PENDING' ? 'badge-yellow' : 'error-badge');

  // Remote post content is untrusted HTML from another server — never
  // innerHTML it directly. DOMParser parses it inertly (no image loads,
  // no script execution) so textContent extraction here is safe.
  const stripHtml = (html) => {
    try {
      return new DOMParser().parseFromString(html || '', 'text/html').body.textContent || '';
    } catch {
      return '';
    }
  };

  const latestPostBlock = (post) => {
    if (!post) return null;
    const wrap = document.createElement('div');
    wrap.className = 'latest-post';

    const label = document.createElement('p');
    label.className = 'muted';
    label.textContent = `Postingan terbaru · ${relativeTime(post.published_at)}`;

    const excerptText = stripHtml(post.title || post.content || '');
    const excerpt = document.createElement('p');
    excerpt.textContent = excerptText.length > 220 ? excerptText.slice(0, 220) + '…' : excerptText;

    wrap.append(label, excerpt);

    if (post.canonical_url) {
      const link = document.createElement('a');
      link.href = post.canonical_url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'Buka postingan asli →';
      wrap.append(link);
    }

    return wrap;
  };

  const connectionRow = (conn) => {
    const row = document.createElement('article');
    row.className = 'panel comment-card';

    const meta = document.createElement('p');
    meta.className = 'muted';
    meta.textContent = `${conn.actor.display_name || conn.actor.federated_address} · ${conn.actor.node_domain}`;

    const badge = document.createElement('span');
    badge.className = `badge ${statusBadgeClass(conn.relationship_status)}`;
    badge.textContent = conn.relationship_status;
    meta.append(' ', badge);

    const fieldRow = document.createElement('div');
    fieldRow.className = 'field-row';

    const select = document.createElement('select');
    ['CONNECTED', 'MUTED', 'BLOCKED', 'DISCONNECTED'].forEach((value) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = value;
      option.selected = value === conn.relationship_status;
      select.append(option);
    });

    const applyButton = document.createElement('button');
    applyButton.type = 'button';
    applyButton.className = 'secondary';
    applyButton.textContent = 'Terapkan';
    applyButton.addEventListener('click', async () => {
      applyButton.disabled = true;
      try {
        await api(`/api/v1/me/federated-connections/${encodeURIComponent(conn.id)}`, {
          method: 'PATCH',
          body: JSON.stringify({ relationship_status: select.value }),
        });
        conn.relationship_status = select.value;
        badge.className = `badge ${statusBadgeClass(select.value)}`;
        badge.textContent = select.value;
      } catch (error) {
        alert(error.message);
      } finally {
        applyButton.disabled = false;
      }
    });

    const visibilityLabel = document.createElement('label');
    visibilityLabel.className = 'check';
    const visibilityCheckbox = document.createElement('input');
    visibilityCheckbox.type = 'checkbox';
    visibilityCheckbox.checked = !!conn.show_on_profile;
    visibilityCheckbox.addEventListener('change', async () => {
      try {
        await api(`/api/v1/me/federated-connections/${encodeURIComponent(conn.id)}`, {
          method: 'PATCH',
          body: JSON.stringify({ show_on_profile: visibilityCheckbox.checked }),
        });
      } catch (error) {
        alert(error.message);
        visibilityCheckbox.checked = !visibilityCheckbox.checked;
      }
    });
    visibilityLabel.append(visibilityCheckbox, ' Tampilkan di profil publik');

    fieldRow.append(select, applyButton);
    row.append(meta, visibilityLabel, fieldRow);

    const latestPost = latestPostBlock(conn.latest_post);
    if (latestPost) row.append(latestPost);

    return row;
  };

  const loadConnections = async () => {
    try {
      const result = await api('/api/v1/me/federated-connections');
      const items = result.data.connections;
      connectionsList.replaceChildren();
      if (items.length === 0) {
        connectionsList.innerHTML = '<p class="muted">Belum ada koneksi.</p>';
        return;
      }
      items.forEach((conn) => connectionsList.append(connectionRow(conn)));
    } catch (error) {
      connectionsList.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };

  // ---- Summary ----

  const loadSummary = async () => {
    try {
      const result = await api('/api/v1/me/federation/summary');
      followerCount.textContent = result.data.follower_count;
      followingCount.textContent = result.data.following_count;
      pendingCount.textContent = result.data.pending_follow_requests;
      capabilitiesEl.textContent = result.data.capabilities.join(', ');
    } catch (error) {
      capabilitiesEl.textContent = error.message;
    }
  };

  // Following happens from the discovery panel (dashboard-federation-discovery.js).
  document.addEventListener('fpdp:federation-changed', () => {
    loadSummary();
    loadConnections();
  });

  // ---- Auth ----

  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk mengelola follow, approve, dan reject.';
      loginForm.classList.remove('hidden');
      logoutButton.classList.add('hidden');
      content.classList.add('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.add('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.remove('hidden'));
      return;
    }
    try {
      const result = await api('/api/v1/me');
      const handle = result.data.profile.handle;
      const domain = result.data.node.domain;
      addressInput.value = `@${handle}@${domain}`;
      actorUriInput.value = `https://${domain}/@${handle}`;

      authSummary.textContent = `Masuk sebagai ${result.data.user.email}.`;
      loginForm.classList.add('hidden');
      logoutButton.classList.remove('hidden');
      content.classList.remove('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.add('hidden'));

      loadSummary();
      loadFollowRequests();
      loadConnections();
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
