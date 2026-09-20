(() => {
  const tokenKey = 'fpdp_access_token';

  const loginForm = document.querySelector('#login-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const status = document.querySelector('#settings-status');
  const settingsContent = document.querySelector('#settings-content');
  const gatewayList = document.querySelector('#gateway-list');
  const gatewayForm = document.querySelector('#gateway-form');
  const gatewayFields = document.querySelector('#gateway-fields');
  const configSubtitle = document.querySelector('#config-subtitle');

  const token = () => sessionStorage.getItem(tokenKey);
  const showStatus = (message, error = false) => {
    status.textContent = message;
    status.className = `status ${error ? 'error' : 'success'}`;
  };
  const showOwnerNav = () => document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
  const setAuthenticated = (authenticated, label = '') => {
    loginForm.classList.toggle('hidden', authenticated);
    logoutButton.classList.toggle('hidden', !authenticated);
    settingsContent.classList.toggle('hidden', !authenticated);
    authSummary.textContent = authenticated ? `Signed in${label ? ` as ${label}` : ''}.` : 'Sign in to manage settings.';
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

  const envLabels = { SANDBOX: 'Sandbox', LIVE: 'Live' };
  const fieldLabels = {
    api_key: 'API Key',
    api_url: 'API URL',
    server_key: 'Server Key',
    client_id: 'Client ID',
    client_secret: 'Client Secret',
    webhook_id: 'Webhook ID',
  };
  let activeGateway = null;

  const renderGatewayList = (data) => {
    activeGateway = data.active_gateway;
    const gateways = data.gateways || [];
    gatewayList.replaceChildren();

    // Show active gateway badge
    if (activeGateway) {
      const badge = document.createElement('p');
      badge.className = 'status success';
      badge.textContent = 'Active gateway: ' + activeGateway;
      gatewayList.append(badge);
    } else {
      const badge = document.createElement('p');
      badge.className = 'muted';
      badge.textContent = 'No gateway is currently set as active for checkout.';
      gatewayList.append(badge);
    }

    gateways.forEach((gw) => {
      const card = document.createElement('article');
      card.className = 'gateway-card';
      const envInfo = gw.environments.map((e) =>
        `${envLabels[e.environment] || e.environment}: ${e.configured_keys.length > 0 ? '✅ Configured' : '❌ Not configured'}`
      ).join(' | ') || 'Not configured';

      const isActive = activeGateway === gw.code;

      card.innerHTML = `
        <div class="gateway-card-head">
          <strong>${gw.name}</strong> <span class="muted">(${gw.code})</span>
          ${isActive ? '<span class="status-tag" style="background:#e3efe9;color:#185f48">ACTIVE</span>' : ''}
        </div>
        <p class="muted">${envInfo}</p>
        ${gw.webhook_url ? `<p class="small"><strong>Webhook URL:</strong> <code style="font-size:.8rem;word-break:break-all">${gw.webhook_url}</code></p>` : ''}
        <div class="gateway-actions" style="display:flex;gap:.5rem;margin-top:.5rem">
          <button class="button secondary small configure-btn" data-code="${gw.code}" data-keys='${JSON.stringify(gw.allowed_config_keys)}'>Configure</button>
          ${!isActive ? `<button class="button small activate-btn" data-code="${gw.code}">Set Active</button>` : ''}
        </div>
      `;
      gatewayList.append(card);
    });
  };

  const loadGateways = async () => {
    try {
      const result = await api('/api/v1/me/payment-gateways');
      renderGatewayList(result.data);
    } catch (error) {
      showStatus(error.message, true);
    }
  };

  const buildFormFields = (keys) => {
    gatewayFields.replaceChildren();
    if (keys.length === 0) {
      const p = document.createElement('p');
      p.className = 'muted';
      p.textContent = 'This gateway has no configuration fields needed.';
      gatewayFields.append(p);
      return;
    }
    keys.forEach((key) => {
      const label = document.createElement('label');
      label.textContent = fieldLabels[key] || key;
      const input = document.createElement('input');
      input.name = key;
      input.type = key.includes('secret') || key === 'api_key' || key === 'server_key' ? 'password' : 'text';
      input.value = '';
      input.placeholder = 'Enter ' + (fieldLabels[key] || key);
      label.append(input);
      gatewayFields.append(label);
    });
  };

  gatewayList.addEventListener('click', (e) => {
    const btn = e.target.closest('.configure-btn');
    if (!btn) return;
    const code = btn.dataset.code;
    const keys = JSON.parse(btn.dataset.keys || '[]');
    gatewayForm.elements.code.value = code;
    configSubtitle.textContent = 'Configuring: ' + code;
    buildFormFields(keys);
    gatewayForm.classList.remove('hidden');
    showStatus('');
  });

  gatewayForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = gatewayForm.elements.code.value;
    const environment = gatewayForm.elements.environment.value;
    const config = {};
    const inputs = gatewayFields.querySelectorAll('input[name]');
    let hasValue = false;
    inputs.forEach((input) => {
      if (input.value) {
        config[input.name] = input.value;
        hasValue = true;
      }
    });
    if (!hasValue) {
      showStatus('Fill in at least one field.', true);
      return;
    }
    try {
      await api('/api/v1/me/payment-gateways/' + encodeURIComponent(code), {
        method: 'PATCH',
        body: JSON.stringify({ environment, config }),
      });
      showStatus(code + ' configuration saved.');
      await loadGateways();
    } catch (error) {
      showStatus(error.message, true);
    }
  });

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = new FormData(loginForm);
    try {
      const result = await api('/api/v1/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email: fields.get('email'), password: fields.get('password') }),
      });
      sessionStorage.setItem(tokenKey, result.data.token.access_token);
      loginForm.reset();
      if (await verifySession()) {
        showStatus('Signed in successfully.');
        loadGateways();
      } else {
        showStatus('Session verification failed. Please try again.', true);
      }
    } catch (error) { showStatus(error.message, true); }
  });

  logoutButton.addEventListener('click', async () => {
    try { await api('/api/v1/auth/logout', { method: 'POST' }); } catch (_) { }
    sessionStorage.removeItem(tokenKey);
    setAuthenticated(false);
    gatewayForm.classList.add('hidden');
    gatewayList.replaceChildren();
    showStatus('Signed out.');
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

  (async () => {
    if (await verifySession()) {
      await loadGateways();
    }
  })();
})();

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