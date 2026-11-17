(() => {
  'use strict';
  const script = document.currentScript;
  if (!script || document.getElementById('dimdian-chat-host')) return;
  const apiBase = (script.dataset.apiBase || new URL('./api/', script.src).href).replace(/\/?$/, '/');
  const contextSku = script.dataset.contextSku || '';
  const tokenKey = 'dimdian.guest-token';
  const conversationKey = 'dimdian.conversation-id';
  const token = () => localStorage.getItem(tokenKey) || '';
  let conversationId = localStorage.getItem(conversationKey) || '';
  let lastMessageId = 0, pollTimer = null, busy = false, isOpen = false, selectedProductId = null, conversationEpoch = 0, catalogueSearchId = 0;

  const host = document.createElement('div');
  host.id = 'dimdian-chat-host';
  host.style.cssText = 'position:fixed;z-index:2147483000;right:20px;bottom:20px;';
  document.body.appendChild(host);
  const root = host.attachShadow({mode:'open'});
  const style = document.createElement('style');
  style.textContent = ':host{all:initial;font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#172033}*{box-sizing:border-box}.launcher{width:58px;height:58px;border:0;border-radius:50%;background:#172033;color:#fff;box-shadow:0 8px 28px #0b122044;cursor:pointer;font-size:24px}.panel{display:none;position:absolute;right:0;bottom:72px;width:min(370px,calc(100vw - 32px));height:min(540px,calc(100vh - 120px));background:#fff;border:1px solid #dce2ea;border-radius:16px;box-shadow:0 18px 60px #0b122033;overflow:hidden}.panel.open{display:flex;flex-direction:column}header{padding:16px 18px;background:#172033;color:#fff;display:flex;align-items:center;justify-content:space-between}header strong{font-size:15px;font-weight:650}header small{display:block;color:#c8d0dc;font-size:12px;margin-top:3px}.close{background:transparent;border:0;color:#fff;font-size:22px;cursor:pointer}.messages{flex:1;overflow:auto;padding:14px;background:#f6f8fb;display:flex;flex-direction:column;gap:10px}.empty{margin:auto;text-align:center;color:#64748b;font-size:13px;line-height:1.5;max-width:240px}.message{max-width:85%;padding:10px 12px;border-radius:13px;font-size:13px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}.customer{align-self:flex-end;background:#172033;color:#fff;border-bottom-right-radius:4px}.staff,.assistant,.system{align-self:flex-start;background:#fff;color:#172033;border:1px solid #e3e8ef;border-bottom-left-radius:4px}.message img{display:block;max-width:100%;max-height:180px;border-radius:8px;margin-top:7px}.time{display:block;font-size:10px;opacity:.65;margin-top:5px}.composer{padding:12px;border-top:1px solid #e3e8ef;background:#fff}.compose-row{display:flex;gap:8px;align-items:flex-end}textarea{resize:none;min-height:42px;max-height:110px;flex:1;border:1px solid #d8dee8;border-radius:10px;padding:10px;font:inherit;font-size:13px;outline:none}textarea:focus{border-color:#64748b}button.send,button.attach{height:40px;border:0;border-radius:10px;cursor:pointer}button.send{padding:0 14px;background:#172033;color:#fff;font-weight:600}button.attach{width:40px;background:#eef1f5;color:#172033;font-size:18px}button:disabled{opacity:.5;cursor:not-allowed}.hint{font-size:10px;color:#788397;margin-top:7px;display:flex;justify-content:space-between;gap:8px}.error{font-size:12px;color:#b42318;margin:0 0 8px;min-height:16px}.file{display:none}.catalogue-search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px;margin-bottom:8px}.catalogue-search input,.catalogue-search select{min-width:0;border:1px solid #d8dee8;border-radius:7px;padding:8px;font:inherit;font-size:12px}.catalogue-search select{grid-column:1/-1}.catalogue-search button{border:0;border-radius:7px;padding:8px 11px;background:#eef1f5;color:#172033;font:inherit;font-size:12px;cursor:pointer}.catalogue-results{display:grid;gap:6px;max-height:150px;overflow:auto;margin-bottom:8px}.catalogue-item{border:1px solid #e3e8ef;border-radius:8px;padding:8px;font-size:11px}.catalogue-item.has-image{display:grid;grid-template-columns:54px minmax(0,1fr);gap:6px;align-items:start}.catalogue-item img{width:54px;height:54px;object-fit:contain;grid-row:1 / span 3;border-radius:6px;background:#f6f8fb}.catalogue-item a{color:#172033;font-weight:650}.catalogue-item strong{font-weight:650}.select-product{margin-top:7px;border:0;border-radius:7px;padding:7px 9px;background:#172033;color:#fff;font:inherit;font-size:11px;cursor:pointer}.select-product:disabled{opacity:.55;cursor:default}.catalogue-item small{display:block;color:#64748b;margin-top:3px}.vehicle-toggle{width:100%;border:0;background:#eef1f5;color:#172033;border-radius:9px;padding:9px;margin-bottom:8px;text-align:left;font:inherit;font-size:12px;cursor:pointer}.vehicle-form{display:none;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:9px;padding:10px;border:1px solid #dce2ea;border-radius:10px;background:#fbfcfe}.vehicle-form.open{display:grid}.vehicle-form label{display:block;font-size:10px;color:#64748b}.vehicle-form input{width:100%;min-width:0;margin-top:3px;border:1px solid #d8dee8;border-radius:7px;padding:8px;font:inherit;font-size:12px}.vehicle-form .wide{grid-column:1/-1}.vehicle-form button{grid-column:1/-1;border:0;border-radius:8px;padding:9px;background:#172033;color:#fff;font:inherit;font-size:12px;cursor:pointer}.vehicle-form .vehicle-note{grid-column:1/-1;font-size:11px;color:#64748b;margin:0}@media(max-width:480px){:host{right:12px!important;bottom:12px!important}.panel{position:fixed;right:12px;bottom:82px;width:calc(100vw - 24px);height:min(600px,calc(100dvh - 108px))}}';
  const panel = document.createElement('section');
  panel.className = 'panel';
  panel.setAttribute('aria-label','Customer support chat');
  panel.innerHTML = '<header><div><strong>Parts support</strong><small>Ask us about a part or fitment</small></div><button class="close" type="button" aria-label="Close chat">×</button></header><div class="messages" aria-live="polite"><div class="empty">Send us a message. If you are viewing a part, we will include its listing details.</div></div><div class="composer"><div class="catalogue-search"><input class="catalogue-query" maxlength="100" placeholder="Search live parts by name, SKU or MPN" aria-label="Search live catalogue"><button class="catalogue-submit" type="button">Search parts</button><select class="catalogue-type" aria-label="Search field"><option value="name">Product name</option><option value="sku">Exact SKU</option><option value="mpn">Manufacturer part number</option></select></div><div class="catalogue-results" aria-live="polite"></div><button class="vehicle-toggle" type="button">＋ Add vehicle details for a fitment check</button><form class="vehicle-form" id="dimdian-vehicle-form"><label>Make<input name="make" maxlength="100" required placeholder="Toyota"></label><label>Model<input name="model" maxlength="120" required placeholder="Camry"></label><label>Year<input name="year" type="number" min="1950" max="2027" required placeholder="2018"></label><label>Engine<input name="engine" maxlength="120" placeholder="2.5L 2AR-FE"></label><label>Transmission<input name="transmission" maxlength="100" placeholder="Automatic"></label><label>Trim<input name="trim" maxlength="120" placeholder="LE"></label><label class="wide">VIN (optional)<input name="vin" maxlength="17" autocomplete="off" placeholder="17-character VIN"></label><p class="vehicle-note">Vehicle details are shared with our support team. Compatibility is confirmed only against verified fitment records.</p><button type="submit">Send vehicle details</button></form><p class="error" role="status"></p><form class="message-form"><div class="compose-row"><input class="file" type="file" accept="image/jpeg,image/png,image/webp"><button class="attach" type="button" aria-label="Attach an image" title="Attach JPG, PNG or WEBP (max 3 MB)">＋</button><textarea rows="1" maxlength="5000" placeholder="Write your message…" aria-label="Message"></textarea><button class="send" type="submit">Send</button></div><div class="hint"><span>JPG, PNG or WEBP · max 3 MB</span><span>Replies appear here</span></div></form></div>';
  const launcher = document.createElement('button');
  launcher.className = 'launcher'; launcher.type = 'button';
  launcher.setAttribute('aria-label','Open support chat'); launcher.textContent = '✉';
  root.append(style,panel,launcher);

  const messagesEl=panel.querySelector('.messages'), form=panel.querySelector('.message-form');
  const vehicleForm=panel.querySelector('#dimdian-vehicle-form'),vehicleToggle=panel.querySelector('.vehicle-toggle');
  const catalogueQuery=panel.querySelector('.catalogue-query'),catalogueType=panel.querySelector('.catalogue-type'),catalogueResults=panel.querySelector('.catalogue-results');
  const textarea=panel.querySelector('textarea'), fileInput=panel.querySelector('.file');
  const errorEl=panel.querySelector('.error'), sendButton=panel.querySelector('.send');
  const endpoint=(name)=>new URL(name+'.php',apiBase).href;
  const headers=(extra={})=>({'X-Dimdian-Guest-Token':token(),...extra});

  async function request(url,options={}) {
    const response=await fetch(url,{credentials:'omit',...options});
    let data={}; try{data=await response.json()}catch(_){}
    if(!response.ok) throw new Error(data.error||'request_failed');
    return data;
  }
  async function ensureSession() {
    const data=await request(endpoint('session'),{method:'POST',headers:headers()});
    localStorage.setItem(tokenKey,data.token);
  }
  async function ensureConversation() {
    const data=await request(endpoint('conversations'),{
      method:'POST',headers:headers({'Content-Type':'application/json'}),
      body:JSON.stringify({context_url:location.href.split('#')[0],context_sku:contextSku||null,subject:document.title.slice(0,180)})
    });
    conversationId=data.conversation.id;
    localStorage.setItem(conversationKey,conversationId);
    selectedProductId=data.selected_product?Number(data.selected_product.id):null;
    vehicleToggle.textContent=data.selected_product?'✓ '+data.selected_product.name+' selected · add vehicle details':'＋ Add vehicle details for a fitment check';
  }
  function showError(message){errorEl.textContent=message?String(message).replaceAll('_',' '):''}
  function appendMessage(message) {
    const empty=messagesEl.querySelector('.empty'); if(empty) empty.remove();
    const bubble=document.createElement('article');
    bubble.className='message '+(['customer','staff','assistant','system'].includes(message.sender_type)?message.sender_type:'system');
    const body=document.createElement('div'); body.textContent=message.body||''; bubble.appendChild(body);
    if(message.attachment&&message.attachment.id) {
      const img=document.createElement('img'); img.alt='Image attachment'; img.loading='lazy';
      bubble.appendChild(img); loadAttachment(img,message.attachment.id);
    }
    const time=document.createElement('time'); time.className='time';
    time.textContent=message.created_at?new Date(message.created_at.replace(' ','T')+'Z').toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}):'';
    bubble.appendChild(time); messagesEl.appendChild(bubble); messagesEl.scrollTop=messagesEl.scrollHeight;
  }
  async function loadAttachment(img,id) {
    try {
      const response=await fetch(endpoint('attachment')+'?id='+encodeURIComponent(id),{headers:headers(),credentials:'omit'});
      if(response.ok) img.src=URL.createObjectURL(await response.blob());
    }catch(_){}
  }
  async function poll() {
    if(!isOpen||document.hidden||busy||!conversationId)return;
    const targetConversationId=conversationId,epoch=conversationEpoch,afterId=lastMessageId;
    try {
      const url=endpoint('messages')+'?conversation_id='+encodeURIComponent(targetConversationId)+'&after_id='+afterId;
      const data=await request(url,{headers:headers()});
      if(!isOpen||epoch!==conversationEpoch||targetConversationId!==conversationId)return;
      for(const message of data.messages||[]){appendMessage(message);lastMessageId=Math.max(lastMessageId,Number(message.id)||0)}
      if(data.status==='closed'){textarea.disabled=true;sendButton.disabled=true;fileInput.disabled=true;showError('This conversation is closed. Close and reopen chat to start a new inquiry.');}
    }catch(error){
      if(isOpen&&epoch===conversationEpoch&&targetConversationId===conversationId&&error.message==='guest_session_invalid'){localStorage.removeItem(tokenKey);showError('Your chat session expired. Close and reopen to reconnect.')}
    }
  }
  function stopPolling(){if(pollTimer!==null)clearInterval(pollTimer);pollTimer=null}
  function startPolling(){stopPolling();poll();pollTimer=setInterval(poll,4000)}
  async function openChat(){
    isOpen=true;panel.classList.add('open');launcher.hidden=true;showError('');
    try{
      const previousConversationId=conversationId;
      await ensureSession();
      await ensureConversation();
      if(previousConversationId!==conversationId){
        conversationEpoch++;catalogueSearchId++;lastMessageId=0;messagesEl.replaceChildren();
        const empty=document.createElement('div');empty.className='empty';
        empty.textContent='Send us a message. If you are viewing a part, we will include its listing details.';
        messagesEl.appendChild(empty);
        catalogueResults.replaceChildren();catalogueQuery.value='';
        vehicleForm.reset();vehicleForm.classList.remove('open');
        textarea.value='';fileInput.value='';
      }
      textarea.disabled=false;sendButton.disabled=false;fileInput.disabled=false;
      startPolling();textarea.focus();
    }catch(_){
      textarea.disabled=true;sendButton.disabled=true;fileInput.disabled=true;
      showError('Chat could not connect. Please try again.');
    }
  }
  function closeChat(){isOpen=false;conversationEpoch++;catalogueSearchId++;panel.classList.remove('open');launcher.hidden=false;stopPolling()}
  launcher.addEventListener('click',openChat);
  panel.querySelector('.close').addEventListener('click',closeChat);
  panel.querySelector('.attach').addEventListener('click',()=>fileInput.click());
  vehicleToggle.addEventListener('click',()=>vehicleForm.classList.toggle('open'));
  async function searchCatalogue(){
    const q=catalogueQuery.value.trim();if(!q||busy||!conversationId)return;
    const searchId=++catalogueSearchId,epoch=conversationEpoch,targetConversationId=conversationId;
    catalogueResults.replaceChildren();catalogueResults.textContent='Searching the live store catalogue…';
    try{
      const url=endpoint('products')+'?type='+encodeURIComponent(catalogueType.value)+'&q='+encodeURIComponent(q);
      const data=await request(url,{headers:headers()});
      if(!isOpen||searchId!==catalogueSearchId||epoch!==conversationEpoch||targetConversationId!==conversationId)return;
      catalogueResults.replaceChildren();
      if(!data.products||!data.products.length){catalogueResults.textContent='No live catalogue matches found.';return;}
      for(const product of data.products){
        const card=document.createElement('article');card.className='catalogue-item';
        if(product.image){card.classList.add('has-image');const image=document.createElement('img');image.src=product.image;image.alt='';image.loading='lazy';card.appendChild(image);}
        if(product.permalink){
          const link=document.createElement('a');link.href=product.permalink;link.target='_blank';link.rel='noopener noreferrer';link.textContent=product.name||'View product';card.appendChild(link);
        }else{
          const title=document.createElement('strong');title.textContent=product.name||'Catalogue product';card.appendChild(title);
        }
        const stock=product.stock_status==='instock'?'In stock':product.stock_status==='outofstock'?'Out of stock':product.stock_status==='onbackorder'?'Available on backorder':'Stock status unavailable';
        const price=(data.currency?data.currency+' ':'')+(product.price||'Price unavailable')+(product.sale_price&&product.regular_price&&product.sale_price!==product.regular_price?' · was '+product.regular_price:'');
        const meta=document.createElement('small');meta.textContent='SKU '+(product.sku||'—')+(product.mpn?' · MPN '+product.mpn:'')+' · '+price+' · '+stock;card.appendChild(meta);
        const select=document.createElement('button');select.type='button';select.className='select-product';select.textContent=selectedProductId===Number(product.id)?(product.type==='simple'?'Selected for fitment':'Selected for specialist review'):(product.type==='simple'?'Use for fitment check':'Ask specialist to check variants');select.disabled=!product.id||!product.permalink||selectedProductId===Number(product.id);
        select.addEventListener('click',async()=>{
          if(busy||!conversationId)return;
          const targetConversationId=conversationId,epoch=conversationEpoch;
          busy=true;select.disabled=true;select.textContent='Selecting listing…';
          try{
            await request(endpoint('product-context'),{method:'POST',headers:headers({'Content-Type':'application/json'}),body:JSON.stringify({conversation_id:targetConversationId,product_id:Number(product.id)})});
            if(!isOpen||epoch!==conversationEpoch||targetConversationId!==conversationId)return;
            selectedProductId=Number(product.id);
            catalogueResults.querySelectorAll('.select-product').forEach(button=>{button.disabled=true;button.textContent=button===select?(product.type==='simple'?'Selected for fitment':'Selected for specialist review'):'Another listing selected';});
            vehicleToggle.textContent='✓ '+(product.name||'Product')+' selected · add vehicle details';
            vehicleForm.classList.add('open');
          }catch(error){
            if(isOpen&&epoch===conversationEpoch&&targetConversationId===conversationId){
              select.disabled=false;select.textContent=product.type==='simple'?'Use for fitment check':'Ask specialist to check variants';
              showError(error.message==='catalogue_product_not_found'?'That listing is no longer available. Search again.':'Could not attach this listing to the vehicle check. Please retry.');
            }
          }finally{busy=false;}
        });
        card.appendChild(select);catalogueResults.appendChild(card);
      }
    }catch(error){if(isOpen&&searchId===catalogueSearchId&&epoch===conversationEpoch&&targetConversationId===conversationId)catalogueResults.textContent=error.message==='mpn_lookup_not_configured'?'Manufacturer part-number search is not configured yet. Search by name or SKU.':'Live catalogue search is temporarily unavailable.';}
  }
  panel.querySelector('.catalogue-submit').addEventListener('click',searchCatalogue);
  catalogueQuery.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();searchCatalogue()}});
  vehicleForm.addEventListener('submit',async(event)=>{
    event.preventDefault();if(busy||!conversationId)return;const fields=new FormData(vehicleForm);const details=Object.fromEntries(fields.entries());
    const targetConversationId=conversationId,epoch=conversationEpoch;
    busy=true;vehicleForm.querySelector('button').disabled=true;showError('');
    try{
      await request(endpoint('vehicle'),{method:'POST',headers:headers({'Content-Type':'application/json'}),body:JSON.stringify({conversation_id:targetConversationId,make:details.make,model:details.model,year:Number(details.year),engine:details.engine,transmission:details.transmission,trim:details.trim,vin:details.vin})});
      if(!isOpen||epoch!==conversationEpoch||targetConversationId!==conversationId)return;
      vehicleForm.reset();vehicleForm.classList.remove('open');await poll();
    }catch(error){if(isOpen&&epoch===conversationEpoch&&targetConversationId===conversationId)showError(error.message==='vin_encryption_not_configured'?'VIN submission is temporarily unavailable. You can leave the VIN blank or contact support.':'Vehicle details could not be submitted. Please retry.');}
    finally{busy=false;if(epoch===conversationEpoch&&targetConversationId===conversationId)vehicleForm.querySelector('button').disabled=false;}
  });

  form.addEventListener('submit',async(event)=>{
    event.preventDefault();const body=textarea.value.trim();if(!body||busy||!conversationId)return;
    const targetConversationId=conversationId,epoch=conversationEpoch;
    busy=true;sendButton.disabled=true;showError('');
    try{
      const data=await request(endpoint('messages'),{method:'POST',headers:headers({'Content-Type':'application/json'}),body:JSON.stringify({conversation_id:targetConversationId,body})});
      if(!isOpen||epoch!==conversationEpoch||targetConversationId!==conversationId)return;
      appendMessage({...data.message,created_at:new Date().toISOString().slice(0,19).replace('T',' ')});
      lastMessageId=Math.max(lastMessageId,Number(data.message.id)||0);textarea.value='';
    }catch(_){if(isOpen&&epoch===conversationEpoch&&targetConversationId===conversationId)showError('Message not sent. Please retry')}
    finally{busy=false;if(epoch===conversationEpoch&&targetConversationId===conversationId){sendButton.disabled=textarea.disabled;if(isOpen)textarea.focus()}}
  });

  fileInput.addEventListener('change',async()=>{
    const file=fileInput.files&&fileInput.files[0];if(!file)return;
    if(file.size>3*1024*1024){showError('Image must be 3 MB or smaller');fileInput.value='';return}
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)){showError('Choose a JPG, PNG or WEBP image');fileInput.value='';return}
    if(busy||!conversationId)return;
    const targetConversationId=conversationId,epoch=conversationEpoch;
    busy=true;sendButton.disabled=true;showError('');
    try{
      const formData=new FormData();formData.append('conversation_id',targetConversationId);formData.append('file',file);
      const data=await request(endpoint('upload'),{method:'POST',headers:headers(),body:formData});
      if(!isOpen||epoch!==conversationEpoch||targetConversationId!==conversationId)return;
      appendMessage({id:data.message.id,sender_type:'customer',body:'Image attachment',created_at:new Date().toISOString().slice(0,19).replace('T',' '),attachment:data.attachment});
      lastMessageId=Math.max(lastMessageId,Number(data.message.id)||0);
    }catch(_){if(isOpen&&epoch===conversationEpoch&&targetConversationId===conversationId)showError('Image upload failed. Check the format and try again')}
    finally{busy=false;if(epoch===conversationEpoch&&targetConversationId===conversationId){sendButton.disabled=textarea.disabled;fileInput.value=''}}
  });
  document.addEventListener('visibilitychange',()=>{if(document.hidden)stopPolling();else if(isOpen)startPolling()});
})();