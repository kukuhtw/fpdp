(() => {
  // Translated UI text (window.fpdpT from the page head); the Indonesian fallback keeps the page readable without it.
  const t=window.fpdpT||((k,p,f)=>Object.keys(p||{}).reduce((s,n)=>s.split(`:${n}`).join(p[n]),f)),numberLocale=window.FPDP_LOCALE==='en'?'en-US':'id-ID';
  const key='fpdp_visitor_token',handle=document.body.dataset.profileHandle,status=document.querySelector('#cv-status');let cv=null;
  const api=async(path,options={},binary=false)=>{const headers={...(options.headers||{})},token=sessionStorage.getItem(key);if(token)headers.Authorization=`Bearer ${token}`;if(options.body)headers['Content-Type']='application/json';const response=await fetch(path,{...options,headers});if(binary&&response.ok)return response.blob();const payload=await response.json();if(!response.ok)throw new Error(payload?.error?.message||t('common.request_failed',{status:response.status},`Request failed (${response.status})`));return payload;};
  const message=(text,error=false)=>{status.textContent=text;status.className=`status ${error?'error':'success'}`;};
  const showConfirmLink=()=>{const el=document.querySelector('#payment-confirm-link');if(!el)return;el.innerHTML='';const link=document.createElement('a');link.className='button secondary';link.href=`/payment/thank-you?type=cv&handle=${encodeURIComponent(handle)}`;link.textContent=t('checkout.confirm_link',{},'Sudah transfer? Cek status pembayaran →');el.append(link);};
  const render=()=>{
    const host=document.querySelector('#cv-details');host.replaceChildren();
    const title=document.createElement('h2');title.textContent=cv.title;
    const price=document.createElement('p');price.className='cv-price';price.textContent=Number(cv.price_amount)>0?`${cv.price_currency} ${Number(cv.price_amount).toLocaleString(numberLocale)}`:t('checkout.free',{},'Gratis');
    host.append(title,price);
    document.querySelector('#cv-actions').classList.remove('hidden');
    const signed=Boolean(sessionStorage.getItem(key));
    document.querySelector('#google-login').classList.toggle('hidden',signed);
    const buyerIdentity=document.querySelector('#cv-buyer-identity');
    const visitorName=sessionStorage.getItem('fpdp_visitor_name'),visitorEmail=sessionStorage.getItem('fpdp_visitor_email');
    if(signed&&visitorEmail){const who=`${visitorName?`${visitorName} `:''}(${visitorEmail})`;buyerIdentity.textContent=t('checkout.signed_in_as',{who},`Masuk sebagai: ${who}`);buyerIdentity.classList.remove('hidden');}else{buyerIdentity.classList.add('hidden');}
    // Name/phone are only needed to identify a paid purchase (so the owner
    // can match a manual bank transfer to this visitor) — a free CV skips
    // straight to access, so there's nothing to identify.
    const needsBuyerInfo=signed&&!cv.has_access&&Number(cv.price_amount)>0;
    document.querySelector('#cv-name-field').classList.toggle('hidden',!needsBuyerInfo);
    document.querySelector('#cv-phone-field').classList.toggle('hidden',!needsBuyerInfo);
    document.querySelector('#access-button').classList.toggle('hidden',!signed||cv.has_access);
    document.querySelector('#access-button').textContent=Number(cv.price_amount)>0?t('cv.buy_access',{},'Beli akses'):t('cv.enable_download',{},'Aktifkan download');
    document.querySelector('#download-button').classList.toggle('hidden',!signed||!cv.has_access);
  };
  const load=async()=>{try{cv=(await api(`/api/v1/profiles/${encodeURIComponent(handle)}/cv`)).data;render();}catch(error){const empty=document.createElement('p');empty.className='muted';empty.textContent=t('cv.not_published',{},'CV belum dipublikasikan.');document.querySelector('#cv-details').replaceChildren(empty);message(error.message,true);}};
  document.querySelector('#access-button').addEventListener('click',async()=>{
    const body={};
    if(Number(cv.price_amount)>0){
      const name=document.querySelector('#cv-name-input').value.trim(),phone=document.querySelector('#cv-phone-input').value.trim();
      if(!name||!phone)return message(t('checkout.fill_name_phone',{},'Isi nama dan nomor telepon terlebih dahulu.'),true);
      body.name=name;body.phone=phone;
    }
    try{
      const result=(await api(`/api/v1/profiles/${encodeURIComponent(handle)}/cv/access`,{method:'POST',body:JSON.stringify(body)})).data;
      if(result.granted){cv.has_access=true;render();return message(t('cv.access_granted',{},'Akses diberikan. CV siap diunduh.'));}
      if(result.payment?.payment_url){message(t('checkout.redirecting',{},'Mengalihkan ke halaman pembayaran…'));location.href=result.payment.payment_url;return;}
      if(result.payment?.instructions){message(result.payment.instructions);showConfirmLink();return;}
      message(t('cv.payment_processing',{},'Pembayaran diproses. Muat ulang setelah pembayaran dikonfirmasi.'));showConfirmLink();
    }catch(error){message(error.message,true);}
  });
  document.querySelector('#download-button').addEventListener('click',async()=>{try{const blob=await api(`/api/v1/profiles/${encodeURIComponent(handle)}/cv/download`,{},true),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=cv.title;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}catch(error){message(error.message,true);}});
  load();
})();
