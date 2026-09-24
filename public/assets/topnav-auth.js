(() => {
  const tokenKey = 'fpdp_access_token';
  const token = sessionStorage.getItem(tokenKey);
  const logoutButton = document.querySelector('#logout-button');

  const setAuthenticated = (authenticated) => {
    document.querySelectorAll('.owner-nav').forEach((el) => el.classList.toggle('hidden', !authenticated));
    document.querySelectorAll('.guest-nav').forEach((el) => el.classList.toggle('hidden', authenticated));
  };

  if (!token) {
    setAuthenticated(false);
  } else {
    fetch('/api/v1/me', { headers: { Authorization: `Bearer ${token}` } })
      .then((response) => {
        if (!response.ok) throw new Error('unauthenticated');
        setAuthenticated(true);
      })
      .catch(() => {
        sessionStorage.removeItem(tokenKey);
        setAuthenticated(false);
      });
  }

  logoutButton?.addEventListener('click', async () => {
    const currentToken = sessionStorage.getItem(tokenKey);
    try {
      await fetch('/api/v1/auth/logout', {
        method: 'POST',
        headers: currentToken ? { Authorization: `Bearer ${currentToken}` } : {},
      });
    } catch (_) {
      // Ignore network errors — the local token is cleared either way below.
    }
    sessionStorage.removeItem(tokenKey);
    window.location.reload();
  });
})();
