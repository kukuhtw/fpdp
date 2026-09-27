// Language settings on the Settings page: which languages the public pages
// offer and which one they open in. Loads once dashboard-settings.js has
// verified the owner's login (fpdp:owner-authenticated).
(() => {
  const tokenKey = 'fpdp_access_token';
  const form = document.querySelector('#language-form');
  if (!form) return;

  const options = form.querySelector('#language-options');
  const defaultSelect = form.querySelector('#default-locale');
  const status = document.querySelector('#language-status');

  const api = async (path, init = {}) => {
    const headers = { ...(init.headers || {}) };
    const token = sessionStorage.getItem(tokenKey);
    if (token) headers.Authorization = `Bearer ${token}`;
    if (init.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...init, headers });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Permintaan gagal (${response.status})`);
    return payload;
  };
  const setStatus = (text, isError = false) => {
    status.textContent = text;
    status.className = `status ${isError ? 'error' : 'success'}`;
  };

  let supported = [];
  const checkedCodes = () => [...options.querySelectorAll('input[type="checkbox"]:checked')].map((box) => box.value);

  // The default must be one of the enabled languages.
  const syncDefaultOptions = (preferred) => {
    const enabled = checkedCodes();
    const keep = enabled.includes(preferred) ? preferred : enabled[0];
    defaultSelect.replaceChildren(...supported.filter((l) => enabled.includes(l.code)).map((l) => {
      const option = document.createElement('option');
      option.value = l.code;
      option.textContent = l.label;
      option.selected = l.code === keep;
      return option;
    }));
  };

  const render = (settings) => {
    supported = settings.supported_locales;
    options.replaceChildren(...supported.map((l) => {
      const label = document.createElement('label');
      label.className = 'check';
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.value = l.code;
      box.checked = settings.enabled_locales.includes(l.code);
      box.addEventListener('change', () => {
        if (checkedCodes().length === 0) box.checked = true; // at least one language stays on
        syncDefaultOptions(defaultSelect.value);
      });
      label.append(box, ` ${l.label}`);
      return label;
    }));
    syncDefaultOptions(settings.default_locale);
  };

  const load = async () => {
    try {
      render((await api('/api/v1/me/locale-settings')).data);
    } catch (error) {
      options.textContent = error.message;
    }
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const { data } = await api('/api/v1/me/locale-settings', {
        method: 'PATCH',
        body: JSON.stringify({ default_locale: defaultSelect.value, enabled_locales: checkedCodes() }),
      });
      render(data);
      setStatus('Bahasa disimpan.');
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      button.disabled = false;
    }
  });

  document.addEventListener('fpdp:owner-authenticated', load);
})();
