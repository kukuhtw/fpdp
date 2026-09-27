/*
 * Second step of the owner login when two-factor authentication is on.
 *
 * Every dashboard page has its own login form that POSTs to
 * /api/v1/auth/login and expects `data.token.access_token` back. Instead of
 * touching each of them, this wraps window.fetch: when the login answers
 * `mfa_required`, it asks for the code from the authenticator app (or a
 * recovery code), exchanges it at /api/v1/auth/login/verify, and hands the
 * page that response — the same shape as a login without 2FA. Cancelling
 * turns into an ordinary error for the page to show.
 */
(() => {
  const originalFetch = window.fetch.bind(window);
  const t = (key, fallback) => (typeof window.fpdpT === 'function' ? window.fpdpT(key, {}, fallback) : fallback);

  const isLogin = (input, init) => {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    try {
      return method === 'POST' && new URL(url, window.location.href).pathname === '/api/v1/auth/login';
    } catch (_) {
      return false;
    }
  };

  const errorResponse = (message, status) => new Response(
    JSON.stringify({ error: { code: 'MFA_CANCELLED', message } }),
    { status, headers: { 'Content-Type': 'application/json' } },
  );

  const buildDialog = () => {
    const dialog = document.createElement('dialog');
    dialog.className = 'mfa-dialog';
    dialog.setAttribute('aria-labelledby', 'mfa-dialog-title');
    dialog.innerHTML = `
      <form method="dialog" class="mfa-dialog-form">
        <h2 id="mfa-dialog-title"></h2>
        <p class="mfa-dialog-help"></p>
        <label>
          <span class="mfa-dialog-label"></span>
          <input name="code" autocomplete="one-time-code" inputmode="text" spellcheck="false" autocapitalize="off" maxlength="20" required>
        </label>
        <p class="mfa-dialog-error" role="alert" hidden></p>
        <div class="mfa-dialog-actions">
          <button type="button" class="secondary" value="cancel"></button>
          <button type="submit" value="verify"></button>
        </div>
      </form>`;
    dialog.querySelector('h2').textContent = t('mfa.title', 'Two-factor authentication');
    dialog.querySelector('.mfa-dialog-help').textContent = t('mfa.help', 'Enter the 6-digit code from your authenticator app. Lost your phone? Enter one of your recovery codes instead.');
    dialog.querySelector('.mfa-dialog-label').textContent = t('mfa.code_label', 'Code');
    dialog.querySelector('button[value="cancel"]').textContent = t('mfa.cancel', 'Cancel');
    dialog.querySelector('button[value="verify"]').textContent = t('mfa.verify', 'Verify');
    document.body.appendChild(dialog);

    return dialog;
  };

  /** Resolves with the verify Response, or an error Response if cancelled. */
  const askForCode = (mfaToken) => new Promise((resolve) => {
    const dialog = buildDialog();
    const form = dialog.querySelector('form');
    const input = dialog.querySelector('input');
    const error = dialog.querySelector('.mfa-dialog-error');
    const submit = dialog.querySelector('button[value="verify"]');
    let done = false;

    const finish = (response) => {
      if (done) return;
      done = true;
      dialog.close();
      dialog.remove();
      resolve(response);
    };
    const showError = (message) => {
      error.textContent = message;
      error.hidden = false;
      input.select();
    };

    dialog.querySelector('button[value="cancel"]').addEventListener('click', () => finish(errorResponse(t('mfa.cancelled', 'Sign-in cancelled.'), 401)));
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      finish(errorResponse(t('mfa.cancelled', 'Sign-in cancelled.'), 401));
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const code = input.value.trim();
      if (code === '') return;
      submit.disabled = true;
      try {
        const response = await originalFetch('/api/v1/auth/login/verify', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ mfa_token: mfaToken, code }),
        });
        if (response.ok || response.status === 401 || response.status === 429) {
          // Signed in — or the challenge is gone (expired, too many tries,
          // rate limited): either way the page takes it from here.
          finish(response);
          return;
        }
        const payload = await response.json().catch(() => null);
        showError(payload?.error?.message || t('mfa.incorrect', 'That code is not correct.'));
      } catch (_) {
        showError(t('mfa.network', 'Could not reach the server. Try again.'));
      } finally {
        submit.disabled = false;
      }
    });

    dialog.showModal();
    input.focus();
  });

  window.fetch = async (input, init) => {
    const response = await originalFetch(input, init);
    if (!response.ok || !isLogin(input, init)) {
      return response;
    }
    const payload = await response.clone().json().catch(() => null);
    const data = payload && payload.data;
    if (!data || data.mfa_required !== true || typeof data.mfa_token !== 'string') {
      return response;
    }

    return askForCode(data.mfa_token);
  };
})();
