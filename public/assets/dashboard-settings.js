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