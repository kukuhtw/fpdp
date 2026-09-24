(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#coretan-content');
  const list = document.querySelector('#coretan-list');
  const loadMoreButton = document.querySelector('#load-more');
  let handle = null;
  let nextCursor = null;

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

  /** Escape HTML, then turn bare http(s) URLs into clickable links — mirrors App\Core\View::autolink(). */
  const renderText = (text) => {
    const escaped = text.replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    const linked = escaped.replace(/https?:\/\/[^\s<]+/gi, (match) => {
      let url = match;
      let trail = '';
      while (url !== '' && '.,!?:)]}\'"'.includes(url.slice(-1))) {
        trail = url.slice(-1) + trail;
        url = url.slice(0, -1);
      }
      if (url === '') return match;
      return `<a href="${url}" target="_blank" rel="noopener noreferrer nofollow ugc">${url}</a>${trail}`;
    });
    return linked.replace(/\n/g, '<br>');
  };

  const commentCard = (comment) => {
    const card = document.createElement('article');
    card.className = 'panel comment-card';
    card.dataset.commentId = comment.id;

    const meta = document.createElement('p');
    meta.className = 'muted';
    meta.textContent = `${comment.author.display_name || 'Pengunjung'} · ${new Date(comment.created_at).toLocaleString('id-ID')}`;

    const body = document.createElement('div');
    body.innerHTML = renderText(comment.content);

    const replyHost = document.createElement('div');
    replyHost.className = 'comment-reply';
    const renderReply = () => {
      replyHost.innerHTML = '';
      if (comment.admin_reply) {
        const replyMeta = document.createElement('p');
        replyMeta.className = 'muted';
        replyMeta.textContent = 'Balasan admin';
        const replyBody = document.createElement('div');
        replyBody.innerHTML = renderText(comment.admin_reply);
        replyHost.append(replyMeta, replyBody);
      }
    };
    renderReply();

    const replyForm = document.createElement('form');
    replyForm.className = 'field-row';
    replyForm.innerHTML = '<label>Balas coretan ini<textarea rows="2" maxlength="1000"></textarea></label><button type="submit" class="secondary">Kirim balasan</button>';
    replyForm.querySelector('textarea').value = comment.admin_reply || '';
    replyForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const reply = replyForm.querySelector('textarea').value.trim();
      if (!reply) return;
      try {
        const result = await api(`/api/v1/me/wall/comments/${encodeURIComponent(comment.id)}/reply`, {
          method: 'POST',
          body: JSON.stringify({ reply }),
        });
        comment.admin_reply = result.data.admin_reply;
        renderReply();
      } catch (error) {
        alert(error.message);
      }
    });

    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.className = 'secondary';
    deleteButton.textContent = 'Hapus coretan';
    deleteButton.addEventListener('click', async () => {
      if (!confirm('Hapus coretan ini?')) return;
      try {
        await api(`/api/v1/me/wall/comments/${encodeURIComponent(comment.id)}`, { method: 'DELETE' });
        card.remove();
      } catch (error) {
        alert(error.message);
      }
    });

    const actions = document.createElement('div');
    actions.className = 'actions';
    actions.append(deleteButton);

    card.append(meta, body, replyHost, replyForm, actions);
    return card;
  };

  const renderComments = (comments, append) => {
    if (!append) list.replaceChildren();
    if (comments.length === 0 && !append) {
      list.innerHTML = '<p class="muted">Belum ada coretan dari pengunjung.</p>';
      return;
    }
    comments.forEach((comment) => list.append(commentCard(comment)));
  };

  const loadComments = async (append = false) => {
    try {
      const cursorParam = append && nextCursor ? `?cursor=${encodeURIComponent(nextCursor)}` : '';
      const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/wall/comments${cursorParam}`);
      renderComments(result.data, append);
      nextCursor = result.meta.next_cursor;
      loadMoreButton.classList.toggle('hidden', !result.meta.has_more);
    } catch (error) {
      list.innerHTML = `<p class="muted">${error.message}</p>`;
    }
  };

  loadMoreButton.addEventListener('click', () => loadComments(true));

  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk mengelola coretan pengunjung.';
      loginForm.classList.remove('hidden');
      logoutButton.classList.add('hidden');
      content.classList.add('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.add('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.remove('hidden'));
      return;
    }
    try {
      const result = await api('/api/v1/me');
      handle = result.data.profile.handle;
      authSummary.textContent = `Masuk sebagai ${result.data.user.email}.`;
      loginForm.classList.add('hidden');
      logoutButton.classList.remove('hidden');
      content.classList.remove('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.add('hidden'));
      nextCursor = null;
      loadComments(false);
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
