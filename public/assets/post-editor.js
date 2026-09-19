(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const postForm = document.querySelector('#post-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const status = document.querySelector('#editor-status');
  const savedPost = document.querySelector('#saved-post');

  const token = () => sessionStorage.getItem(tokenKey);
  const showStatus = (message, error = false) => {
    status.textContent = message;
    status.className = `status ${error ? 'error' : 'success'}`;
  };
  const setAuthenticated = (authenticated, label = '') => {
    loginForm.classList.toggle('hidden', authenticated);
    logoutButton.classList.toggle('hidden', !authenticated);
    authSummary.textContent = authenticated ? `Signed in${label ? ` as ${label}` : ''}.` : 'Sign in to create and manage local posts.';
  };
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };
  const verifySession = async () => {
    if (!token()) return setAuthenticated(false);
    try {
      const result = await api('/api/v1/me');
      setAuthenticated(true, result.data.profile.handle);
    } catch (_) {
      sessionStorage.removeItem(tokenKey);
      setAuthenticated(false);
    }
  };

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = new FormData(loginForm);
    try {
      const result = await api('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: fields.get('email'), password: fields.get('password') }) });
      sessionStorage.setItem(tokenKey, result.data.token.access_token);
      loginForm.reset();
      await verifySession();
      showStatus('Signed in successfully.');
    } catch (error) { showStatus(error.message, true); }
  });
  logoutButton.addEventListener('click', async () => {
    try { await api('/api/v1/auth/logout', { method: 'POST' }); } catch (_) {}
    sessionStorage.removeItem(tokenKey);
    setAuthenticated(false);
    showStatus('Signed out.');
  });
  postForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!token()) return showStatus('Sign in before saving a post.', true);
    const fields = new FormData(postForm);
    const postId = fields.get('post_id');
    const payload = {
      title: fields.get('title') || null,
      content: fields.get('content'),
      post_type: fields.get('post_type'),
      visibility: fields.get('visibility'),
      published_at: fields.get('publish') ? new Date().toISOString() : null,
    };
    try {
      const result = await api(postId ? `/api/v1/posts/${encodeURIComponent(postId)}` : '/api/v1/posts', { method: postId ? 'PATCH' : 'POST', body: JSON.stringify(payload) });
      postForm.elements.post_id.value = result.data.id;
      savedPost.replaceChildren();
      const message = document.createElement('strong');
      message.textContent = `${postId ? 'Updated' : 'Saved'}.`;
      savedPost.append(message);
      if (result.data.published_at) {
        const link = document.createElement('a');
        link.href = `/posts/${encodeURIComponent(result.data.id)}`;
        link.textContent = ' Open post';
        savedPost.append(link);
      }
      savedPost.classList.remove('hidden');
      showStatus(result.data.published_at ? 'Post published.' : 'Draft saved.');
    } catch (error) { showStatus(error.message, true); }
  });
  document.querySelector('#reset-button').addEventListener('click', () => {
    postForm.reset(); postForm.elements.post_id.value = ''; savedPost.classList.add('hidden'); showStatus('Ready for a new draft.');
  });
  verifySession();
})();
