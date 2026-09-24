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
  const mediaList = document.querySelector('#media-list');
  const mediaRowTemplate = document.querySelector('#media-row-template');
  const MAX_MEDIA_ROWS = 10;

  const addMediaRow = (data = {}) => {
    if (mediaList.children.length >= MAX_MEDIA_ROWS) return null;
    const row = mediaRowTemplate.content.firstElementChild.cloneNode(true);
    row.querySelector('.media-type').value = data.type || 'IMAGE';
    row.querySelector('.media-url').value = data.url || '';
    row.querySelector('.media-alt').value = data.alt_text || '';
    row.querySelector('.remove-media-row').addEventListener('click', () => row.remove());
    mediaList.append(row);
    return row;
  };
  document.querySelector('#add-media-row').addEventListener('click', () => addMediaRow());
  const collectMedia = () => Array.from(mediaList.querySelectorAll('.media-row')).map((row) => ({
    type: row.querySelector('.media-type').value,
    url: row.querySelector('.media-url').value.trim(),
    alt_text: row.querySelector('.media-alt').value.trim() || null,
  })).filter((item) => item.url);

  const editorContent = document.querySelector('#editor-content');
  const postContentInput = document.querySelector('#post-content');
  const toolbar = document.querySelector('.editor-toolbar');

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
      editorContent.innerHTML = post.content || '';
      postForm.elements.post_type.value = post.post_type || 'NOTE';
      postForm.elements.visibility.value = post.visibility || 'PUBLIC';
      postForm.elements.publish.checked = post.published_at !== null;
      mediaList.replaceChildren();
      (post.media && post.media.length > 0 ? post.media : [{}]).forEach((item) => addMediaRow(item));
      showStatus(`Editing post "${post.title || 'untitled'}".`);
    } catch (error) {
      showStatus(error.message, true);
    }
  };
  const syncContent = () => { postContentInput.value = editorContent.innerHTML; };
  const execFormat = (cmd, value) => {
    document.execCommand(cmd, false, value || null);
    syncContent();
    editorContent.focus();
  };

  /** Turn a YouTube / TikTok / Instagram URL into a safe embeddable iframe. */
  const buildVideoEmbed = (url) => {
    const youtube = url.match(/(?:youtube\.com\/(?:watch\?v=|shorts\/)|youtu\.be\/)([\w-]+)/);
    if (youtube) {
      return `<iframe src="https://www.youtube-nocookie.com/embed/${youtube[1]}" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>`;
    }
    const tiktok = url.match(/tiktok\.com\/@[\w.-]+\/video\/(\d+)/);
    if (tiktok) {
      return `<iframe src="https://www.tiktok.com/embed/v2/${tiktok[1]}" frameborder="0" allow="encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
    }
    const instagram = url.match(/instagram\.com\/(p|reel|tv)\/([\w-]+)/);
    if (instagram) {
      return `<iframe src="https://www.instagram.com/${instagram[1]}/${instagram[2]}/embed" frameborder="0" allowfullscreen></iframe>`;
    }
    const safeUrl = url.replace(/"/g, '&quot;');
    return `<iframe src="${safeUrl}" frameborder="0" allowfullscreen></iframe>`;
  };

  /* ── Toolbar buttons ── */
  toolbar.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-cmd]');
    if (!btn) return;
    const cmd = btn.dataset.cmd;
    if (cmd === 'createLink') {
      const url = prompt('Enter link URL:', 'https://');
      if (url) execFormat('createLink', url);
    } else if (cmd === 'insertImage') {
      const url = prompt('Enter image URL:', 'https://');
      if (url) execFormat('insertImage', url);
    } else if (cmd === 'insertVideo') {
      const url = prompt('Paste a YouTube, TikTok, or Instagram post/reel URL:', 'https://');
      if (!url) return;
      document.execCommand('insertHTML', false, buildVideoEmbed(url));
      syncContent();
    } else if (cmd === 'h1' || cmd === 'h2' || cmd === 'h3') {
      document.execCommand('formatBlock', false, cmd.replace('h', 'H'));
      syncContent();
    } else if (cmd === 'pre') {
      document.execCommand('formatBlock', false, 'PRE');
      syncContent();
    } else {
      execFormat(cmd);
    }
  });

  /* ── Keep hidden input in sync ── */
  editorContent.addEventListener('input', syncContent);
  editorContent.addEventListener('paste', (e) => {
    e.preventDefault();
    const text = e.clipboardData.getData('text/plain');
    const html = e.clipboardData.getData('text/html');
    if (html) {
      // Strip Word/external HTML mess — keep only basic formatting
      const cleaned = html
        .replace(/<meta[^>]*>/gi, '')
        .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/<xml[^>]*>[\s\S]*?<\/xml>/gi, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<o:[^>]*>[\s\S]*?<\/o:[^>]*>/gi, '')
        .replace(/class=["'][^"']*["']/gi, '')
        .replace(/style=["'][^"']*["']/gi, '')
        .replace(/<span[^>]*>/gi, '')
        .replace(/<\/span>/gi, '')
        .replace(/<font[^>]*>/gi, '')
        .replace(/<\/font>/gi, '')
        .trim();
      if (cleaned) {
        document.execCommand('insertHTML', false, cleaned);
      } else {
        document.execCommand('insertText', false, text);
      }
    } else if (text) {
      document.execCommand('insertText', false, text);
    }
    syncContent();
  });

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
    document.querySelectorAll('.guest-nav').forEach((el) => el.classList.toggle('hidden', authenticated));
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
  /**
   * fetch() has no way to report upload progress, so a real progress bar
   * needs XMLHttpRequest instead — same request shape as api(), just with
   * xhr.upload.onprogress wired to onProgress(percent).
   */
  const postJsonWithProgress = (path, body, onProgress) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', path);
    xhr.setRequestHeader('Content-Type', 'application/json');
    if (token()) xhr.setRequestHeader('Authorization', `Bearer ${token()}`);
    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
    });
    xhr.addEventListener('load', () => {
      let payload = null;
      let parseFailed = false;
      try { payload = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch (_) { parseFailed = true; }
      if (xhr.status >= 200 && xhr.status < 300 && !parseFailed) resolve(payload);
      else if (parseFailed) reject(new Error(`The server returned an invalid response (HTTP ${xhr.status}). Check the server's error log — a stray warning before the JSON output is the usual cause.`));
      else reject(new Error(payload?.error?.message || `Request failed (${xhr.status})`));
    });
    xhr.addEventListener('error', () => reject(new Error('Network error while uploading.')));
    xhr.send(JSON.stringify(body));
  });

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
    syncContent();
    const fields = new FormData(postForm);
    const postId = fields.get('post_id');
    const payload = {
      title: fields.get('title') || null,
      content: fields.get('content'),
      post_type: fields.get('post_type'),
      visibility: fields.get('visibility'),
      published_at: fields.get('publish') ? new Date().toISOString() : null,
      media: collectMedia(),
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
        link.href = result.data.slug_url || `/posts/${encodeURIComponent(result.data.id)}`;
        link.textContent = ' Open post';
        savedPost.append(link);
      }
      savedPost.classList.remove('hidden');
      showStatus(result.data.published_at ? 'Post published.' : 'Draft saved.');
    } catch (error) { showStatus(error.message, true); }
  });
  document.querySelector('#reset-button').addEventListener('click', () => {
    postForm.reset(); postForm.elements.post_id.value = ''; editorContent.innerHTML = ''; postContentInput.value = ''; savedPost.classList.add('hidden');
    mediaList.replaceChildren(); addMediaRow();
    showStatus('Ready for a new draft.');
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

  const mediaMaxBytes = Number(mediaFileInput.dataset.maxBytes) || 10485760;

  mediaFileInput.addEventListener('change', () => {
    const file = mediaFileInput.files[0];
    if (file && file.size > mediaMaxBytes) {
      mediaUploadButton.disabled = true;
      showMediaStatus(`File is too large (${(file.size / 1048576).toFixed(1)} MB). Max ${(mediaMaxBytes / 1048576).toFixed(0)} MB.`, true);
      return;
    }
    mediaUploadButton.disabled = mediaFileInput.files.length === 0;
    showMediaStatus('');
  });

  const mediaUploadProgress = document.querySelector('#media-upload-progress');

  mediaUploadButton.addEventListener('click', async () => {
    if (!token()) return showMediaStatus('Sign in before uploading media.', true);
    const file = mediaFileInput.files[0];
    if (!file) return;
    if (file.size > mediaMaxBytes) {
      return showMediaStatus(`File is too large (${(file.size / 1048576).toFixed(1)} MB). Max ${(mediaMaxBytes / 1048576).toFixed(0)} MB.`, true);
    }

    const guessedType = mediaCategoryFor(file.type);
    const mediaType = guessedType || 'IMAGE';

    mediaUploadButton.disabled = true;
    mediaUploadProgress.value = 0;
    mediaUploadProgress.classList.remove('hidden');
    showMediaStatus('Reading file…');
    try {
      const contentBase64 = await readAsBase64(file);
      showMediaStatus('Uploading… 0%');
      const result = await postJsonWithProgress('/api/v1/me/media', { media_type: mediaType, content_base64: contentBase64 }, (percent) => {
        mediaUploadProgress.value = percent;
        showMediaStatus(`Uploading… ${percent}%`);
      });
      const emptyRow = Array.from(mediaList.querySelectorAll('.media-row')).find((row) => !row.querySelector('.media-url').value.trim());
      const targetRow = emptyRow || addMediaRow();
      if (targetRow) {
        targetRow.querySelector('.media-url').value = result.data.url;
        targetRow.querySelector('.media-type').value = mediaType;
      }
      showMediaStatus('Uploaded. URL added below — save the post to attach it.');
    } catch (error) {
      showMediaStatus(error.message, true);
    } finally {
      mediaUploadButton.disabled = mediaFileInput.files.length === 0;
      mediaUploadProgress.classList.add('hidden');
    }
  });

  addMediaRow();

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
