(() => {
  const tokenKey = 'fpdp_visitor_token';
  const handle = document.body.dataset.profileHandle;
  const widget = document.querySelector('#chatbot-widget');
  if (!handle || !widget) return;

  const priceNote = document.querySelector('#chatbot-price-note');
  const signinBox = document.querySelector('#chatbot-signin');
  const googleLogin = document.querySelector('#chatbot-google-login');
  const chatBox = document.querySelector('#chatbot-chat');
  const walletNote = document.querySelector('#chatbot-wallet-note');
  const topupAmount = document.querySelector('#chatbot-topup-amount');
  const topupButton = document.querySelector('#chatbot-topup-button');
  const log = document.querySelector('#chatbot-log');
  const askForm = document.querySelector('#chatbot-ask-form');
  const questionInput = document.querySelector('#chatbot-question-input');
  const status = document.querySelector('#chatbot-status');

  let currency = 'IDR';
  let lastKnownBalance = 0;

  const api = async (path, options = {}) => {
    const headers = { ...(options.headers || {}) };
    const token = sessionStorage.getItem(tokenKey);
    if (token) headers.Authorization = `Bearer ${token}`;
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) {
      const error = new Error(payload?.error?.message || `Request failed (${response.status})`);
      error.status = response.status;
      throw error;
    }
    return payload;
  };

  const message = (text, error = false) => {
    status.textContent = text;
    status.className = `status ${error ? 'error' : 'success'}`;
  };

  const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  const formatMoney = (amount) => `${currency} ${Number(amount).toLocaleString('id-ID')}`;

  const appendLog = (question, answer) => {
    const q = document.createElement('p');
    q.innerHTML = `<strong>Anda:</strong> ${escapeHtml(question)}`;
    const a = document.createElement('p');
    a.innerHTML = `<strong>AI (balasan otomatis):</strong> ${escapeHtml(answer)}`;
    log.append(q, a);
    log.scrollTop = log.scrollHeight;
  };

  const refreshWallet = async () => {
    try {
      const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/wallet`);
      lastKnownBalance = Number(result.data.balance_amount) || 0;
      walletNote.textContent = `Saldo Anda: ${formatMoney(result.data.balance_amount)}`;
    } catch (_) {
      // Ignore — the ask/top-up flows surface their own errors.
    }
  };

  const showConfirmLink = () => {
    const el = document.querySelector('#chatbot-payment-confirm-link');
    if (!el) return;
    el.innerHTML = '';
    const link = document.createElement('a');
    link.className = 'button secondary';
    link.href = `/payment/thank-you?type=wallet&handle=${encodeURIComponent(handle)}&before=${encodeURIComponent(lastKnownBalance)}`;
    link.textContent = 'Sudah transfer? Cek status pembayaran →';
    el.append(link);
  };

  const signOut = () => {
    sessionStorage.removeItem(tokenKey);
    signinBox.classList.remove('hidden');
    chatBox.classList.add('hidden');
  };

  const updateSignedInUi = async () => {
    const signedIn = Boolean(sessionStorage.getItem(tokenKey));
    signinBox.classList.toggle('hidden', signedIn);
    chatBox.classList.toggle('hidden', !signedIn);
    if (signedIn) await refreshWallet();
  };

  topupButton.addEventListener('click', async () => {
    const amount = Number(topupAmount.value);
    if (!amount || amount < 1000) return message('Minimal top up Rp 1.000.', true);
    topupButton.disabled = true;
    message('Memproses top up…');
    try {
      const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/wallet/topup`, {
        method: 'POST',
        body: JSON.stringify({ amount }),
      });
      if (result.data.payment?.payment_url) {
        message('Mengalihkan ke halaman pembayaran…');
        location.href = result.data.payment.payment_url;
        return;
      }
      message(result.data.payment?.instructions || 'Top up berhasil.');
      if (result.data.payment?.instructions) showConfirmLink();
      await refreshWallet();
    } catch (error) {
      if (error.status === 401) { signOut(); message('Sesi berakhir, silakan masuk lagi.', true); return; }
      message(error.message, true);
    } finally {
      topupButton.disabled = false;
    }
  });

  askForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const question = questionInput.value.trim();
    if (!question) return;
    const submitButton = askForm.querySelector('button');
    submitButton.disabled = true;
    message('Mengirim pertanyaan…');
    try {
      const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/chatbot/messages`, {
        method: 'POST',
        body: JSON.stringify({ question }),
      });
      appendLog(result.data.question, result.data.answer);
      questionInput.value = '';
      walletNote.textContent = `Saldo Anda: ${formatMoney(result.data.wallet_balance)}`;
      message('');
    } catch (error) {
      if (error.status === 402) { message('Saldo tidak cukup. Silakan top up terlebih dahulu.', true); }
      else if (error.status === 401) { signOut(); message('Sesi berakhir, silakan masuk lagi.', true); }
      else { message(error.message, true); }
    } finally {
      submitButton.disabled = false;
    }
  });

  const init = async () => {
    try {
      const result = await api(`/api/v1/profiles/${encodeURIComponent(handle)}/chatbot/settings`);
      if (!result.data.enabled) return;
      currency = result.data.currency;
      const price = Number(result.data.price_per_question);
      priceNote.textContent = price > 0 ? `${formatMoney(price)} per pertanyaan.` : 'Gratis untuk bertanya.';
      googleLogin.href = `/api/v1/profiles/${encodeURIComponent(handle)}/visitor-auth/google/redirect?return_to=${encodeURIComponent('/@' + handle)}`;
      widget.classList.remove('hidden');
      await updateSignedInUi();
    } catch (_) {
      // No chatbot for this profile (disabled or not configured) — leave the widget hidden.
    }
  };

  init();
})();
