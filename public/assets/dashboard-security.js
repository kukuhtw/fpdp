// Account security on the Settings page: active sessions (log a device out,
// or all others) and password change. Loads once dashboard-settings.js has
// verified the owner's login (fpdp:owner-authenticated).
(() => {
  const tokenKey = 'fpdp_access_token';
  const panel = document.querySelector('#security-panel');
  if (!panel) return;

  const list = panel.querySelector('#session-list');
  const sessionStatus = panel.querySelector('#session-status');
  const revokeOthersButton = panel.querySelector('#revoke-others');
  const passwordForm = panel.querySelector('#password-form');
  const passwordStatus = panel.querySelector('#password-status');

  const api = async (path, options = {}) => {
    const headers = { ...(options.headers || {}) };
    const token = sessionStorage.getItem(tokenKey);
    if (token) headers.Authorization = `Bearer ${token}`;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    if (response.status === 204) return null;
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Permintaan gagal (${response.status})`);
    return payload;
  };
  const setStatus = (el, text, isError = false) => {
    el.textContent = text;
    el.className = `status ${isError ? 'error' : 'success'}`;
  };
  const when = (value) => (value ? new Date(value.replace(' ', 'T')).toLocaleString('id-ID') : '–');

  const sessionRow = (session) => {
    const row = document.createElement('article');
    row.className = 'panel comment-card';

    const title = document.createElement('p');
    const strong = document.createElement('strong');
    strong.textContent = session.device;
    title.append(strong);
    if (session.current) {
      const badge = document.createElement('span');
      badge.className = 'badge badge-green';
      badge.textContent = 'Perangkat ini';
      title.append(' ', badge);
    }

    const meta = document.createElement('p');
    meta.className = 'muted';
    meta.textContent = `${session.ip_hint || 'IP tidak diketahui'} · masuk ${when(session.created_at)} · terakhir aktif ${when(session.last_used_at)}`;
    if (session.user_agent) meta.title = session.user_agent;

    row.append(title, meta);
    if (!session.current) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'secondary';
      button.textContent = 'Keluarkan';
      button.addEventListener('click', async () => {
        button.disabled = true;
        try {
          await api(`/api/v1/me/sessions/${encodeURIComponent(session.id)}`, { method: 'DELETE' });
          row.remove();
          setStatus(sessionStatus, `${session.device} sudah dikeluarkan.`);
        } catch (error) {
          button.disabled = false;
          setStatus(sessionStatus, error.message, true);
        }
      });
      row.append(button);
    }
    return row;
  };

  const loadSessions = async () => {
    try {
      const { data } = await api('/api/v1/me/sessions');
      list.replaceChildren(...data.sessions.map(sessionRow));
      revokeOthersButton.disabled = data.sessions.length <= 1;
    } catch (error) {
      list.textContent = error.message;
    }
  };

  revokeOthersButton.addEventListener('click', async () => {
    if (!confirm('Keluarkan semua perangkat lain? Perangkat ini tetap masuk.')) return;
    revokeOthersButton.disabled = true;
    try {
      const { data } = await api('/api/v1/me/sessions/revoke-others', { method: 'POST' });
      setStatus(sessionStatus, `${data.revoked} perangkat lain dikeluarkan.`);
      await loadSessions();
    } catch (error) {
      revokeOthersButton.disabled = false;
      setStatus(sessionStatus, error.message, true);
    }
  });

  passwordForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const { current_password: current, new_password: next, confirm_password: confirmation } = passwordForm.elements;
    if (next.value !== confirmation.value) {
      setStatus(passwordStatus, 'Password baru dan ulangannya tidak sama.', true);
      return;
    }
    const button = passwordForm.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const { data } = await api('/api/v1/me/password', {
        method: 'POST',
        body: JSON.stringify({ current_password: current.value, new_password: next.value }),
      });
      passwordForm.reset();
      setStatus(passwordStatus, `Password diganti. ${data.other_sessions_revoked} perangkat lain dikeluarkan.`);
      await loadSessions();
    } catch (error) {
      setStatus(passwordStatus, error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  document.addEventListener('fpdp:owner-authenticated', loadSessions);
})();
