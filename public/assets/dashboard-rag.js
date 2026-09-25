(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#rag-content');
  const documentForm = document.querySelector('#document-form');
  const documentFileInput = document.querySelector('#document-file-input');
  const documentStatus = document.querySelector('#document-status');
  const documentList = document.querySelector('#document-list');
  const faqHeading = document.querySelector('#faq-heading');
  const faqEmpty = document.querySelector('#faq-empty');
  const faqContent = document.querySelector('#faq-content');
  const faqCountInput = document.querySelector('#faq-count-input');
  const generateFaqsButton = document.querySelector('#generate-faqs-button');
  const faqStatus = document.querySelector('#faq-status');
  const faqList = document.querySelector('#faq-list');
  const faqCardTemplate = document.querySelector('#faq-card-template');
  const chatbotSettingsContent = document.querySelector('#chatbot-settings-content');
  const chatbotSettingsForm = document.querySelector('#chatbot-settings-form');
  const chatbotEnabledInput = document.querySelector('#chatbot-enabled-input');
  const chatbotPriceInput = document.querySelector('#chatbot-price-input');
  const chatbotCurrencyInput = document.querySelector('#chatbot-currency-input');
  const chatbotSettingsStatus = document.querySelector('#chatbot-settings-status');

  let selectedDocument = null;

  const token = () => sessionStorage.getItem(tokenKey);
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) throw new Error(payload?.error?.message || `Request failed (${response.status})`);
    return payload;
  };
  const message = (el, text, error = false) => {
    el.textContent = text;
    el.className = `status ${error ? 'error' : 'success'}`;
  };

  const readAsText = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error('Could not read the selected file.'));
    reader.readAsText(file);
  });

  const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

  // ---- Documents ----
  const loadDocuments = async () => {
    try {
      const result = await api('/api/v1/me/rag/documents');
      documentList.replaceChildren();
      if (result.data.length === 0) {
        documentList.innerHTML = '<p class="muted">Belum ada dokumen.</p>';
        return;
      }
      result.data.forEach((doc) => documentList.append(documentCard(doc)));
    } catch (error) {
      documentList.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
    }
  };

  const documentCard = (doc) => {
    const card = document.createElement('article');
    card.className = 'gateway-card';
    if (selectedDocument && selectedDocument.id === doc.id) card.style.borderColor = 'var(--accent)';
    const head = document.createElement('div');
    head.className = 'gateway-card-head';
    head.innerHTML = `<strong>${escapeHtml(doc.title)}</strong>`;
    const actions = document.createElement('div');
    actions.className = 'actions';
    const selectButton = document.createElement('button');
    selectButton.type = 'button';
    selectButton.className = 'secondary';
    selectButton.textContent = 'Kelola FAQ';
    selectButton.addEventListener('click', () => selectDocument(doc));
    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.className = 'secondary';
    deleteButton.textContent = 'Hapus';
    deleteButton.addEventListener('click', () => deleteDocument(doc));
    actions.append(selectButton, deleteButton);
    card.append(head, actions);
    return card;
  };

  const deleteDocument = async (doc) => {
    if (!confirm(`Hapus dokumen "${doc.title}" beserta semua FAQ-nya?`)) return;
    try {
      await api(`/api/v1/me/rag/documents/${encodeURIComponent(doc.id)}`, { method: 'DELETE' });
      if (selectedDocument && selectedDocument.id === doc.id) {
        selectedDocument = null;
        faqContent.classList.add('hidden');
        faqEmpty.classList.remove('hidden');
        faqHeading.textContent = 'FAQ';
      }
      await loadDocuments();
    } catch (error) {
      message(documentStatus, error.message, true);
    }
  };

  documentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const file = documentFileInput.files[0];
    if (!file) return;
    try {
      const text = await readAsText(file);
      const contentType = /\.md$/i.test(file.name) ? 'text/markdown' : 'text/plain';
      await api('/api/v1/me/rag/documents', {
        method: 'POST',
        body: JSON.stringify({
          title: documentForm.elements.title.value.trim(),
          content_type: contentType,
          content_base64: btoa(unescape(encodeURIComponent(text))),
          filename: file.name,
        }),
      });
      documentForm.reset();
      message(documentStatus, 'Dokumen berhasil diupload.');
      await loadDocuments();
    } catch (error) {
      message(documentStatus, error.message, true);
    }
  });

  // ---- FAQs ----
  const selectDocument = (doc) => {
    selectedDocument = doc;
    faqHeading.textContent = `FAQ: ${doc.title}`;
    faqEmpty.classList.add('hidden');
    faqContent.classList.remove('hidden');
    loadFaqs();
    loadDocuments();
  };

  const faqCard = (faq) => {
    const card = faqCardTemplate.content.firstElementChild.cloneNode(true);
    card.querySelector('.faq-question').value = faq.question;
    card.querySelector('.faq-answer').value = faq.answer;
    card.querySelector('.faq-embedding-status').textContent = faq.embedding_generated
      ? `Embedding tersimpan (${faq.embedding_model || ''}).`
      : 'Belum ada embedding — akan dibuat ulang saat disimpan (butuh provider LLM dengan dukungan embeddings, mis. OpenAI/OpenRouter).';
    card.querySelector('.faq-save').addEventListener('click', async () => {
      try {
        await api(`/api/v1/me/rag/faqs/${encodeURIComponent(faq.id)}`, {
          method: 'PATCH',
          body: JSON.stringify({
            question: card.querySelector('.faq-question').value.trim(),
            answer: card.querySelector('.faq-answer').value.trim(),
          }),
        });
        message(faqStatus, 'FAQ tersimpan.');
        await loadFaqs();
      } catch (error) {
        message(faqStatus, error.message, true);
      }
    });
    card.querySelector('.faq-delete').addEventListener('click', async () => {
      if (!confirm('Hapus FAQ ini?')) return;
      try {
        await api(`/api/v1/me/rag/faqs/${encodeURIComponent(faq.id)}`, { method: 'DELETE' });
        await loadFaqs();
      } catch (error) {
        message(faqStatus, error.message, true);
      }
    });
    return card;
  };

  const loadFaqs = async () => {
    if (!selectedDocument) return;
    try {
      const result = await api(`/api/v1/me/rag/documents/${encodeURIComponent(selectedDocument.id)}/faqs`);
      faqList.replaceChildren();
      if (result.data.length === 0) {
        faqList.innerHTML = '<p class="muted">Belum ada FAQ. Klik "Generate FAQ dengan AI" untuk membuatnya.</p>';
        return;
      }
      result.data.forEach((faq) => faqList.append(faqCard(faq)));
    } catch (error) {
      message(faqStatus, error.message, true);
    }
  };

  generateFaqsButton.addEventListener('click', async () => {
    if (!selectedDocument) return;
    generateFaqsButton.disabled = true;
    message(faqStatus, 'Membuat FAQ dengan AI…');
    try {
      await api(`/api/v1/me/rag/documents/${encodeURIComponent(selectedDocument.id)}/faqs/generate`, {
        method: 'POST',
        body: JSON.stringify({ count: Number(faqCountInput.value) || 5 }),
      });
      message(faqStatus, 'FAQ berhasil dibuat.');
      await loadFaqs();
    } catch (error) {
      message(faqStatus, error.message, true);
    } finally {
      generateFaqsButton.disabled = false;
    }
  });

  // ---- Chatbot settings ----
  const loadChatbotSettings = async () => {
    try {
      const result = await api('/api/v1/me/chatbot-settings');
      chatbotEnabledInput.checked = result.data.enabled;
      chatbotPriceInput.value = result.data.price_per_question;
      chatbotCurrencyInput.value = result.data.currency;
    } catch (error) {
      message(chatbotSettingsStatus, error.message, true);
    }
  };

  chatbotSettingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await api('/api/v1/me/chatbot-settings', {
        method: 'PATCH',
        body: JSON.stringify({
          enabled: chatbotEnabledInput.checked,
          price_per_question: chatbotPriceInput.value,
          currency: chatbotCurrencyInput.value,
        }),
      });
      message(chatbotSettingsStatus, 'Pengaturan chatbot tersimpan.');
    } catch (error) {
      message(chatbotSettingsStatus, error.message, true);
    }
  });

  // ---- Auth ----
  const verify = async () => {
    if (!token()) {
      authSummary.textContent = 'Masuk untuk mengelola dokumen RAG.';
      loginForm.classList.remove('hidden');
      content.classList.add('hidden');
      chatbotSettingsContent.classList.add('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.add('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.remove('hidden'));
      return;
    }
    try {
      const result = await api('/api/v1/me');
      authSummary.textContent = `Masuk sebagai ${result.data.user.email}.`;
      loginForm.classList.add('hidden');
      content.classList.remove('hidden');
      chatbotSettingsContent.classList.remove('hidden');
      document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
      document.querySelectorAll('.guest-nav').forEach((el) => el.classList.add('hidden'));
      await loadDocuments();
      await loadChatbotSettings();
    } catch (_) {
      sessionStorage.removeItem(tokenKey);
      verify();
    }
  };

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const result = await api('/api/v1/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email: loginForm.elements.email.value, password: loginForm.elements.password.value }),
      });
      sessionStorage.setItem(tokenKey, result.data.token.access_token);
      verify();
    } catch (error) {
      authSummary.textContent = error.message;
    }
  });

  verify();
})();
