(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form'); const logoutButton = document.querySelector('#logout-button'); const authSummary = document.querySelector('#auth-summary'); const content = document.querySelector('#about-me-content'); const form = document.querySelector('#about-me-form'); const status = document.querySelector('#about-me-status');
  const avatarPreview = document.querySelector('#avatar-preview'); const avatarFileInput = document.querySelector('#avatar-file-input'); const avatarUploadButton = document.querySelector('#avatar-upload-button'); const avatarUploadProgress = document.querySelector('#avatar-upload-progress'); const avatarUploadStatus = document.querySelector('#avatar-upload-status');
  const token = () => sessionStorage.getItem(tokenKey);
  const api = async (path, options = {}) => { const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) }; if (token()) headers.Authorization = `Bearer ${token()}`; const response = await fetch(path, { ...options, headers }); const payload = await response.json(); if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`); return payload; };
  const showAvatarStatus = (text, error = false) => { avatarUploadStatus.textContent = text; avatarUploadStatus.className = `status ${error ? 'error' : 'success'}`; };
  const setAvatarPreview = (url) => { if (url) { avatarPreview.src = url; avatarPreview.classList.remove('hidden'); } else { avatarPreview.removeAttribute('src'); avatarPreview.classList.add('hidden'); } };
  const setAuth = (profile = null) => { const authenticated = profile !== null; loginForm.classList.toggle('hidden', authenticated); logoutButton.classList.toggle('hidden', !authenticated); content.classList.toggle('hidden', !authenticated); authSummary.textContent = authenticated ? `Masuk sebagai @${profile.handle}.` : 'Masuk untuk mengubah konten About Me.'; document.querySelectorAll('.owner-nav').forEach((el) => el.classList.toggle('hidden', !authenticated)); document.querySelectorAll('.guest-nav').forEach((el) => el.classList.toggle('hidden', authenticated)); if (authenticated) { form.elements.display_name.value = profile.display_name || ''; form.elements.bio.value = profile.bio || ''; form.elements.avatar_url.value = profile.avatar_url || ''; setAvatarPreview(profile.avatar_url || null); } };
  const verify = async () => { if (!token()) return setAuth(); try { const result = await api('/api/v1/me'); setAuth(result.data.profile); } catch (_) { sessionStorage.removeItem(tokenKey); setAuth(); } };
  loginForm.addEventListener('submit', async (event) => { event.preventDefault(); const fields = new FormData(loginForm); try { const result = await api('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: fields.get('email'), password: fields.get('password') }) }); sessionStorage.setItem(tokenKey, result.data.token.access_token); loginForm.reset(); await verify(); } catch (error) { status.textContent = error.message; status.className = 'status error'; } });
  form.addEventListener('submit', async (event) => { event.preventDefault(); try { const result = await api('/api/v1/me/profile', { method: 'PATCH', body: JSON.stringify({ display_name: form.elements.display_name.value.trim(), bio: form.elements.bio.value.trim() || null, avatar_url: form.elements.avatar_url.value.trim() || null }) }); setAuth(result.data); status.textContent = 'Konten About Me berhasil disimpan.'; status.className = 'status success'; } catch (error) { status.textContent = error.message; status.className = 'status error'; } });
  logoutButton.addEventListener('click', async () => { try { await api('/api/v1/auth/logout', { method: 'POST' }); } catch (_) {} sessionStorage.removeItem(tokenKey); setAuth(); });

  // ---- Profile photo upload ----
  const readAsBase64 = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
    reader.onerror = () => reject(new Error('Could not read the selected file.'));
    reader.readAsDataURL(file);
  });
  const postJsonWithProgress = (path, body, onProgress) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', path);
    xhr.setRequestHeader('Content-Type', 'application/json');
    if (token()) xhr.setRequestHeader('Authorization', `Bearer ${token()}`);
    xhr.upload.addEventListener('progress', (event) => { if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100)); });
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

  const avatarMaxBytes = Number(avatarFileInput.dataset.maxBytes) || 10485760;
  avatarFileInput.addEventListener('change', () => {
    const file = avatarFileInput.files[0];
    if (file && file.size > avatarMaxBytes) {
      avatarUploadButton.disabled = true;
      showAvatarStatus(`File terlalu besar (${(file.size / 1048576).toFixed(1)} MB). Maks ${(avatarMaxBytes / 1048576).toFixed(0)} MB.`, true);
      return;
    }
    avatarUploadButton.disabled = avatarFileInput.files.length === 0;
    showAvatarStatus('');
  });

  avatarUploadButton.addEventListener('click', async () => {
    if (!token()) return showAvatarStatus('Masuk terlebih dahulu sebelum mengunggah foto.', true);
    const file = avatarFileInput.files[0];
    if (!file) return;
    if (file.size > avatarMaxBytes) {
      return showAvatarStatus(`File terlalu besar (${(file.size / 1048576).toFixed(1)} MB). Maks ${(avatarMaxBytes / 1048576).toFixed(0)} MB.`, true);
    }
    avatarUploadButton.disabled = true;
    avatarUploadProgress.value = 0;
    avatarUploadProgress.classList.remove('hidden');
    showAvatarStatus('Membaca file…');
    try {
      const contentBase64 = await readAsBase64(file);
      showAvatarStatus('Mengunggah… 0%');
      const result = await postJsonWithProgress('/api/v1/me/media', { media_type: 'IMAGE', content_base64: contentBase64 }, (percent) => {
        avatarUploadProgress.value = percent;
        showAvatarStatus(`Mengunggah… ${percent}%`);
      });
      form.elements.avatar_url.value = result.data.url;
      setAvatarPreview(result.data.url);
      showAvatarStatus('Foto terunggah. Klik "Simpan About Me" untuk menerapkannya.');
    } catch (error) {
      showAvatarStatus(error.message, true);
    } finally {
      avatarUploadButton.disabled = avatarFileInput.files.length === 0;
      avatarUploadProgress.classList.add('hidden');
    }
  });

  verify();
})();
