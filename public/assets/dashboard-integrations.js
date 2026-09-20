(() => {
  const tokenKey = 'fpdp_access_token';
  const loginForm = document.querySelector('#login-form');
  const logoutButton = document.querySelector('#logout-button');
  const authSummary = document.querySelector('#auth-summary');
  const content = document.querySelector('#integration-content');
  const sourceForm = document.querySelector('#source-form');
  const provider = document.querySelector('#provider');
  const sourceList = document.querySelector('#source-list');
  const status = document.querySelector('#integration-status');
  const syncButton = document.querySelector('#sync-button');
  const facebookConnect = document.querySelector('#facebook-connect');
  const facebookAccounts = document.querySelector('#facebook-accounts');
  const linkedinConnect = document.querySelector('#linkedin-connect');
  const linkedinAccounts = document.querySelector('#linkedin-accounts');
  const token = () => sessionStorage.getItem(tokenKey);
  const showStatus = (message, error = false) => { status.textContent = message; status.className = `status ${error ? 'error' : 'success'}`; };
  const api = async (path, options = {}) => {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (token()) headers.Authorization = `Bearer ${token()}`;
    const response = await fetch(path, { ...options, headers });
    const payload = response.status === 204 ? null : await response.json();
    if (!response.ok) {
      const reason = payload?.error?.details?.[0]?.reason;
      throw new Error(reason === 'youtube_channel_id_required' ? 'Gunakan channel ID YouTube atau URL /channel/UC….' : (payload?.error?.message || `Request failed (${response.status})`));
    }
    return payload;
  };
  const showOwnerNav = () => document.querySelectorAll('.owner-nav').forEach((el) => el.classList.remove('hidden'));
  const setAuthenticated = (authenticated, label = '') => {
    loginForm.classList.toggle('hidden', authenticated); logoutButton.classList.toggle('hidden', !authenticated); content.classList.toggle('hidden', !authenticated);
    authSummary.textContent = authenticated ? `Masuk sebagai ${label}.` : 'Masuk untuk mengelola sumber konten.';
    if (authenticated) showOwnerNav();
  };
  const renderSources = (sources) => {
    sourceList.replaceChildren();
    if (!sources.length) { const empty = document.createElement('p'); empty.className = 'muted'; empty.textContent = 'Belum ada sumber yang terhubung.'; sourceList.append(empty); return; }
    sources.forEach((source) => {
      const row = document.createElement('article'); row.className = 'source-row';
      const copy = document.createElement('div'); const title = document.createElement('strong'); title.textContent = source.provider;
      const url = document.createElement('a'); url.href = source.source_url; url.target = '_blank'; url.rel = 'noopener noreferrer'; url.textContent = source.source_url;
      const meta = document.createElement('small'); meta.className = 'muted'; meta.textContent = source.last_sync_at ? `Sinkron terakhir: ${new Date(source.last_sync_at).toLocaleString()}` : 'Belum pernah disinkronkan';
      copy.append(title, url, meta); const badge = document.createElement('span'); badge.className = `status-badge ${source.status === 'ERROR' ? 'error-badge' : ''}`; badge.textContent = source.status;
      row.append(copy, badge); sourceList.append(row);
    });
  };
  const loadSources = async () => { const result = await api('/api/v1/me/feed-sources'); renderSources(result.data.sources); };
  const loadFacebook = async () => {
    const result = await api('/api/v1/me/integrations/facebook');
    facebookAccounts.replaceChildren();
    if (!result.data.accounts.length) { const empty=document.createElement('p'); empty.className='muted'; empty.textContent='Belum ada Facebook Page terhubung.'; facebookAccounts.append(empty); return; }
    result.data.accounts.forEach(account => { const row=document.createElement('article'); row.className='source-row'; const copy=document.createElement('div'); const name=document.createElement('strong'); name.textContent=account.display_name; const link=document.createElement('a'); link.href=account.profile_url; link.target='_blank'; link.rel='noopener noreferrer'; link.textContent=account.profile_url; copy.append(name,link); const remove=document.createElement('button'); remove.type='button'; remove.className='secondary'; remove.textContent='Putuskan'; remove.addEventListener('click',async()=>{if(!confirm(`Putuskan ${account.display_name}?`))return;try{await api(`/api/v1/me/integrations/facebook/${account.id}`,{method:'DELETE'});await Promise.all([loadFacebook(),loadSources()]);showStatus('Facebook Page diputuskan.');}catch(error){showStatus(error.message,true);}}); row.append(copy,remove); facebookAccounts.append(row); });
  };
  const loadLinkedIn = async () => {
    const result=await api('/api/v1/me/integrations/linkedin');linkedinAccounts.replaceChildren();if(!result.data.accounts.length){const empty=document.createElement('p');empty.className='muted';empty.textContent='Belum ada LinkedIn Organization terhubung.';linkedinAccounts.append(empty);return;}
    result.data.accounts.forEach(account=>{const row=document.createElement('article');row.className='source-row';const copy=document.createElement('div');const name=document.createElement('strong');name.textContent=account.display_name;const link=document.createElement('a');link.href=account.profile_url;link.target='_blank';link.rel='noopener noreferrer';link.textContent=account.profile_url;const expiry=document.createElement('small');expiry.className='muted';expiry.textContent=account.token_expires_at?`Token berlaku sampai ${new Date(account.token_expires_at).toLocaleString()}`:'Masa token tidak diketahui';copy.append(name,link,expiry);const remove=document.createElement('button');remove.type='button';remove.className='secondary';remove.textContent='Putuskan';remove.addEventListener('click',async()=>{if(!confirm(`Putuskan ${account.display_name}?`))return;try{await api(`/api/v1/me/integrations/linkedin/${account.id}`,{method:'DELETE'});await Promise.all([loadLinkedIn(),loadSources()]);showStatus('LinkedIn Organization diputuskan.');}catch(error){showStatus(error.message,true);}});row.append(copy,remove);linkedinAccounts.append(row);});
  };
  const verify = async () => {
    if (!token()) { setAuthenticated(false); return; }
    try { const result = await api('/api/v1/me'); setAuthenticated(true, result.data.profile.handle); await Promise.all([loadSources(),loadFacebook(),loadLinkedIn()]); const query=new URLSearchParams(location.search); if(query.get('facebook')==='connected') showStatus(`${query.get('pages')||0} Facebook Page berhasil dihubungkan.`); if(query.get('linkedin')==='connected') showStatus(`${query.get('organizations')||0} LinkedIn Organization berhasil dihubungkan.`); }
    catch (_) { sessionStorage.removeItem(tokenKey); setAuthenticated(false); }
  };
  provider.addEventListener('change', () => {
    const youtube = provider.value === 'YOUTUBE'; document.querySelector('#source-label-text').textContent = youtube ? 'Channel ID atau URL channel YouTube' : 'URL sumber';
    document.querySelector('#source-help').classList.toggle('hidden', !youtube); sourceForm.elements.source_url.placeholder = youtube ? 'UC... atau https://youtube.com/channel/UC...' : 'https://example.com/feed.xml';
  });
  sourceForm.addEventListener('submit', async (event) => {
    event.preventDefault(); const fields = new FormData(sourceForm);
    try { await api('/api/v1/me/feed-sources', { method: 'POST', body: JSON.stringify({ provider: fields.get('provider'), source_type: fields.get('provider') === 'YOUTUBE' ? 'youtube_channel' : String(fields.get('provider')).toLowerCase(), source_url: fields.get('source_url'), sync_interval: Number(fields.get('sync_interval')) }) }); sourceForm.reset(); await loadSources(); showStatus('Sumber berhasil dihubungkan. Klik “Sinkronkan sekarang” untuk mengambil konten.'); }
    catch (error) { showStatus(error.message, true); }
  });
  syncButton.addEventListener('click', async () => {
    syncButton.disabled = true;
    try { const result = await api('/api/v1/me/sync', { method: 'POST' }); await loadSources(); showStatus(`Sinkronisasi selesai: ${result.data.inserted} post baru, ${result.data.errors} error.`); }
    catch (error) { showStatus(error.message, true); } finally { syncButton.disabled = false; }
  });
  facebookConnect.addEventListener('click', async () => { facebookConnect.disabled=true; try { const result=await api('/api/v1/me/integrations/facebook/authorize',{method:'POST'}); location.href=result.data.authorization_url; } catch(error) { showStatus(error.message,true); facebookConnect.disabled=false; } });
  linkedinConnect.addEventListener('click',async()=>{linkedinConnect.disabled=true;try{const result=await api('/api/v1/me/integrations/linkedin/authorize',{method:'POST'});location.href=result.data.authorization_url;}catch(error){showStatus(error.message,true);linkedinConnect.disabled=false;}});
  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault(); const fields = new FormData(loginForm);
    try { const result = await api('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: fields.get('email'), password: fields.get('password') }) }); sessionStorage.setItem(tokenKey, result.data.token.access_token); loginForm.reset(); await verify(); showStatus('Berhasil masuk.'); }
    catch (error) { showStatus(error.message, true); }
  });
  logoutButton.addEventListener('click', async () => { try { await api('/api/v1/auth/logout', { method: 'POST' }); } catch (_) {} sessionStorage.removeItem(tokenKey); setAuthenticated(false); showStatus('Anda telah keluar.'); });
  document.querySelector('.toggle-password').addEventListener('click', (event) => { const input = loginForm.elements.password; input.type = input.type === 'password' ? 'text' : 'password'; event.currentTarget.textContent = input.type === 'password' ? event.currentTarget.dataset.show : event.currentTarget.dataset.hide; });
  verify();
})();
