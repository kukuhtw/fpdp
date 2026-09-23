(() => {
  const tokenKey = 'fpdp_visitor_token';
  const handle = document.body.dataset.profileHandle;
  const list = document.querySelector('#comment-list');
  const status = document.querySelector('#comment-status');
  const guestBox = document.querySelector('#composer-guest');
  const form = document.querySelector('#comment-form');
  const logoutButton = document.querySelector('#comment-logout');
  const loadMoreButton = document.querySelector('#load-more');
  let nextCursor = null;

  const api = async (path, options = {}) => {
    const headers = { ...(options.headers || {}) };
    const token = sessionStorage.getItem(tokenKey);
    if (token) headers.Authorization = `Bearer ${token}`;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };

  const message = (text, error = false) => {
    status.textContent = text;
    status.className = `status ${error ? 'error' : 'success'}`;
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

    const meta = document.createElement('p');
    meta.className = 'muted';
    meta.textContent = `${comment.author.display_name || 'Pengunjung'} · ${new Date(comment.created_at).toLocaleString('id-ID')}`;

    const body = document.createElement('div');
    body.innerHTML = renderText(comment.content);

    card.append(meta, body);

    if (comment.admin_reply) {
      const reply = document.createElement('div');
      reply.className = 'comment-reply';
      const replyMeta = document.createElement('p');
      replyMeta.className = 'muted';
      replyMeta.textContent = 'Balasan admin';
      const replyBody = document.createElement('div');
      replyBody.innerHTML = renderText(comment.admin_reply);
      reply.append(replyMeta, replyBody);
      card.append(reply);
    }

    return card;
  };

  const renderComments = (comments, append) => {
    if (!append) list.replaceChildren();
    if (comments.length === 0 && !append) {
      list.innerHTML = '<p class="muted">Belum ada coretan. Jadilah yang pertama menulis!</p>';
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
      message(error.message, true);
    }
  };

  const updateAuthUi = () => {
    const signedIn = Boolean(sessionStorage.getItem(tokenKey));
    guestBox.classList.toggle('hidden', signedIn);
    form.classList.toggle('hidden', !signedIn);
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const content = form.elements.content.value.trim();
    if (!content) return;
    try {
      await api(`/api/v1/profiles/${encodeURIComponent(handle)}/wall/comments`, {
        method: 'POST',
        body: JSON.stringify({ content }),
      });
      form.reset();
      message('Coretan terkirim. Terima kasih!');
      nextCursor = null;
      loadComments(false);
    } catch (error) {
      message(error.message, true);
    }
  });

  logoutButton.addEventListener('click', () => {
    sessionStorage.removeItem(tokenKey);
    updateAuthUi();
  });

  loadMoreButton.addEventListener('click', () => loadComments(true));

  updateAuthUi();
  loadComments(false);
})();
