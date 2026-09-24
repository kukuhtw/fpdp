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
  const llmForm = document.querySelector('#llm-form');
  const llmStatus = document.querySelector('#llm-status');
  const llmKeyHint = document.querySelector('#llm-key-hint');

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
        `${envLabels[e.environment] || e.environment}: ${gw.allowed_config_keys.every((key) => e.configured_keys.includes(key)) ? '✅ Configured' : '❌ Incomplete'}`
      ).join(' | ') || 'Not configured';

      const isActive = activeGateway === gw.code;
      const activeEnv = (gw.environments.find((e) => e.is_active) || {}).environment || 'SANDBOX';

      card.innerHTML = `
        <div class="gateway-card-head">
          <strong>${gw.name}</strong> <span class="muted">(${gw.code})</span>
          ${gw.is_plugin ? '<span class="status-tag" style="background:#eef2ff;color:#4338ca">PLUGIN</span>' : ''}
          ${isActive ? '<span class="status-tag" style="background:#e3efe9;color:#185f48">ACTIVE</span>' : ''}
        </div>
        <p class="muted">${envInfo}</p>
        ${gw.webhook_url ? `<p class="small"><strong>Webhook URL:</strong> <code style="font-size:.8rem;word-break:break-all">${gw.webhook_url}</code></p>` : ''}
        <div class="gateway-actions" style="display:flex;gap:.5rem;margin-top:.5rem">
          <button class="button secondary small configure-btn" data-code="${gw.code}" data-keys='${JSON.stringify(gw.allowed_config_keys)}' data-active-env="${activeEnv}">Configure</button>
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

  gatewayList.addEventListener('click', async (e) => {
    const btn = e.target.closest('.configure-btn');
    if (btn) {
      const code = btn.dataset.code;
      const keys = JSON.parse(btn.dataset.keys || '[]');
      const activeEnv = btn.dataset.activeEnv || 'SANDBOX';
      gatewayForm.elements.code.value = code;
      gatewayForm.elements.environment.value = activeEnv;
      configSubtitle.textContent = `Configuring: ${code} (${envLabels[activeEnv] || activeEnv})`;
      buildFormFields(keys);
      gatewayForm.classList.remove('hidden');
      showStatus('');
      return;
    }
    const activateBtn = e.target.closest('.activate-btn');
    if (activateBtn) {
      const code = activateBtn.dataset.code;
      try {
        await api('/api/v1/me/payment-gateways/' + encodeURIComponent(code) + '/activate', { method: 'PUT' });
        showStatus(code + ' is now the active payment gateway.');
        await loadGateways();
      } catch (error) {
        showStatus(error.message, true);
      }
    }
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
    if (!hasValue || Object.keys(config).length !== inputs.length) {
      showStatus('Fill in all required fields. Existing secrets are hidden, so enter the complete credential set when saving.', true);
      return;
    }
    try {
      const result = await api('/api/v1/me/payment-gateways/' + encodeURIComponent(code), {
        method: 'PATCH',
        body: JSON.stringify({ environment, config }),
      });
      showStatus(`${code} (${envLabels[environment] || environment}) provider check completed and configuration saved.`);
      await loadGateways();
    } catch (error) {
      showStatus(error.message, true);
    }
  });

  const showLlmStatus = (message, error = false) => {
    llmStatus.textContent = message;
    llmStatus.className = `status ${error ? 'error' : 'success'}`;
  };

  const loadLlmSettings = async () => {
    try {
      const result = await api('/api/v1/me/llm-config');
      const data = result.data;
      if (data.provider_code) llmForm.elements.provider_code.value = data.provider_code;
      llmForm.elements.model.value = data.model || '';
      llmForm.elements.supports_vision.checked = !!data.supports_vision;
      if (data.key_configured) {
        llmKeyHint.textContent = `Current key: ${data.key_hint}`;
        llmKeyHint.classList.remove('hidden');
      } else {
        llmKeyHint.classList.add('hidden');
      }
    } catch (error) {
      showLlmStatus(error.message, true);
    }
  };

  llmForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const apiKey = llmForm.elements.api_key.value;
    if (!apiKey) {
      showLlmStatus('Enter the API key to save (it is never shown back in full, so it must be re-entered to change it).', true);
      return;
    }
    try {
      await api('/api/v1/me/llm-config', {
        method: 'PATCH',
        body: JSON.stringify({
          provider_code: llmForm.elements.provider_code.value,
          model: llmForm.elements.model.value.trim(),
          api_key: apiKey,
          supports_vision: llmForm.elements.supports_vision.checked,
        }),
      });
      llmForm.elements.api_key.value = '';
      showLlmStatus('LLM settings saved.');
      await loadLlmSettings();
    } catch (error) {
      showLlmStatus(error.message, true);
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
        loadLlmSettings();
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
      await loadLlmSettings();
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
