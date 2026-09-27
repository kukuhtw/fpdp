// Two-factor authentication on the Settings page: turn it on (QR code +
// manual key, confirm with a first code, recovery codes shown once), make new
// recovery codes, or turn it off. The QR code is drawn here in the browser
// (vendor/qrcode-generator), so the secret never goes to a third party.
// Loads once dashboard-settings.js has verified the owner's login.
(() => {
  const tokenKey = 'fpdp_access_token';
  const panel = document.querySelector('#twofa-panel');
  if (!panel) return;

  const $ = (selector) => panel.querySelector(selector);
  const summary = $('#twofa-summary');
  const sections = { off: $('#twofa-off'), setup: $('#twofa-setup'), codes: $('#twofa-codes'), on: $('#twofa-on') };
  const status = $('#twofa-status');
  const confirmForm = $('#twofa-confirm-form');
  const regenerateForm = $('#twofa-regenerate-form');
  const disableForm = $('#twofa-disable-form');
  let shownCodes = [];

  const api = async (path, options = {}) => {
    const headers = { ...(options.headers || {}) };
    const token = sessionStorage.getItem(tokenKey);
    if (token) headers.Authorization = `Bearer ${token}`;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Permintaan gagal (${response.status})`);
    return payload.data;
  };
  const setStatus = (text, isError = false) => {
    status.textContent = text;
    status.className = `status ${text === '' ? '' : isError ? 'error' : 'success'}`;
  };
  const show = (name) => {
    Object.entries(sections).forEach(([key, el]) => el.classList.toggle('hidden', key !== name));
  };
  const busy = async (form, work) => {
    const buttons = form.querySelectorAll('button');
    buttons.forEach((b) => { b.disabled = true; });
    try {
      await work();
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      buttons.forEach((b) => { b.disabled = false; });
    }
  };

  const renderStatus = (data) => {
    if (data.enabled) {
      const since = data.enabled_at ? new Date(data.enabled_at.replace(' ', 'T')).toLocaleDateString('id-ID') : '';
      summary.textContent = `2FA aktif${since ? ` sejak ${since}` : ''}. Sisa kode pemulihan: ${data.recovery_codes_remaining} dari 10.`;
      summary.className = data.recovery_codes_remaining <= 2 ? 'status error' : 'muted';
      show('on');
    } else {
      summary.textContent = '2FA belum aktif.';
      summary.className = 'muted';
      show('off');
    }
  };
  const load = async () => {
    try {
      renderStatus(await api('/api/v1/me/2fa'));
    } catch (error) {
      summary.textContent = error.message;
    }
  };

  const drawQr = (uri) => {
    const box = $('#twofa-qr');
    box.replaceChildren();
    if (typeof window.qrcode !== 'function') {
      box.textContent = 'Kode QR tidak bisa dimuat; gunakan kunci manual di bawah.';
      return;
    }
    const qr = window.qrcode(0, 'M');
    qr.addData(uri);
    qr.make();
    // createSvgTag builds markup from the matrix only (no user text in it).
    box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
  };

  const showCodes = (codes) => {
    shownCodes = codes;
    const list = $('#twofa-code-list');
    list.replaceChildren(...codes.map((code) => {
      const li = document.createElement('li');
      li.textContent = code;
      return li;
    }));
    summary.textContent = '';
    show('codes');
  };

  $('#twofa-start').addEventListener('click', async () => {
    setStatus('');
    try {
      const data = await api('/api/v1/me/2fa/setup', { method: 'POST' });
      drawQr(data.otpauth_uri);
      $('#twofa-secret').textContent = data.secret.replace(/(.{4})/g, '$1 ').trim();
      confirmForm.reset();
      show('setup');
      confirmForm.elements.code.focus();
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  $('#twofa-setup-cancel').addEventListener('click', () => {
    $('#twofa-qr').replaceChildren();
    $('#twofa-secret').textContent = '';
    setStatus('');
    load();
  });

  confirmForm.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(confirmForm, async () => {
      const data = await api('/api/v1/me/2fa/confirm', { method: 'POST', body: JSON.stringify({ code: confirmForm.elements.code.value }) });
      $('#twofa-qr').replaceChildren();
      $('#twofa-secret').textContent = '';
      showCodes(data.recovery_codes);
      setStatus(`2FA aktif. ${data.other_sessions_revoked} perangkat lain dikeluarkan.`);
    });
  });

  $('#twofa-copy').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(shownCodes.join('\n'));
      setStatus('Kode pemulihan disalin.');
    } catch (_) {
      setStatus('Tidak bisa menyalin otomatis; salin manual dari daftar.', true);
    }
  });

  $('#twofa-download').addEventListener('click', () => {
    const text = `Kode pemulihan 2FA — ${window.location.host}\nSetiap kode hanya bisa dipakai satu kali.\n\n${shownCodes.join('\n')}\n`;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    link.download = `fpdp-recovery-codes-${window.location.host}.txt`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  });

  $('#twofa-codes-done').addEventListener('click', () => {
    shownCodes = [];
    $('#twofa-code-list').replaceChildren();
    setStatus('');
    load();
  });

  regenerateForm.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(regenerateForm, async () => {
      const data = await api('/api/v1/me/2fa/recovery-codes', { method: 'POST', body: JSON.stringify({ code: regenerateForm.elements.code.value }) });
      regenerateForm.reset();
      showCodes(data.recovery_codes);
      setStatus('Kode pemulihan baru dibuat; kode lama tidak berlaku lagi.');
    });
  });

  disableForm.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!confirm('Nonaktifkan 2FA? Login akan kembali hanya memakai password.')) return;
    busy(disableForm, async () => {
      const data = await api('/api/v1/me/2fa/disable', {
        method: 'POST',
        body: JSON.stringify({ password: disableForm.elements.password.value, code: disableForm.elements.code.value }),
      });
      disableForm.reset();
      renderStatus(data);
      setStatus('2FA dinonaktifkan.');
    });
  });

  document.addEventListener('fpdp:owner-authenticated', load);
})();
