(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const postForm = document.querySelector('#post-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const status = document.querySelector('#editor-status');
  const savedPost = document.querySelector('#saved-post');

  const mediaFileInput = document.querySelector('#media-file-input');
  const mediaUploadButton = document.querySelector('#media-upload-button');
  const mediaUploadStatus = document.querySelector('#media-upload-status');
  const mediaUrlInput = postForm.elements.media_url;
  const mediaTypeSelect = postForm.elements.media_type;

  const getParam = (name) => {
    const params = new URLSearchParams(window.location.search);
    return params.get(name) || '';
  };
  const loadPost = async (postId) => {
    try {
      const result = await api(`/api/v1/posts/${encodeURIComponent(postId)}`);
      const post = result.data;
      postForm.elements.post_id.value = post.id;
      postForm.elements.title.value = post.title || '';
      postForm.elements.content.value = post.content || '';
      postForm.elements.post_type.value = post.post_type || 'NOTE';
      postForm.elements.visibility.value = post.visibility || 'PUBLIC';
      postForm.elements.publish.checked = post.published_at !== null;
      if ((post.media || []).length > 0) {
        mediaUrlInput.value = post.media[0].url || '';
        mediaTypeSelect.value = post.media[0].type || 'IMAGE';
        postForm.elements.media_alt_text.value = post.media[0].alt_text || '';
      }
      showStatus(`Editing post "${post.title || 'untitled'}".`);
    } catch (error) {
      showStatus(error.message, true);
    }
  };

  const token = () => sessionStorage.getItem(tokenKey);
  const showStatus = (message, error = false) => {
    status.textContent = message;
    status.className = `status ${error ? 'error' : 'success'}`;
  };
  const showMediaStatus = (message, error = false) => {
    mediaUploadStatus.textContent = message;
    mediaUploadStatus.className = `status ${error ? 'error' : 'success'}`;
  };
  const showOwnerNav = () => document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
  const setAuthenticated = (authenticated, label = '') => {
    loginForm.classList.toggle('hidden', authenticated);
    logoutButton.classList.toggle('hidden', !authenticated);
    authSummary.textContent = authenticated ? `Signed in${label ? ` as ${label}` : ''}.` : 'Sign in to create and manage local posts.';
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

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = new FormData(loginForm);
    try {
      const result = await api('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: fields.get('email'), password: fields.get('password') }) });
      sessionStorage.setItem(tokenKey, result.data.token.access_token);
      loginForm.reset();
      if (await verifySession()) showStatus('Signed in successfully.');
      else showStatus('Session verification failed. Please try again.', true);
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
      media: fields.get('media_url') ? [{
        type: fields.get('media_type'),
        url: fields.get('media_url'),
        alt_text: fields.get('media_alt_text') || null,
      }] : [],
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

  const mediaCategoryFor = (mimeType) => {
    if (mimeType.startsWith('image/')) return 'IMAGE';
    if (mimeType.startsWith('video/')) return 'VIDEO';
    if (mimeType.startsWith('audio/')) return 'AUDIO';
    if (mimeType === 'application/pdf') return 'FILE';
    return null;
  };
  const readAsBase64 = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
    reader.onerror = () => reject(new Error('Could not read the selected file.'));
    reader.readAsDataURL(file);
  });

  mediaFileInput.addEventListener('change', () => {
    mediaUploadButton.disabled = mediaFileInput.files.length === 0;
    showMediaStatus('');
  });

  mediaUploadButton.addEventListener('click', async () => {
    if (!token()) return showMediaStatus('Sign in before uploading media.', true);
    const file = mediaFileInput.files[0];
    if (!file) return;

    const guessedType = mediaCategoryFor(file.type);
    const mediaType = guessedType || mediaTypeSelect.value;

    mediaUploadButton.disabled = true;
    showMediaStatus('Uploading…');
    try {
      const contentBase64 = await readAsBase64(file);
      const result = await api('/api/v1/me/media', {
        method: 'POST',
        body: JSON.stringify({ media_type: mediaType, content_base64: contentBase64 }),
      });
      mediaUrlInput.value = result.data.url;
      if (guessedType) mediaTypeSelect.value = guessedType;
      showMediaStatus('Uploaded. URL filled in below — save the post to attach it.');
    } catch (error) {
      showMediaStatus(error.message, true);
    } finally {
      mediaUploadButton.disabled = mediaFileInput.files.length === 0;
    }
  });

  const editId = getParam('edit');
  (async () => {
    if (await verifySession()) {
      if (editId) await loadPost(editId);
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
