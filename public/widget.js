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
  let lastMessageId = 0, pollTimer = null, busy = false, isOpen = false;

  const host = document.createElement('div');
  host.id = 'dimdian-chat-host';
  host.style.cssText = 'position:fixed;z-index:2147483000;right:20px;bottom:20px;';
  document.body.appendChild(host);
  const root = host.attachShadow({mode:'open'});
  const style = document.createElement('style');
  style.textContent = ':host{all:initial;font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#172033}*{box-sizing:border-box}.launcher{width:58px;height:58px;border:0;border-radius:50%;background:#172033;color:#fff;box-shadow:0 8px 28px #0b122044;cursor:pointer;font-size:24px}.panel{display:none;position:absolute;right:0;bottom:72px;width:min(370px,calc(100vw - 32px));height:min(540px,calc(100vh - 120px));background:#fff;border:1px solid #dce2ea;border-radius:16px;box-shadow:0 18px 60px #0b122033;overflow:hidden}.panel.open{display:flex;flex-direction:column}header{padding:16px 18px;background:#172033;color:#fff;display:flex;align-items:center;justify-content:space-between}header strong{font-size:15px;font-weight:650}header small{display:block;color:#c8d0dc;font-size:12px;margin-top:3px}.close{background:transparent;border:0;color:#fff;font-size:22px;cursor:pointer}.messages{flex:1;overflow:auto;padding:14px;background:#f6f8fb;display:flex;flex-direction:column;gap:10px}.empty{margin:auto;text-align:center;color:#64748b;font-size:13px;line-height:1.5;max-width:240px}.message{max-width:85%;padding:10px 12px;border-radius:13px;font-size:13px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}.customer{align-self:flex-end;background:#172033;color:#fff;border-bottom-right-radius:4px}.staff,.assistant,.system{align-self:flex-start;background:#fff;color:#172033;border:1px solid #e3e8ef;border-bottom-left-radius:4px}.message img{display:block;max-width:100%;max-height:180px;border-radius:8px;margin-top:7px}.time{display:block;font-size:10px;opacity:.65;margin-top:5px}.composer{padding:12px;border-top:1px solid #e3e8ef;background:#fff}.compose-row{display:flex;gap:8px;align-items:flex-end}textarea{resize:none;min-height:42px;max-height:110px;flex:1;border:1px solid #d8dee8;border-radius:10px;padding:10px;font:inherit;font-size:13px;outline:none}textarea:focus{border-color:#64748b}button.send,button.attach{height:40px;border:0;border-radius:10px;cursor:pointer}button.send{padding:0 14px;background:#172033;color:#fff;font-weight:600}button.attach{width:40px;background:#eef1f5;color:#172033;font-size:18px}button:disabled{opacity:.5;cursor:not-allowed}.hint{font-size:10px;color:#788397;margin-top:7px;display:flex;justify-content:space-between;gap:8px}.error{font-size:12px;color:#b42318;margin:0 0 8px;min-height:16px}.file{display:none}@media(max-width:480px){:host{right:12px!important;bottom:12px!important}.panel{position:fixed;right:12px;bottom:82px;width:calc(100vw - 24px);height:min(600px,calc(100dvh - 108px))}}';
  const panel = document.createElement('section');
  panel.className = 'panel';
  panel.setAttribute('aria-label','Customer support chat');
  panel.innerHTML = '<header><div><strong>Parts support</strong><small>Ask us about a part or fitment</small></div><button class="close" type="button" aria-label="Close chat">×</button></header><div class="messages" aria-live="polite"><div class="empty">Send us a message. If you are viewing a part, we will include its listing details.</div></div><form class="composer"><p class="error" role="status"></p><div class="compose-row"><input class="file" type="file" accept="image/jpeg,image/png,image/webp"><button class="attach" type="button" aria-label="Attach an image" title="Attach JPG, PNG or WEBP (max 3 MB)">＋</button><textarea rows="1" maxlength="5000" placeholder="Write your message…" aria-label="Message"></textarea><button class="send" type="submit">Send</button></div><div class="hint"><span>JPG, PNG or WEBP · max 3 MB</span><span>Replies appear here</span></div></form>';
  const launcher = document.createElement('button');
  launcher.className = 'launcher'; launcher.type = 'button';
  launcher.setAttribute('aria-label','Open support chat'); launcher.textContent = '✉';
  root.append(style,panel,launcher);

  const messagesEl=panel.querySelector('.messages'), form=panel.querySelector('form');
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
      body:JSON.stringify({context_url:location.href,context_sku:contextSku||null,subject:document.title.slice(0,180)})
    });
    conversationId=data.conversation.id;
    localStorage.setItem(conversationKey,conversationId);
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
    try {
      const url=endpoint('messages')+'?conversation_id='+encodeURIComponent(conversationId)+'&after_id='+lastMessageId;
      const data=await request(url,{headers:headers()});
      for(const message of data.messages||[]){appendMessage(message);lastMessageId=Math.max(lastMessageId,Number(message.id)||0)}
    }catch(error){
      if(error.message==='guest_session_invalid'){localStorage.removeItem(tokenKey);showError('Your chat session expired. Close and reopen to reconnect.')}
    }
  }
  function stopPolling(){if(pollTimer!==null)clearInterval(pollTimer);pollTimer=null}
  function startPolling(){stopPolling();poll();pollTimer=setInterval(poll,4000)}
  async function openChat(){
    isOpen=true;panel.classList.add('open');launcher.hidden=true;showError('');
    try{await ensureSession();await ensureConversation();startPolling();textarea.focus()}
    catch(_){showError('Chat could not connect. Please try again.')}
  }
  function closeChat(){isOpen=false;panel.classList.remove('open');launcher.hidden=false;stopPolling()}
  launcher.addEventListener('click',openChat);
  panel.querySelector('.close').addEventListener('click',closeChat);
  panel.querySelector('.attach').addEventListener('click',()=>fileInput.click());

  form.addEventListener('submit',async(event)=>{
    event.preventDefault();const body=textarea.value.trim();if(!body||busy||!conversationId)return;
    busy=true;sendButton.disabled=true;showError('');
    try{
      const data=await request(endpoint('messages'),{method:'POST',headers:headers({'Content-Type':'application/json'}),body:JSON.stringify({conversation_id:conversationId,body})});
      appendMessage({...data.message,created_at:new Date().toISOString().slice(0,19).replace('T',' ')});
      lastMessageId=Math.max(lastMessageId,Number(data.message.id)||0);textarea.value='';
    }catch(_){showError('Message not sent. Please retry')}
    finally{busy=false;sendButton.disabled=false;textarea.focus()}
  });

  fileInput.addEventListener('change',async()=>{
    const file=fileInput.files&&fileInput.files[0];if(!file)return;
    if(file.size>3*1024*1024){showError('Image must be 3 MB or smaller');fileInput.value='';return}
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)){showError('Choose a JPG, PNG or WEBP image');fileInput.value='';return}
    if(busy||!conversationId)return;
    busy=true;sendButton.disabled=true;showError('');
    try{
      const formData=new FormData();formData.append('conversation_id',conversationId);formData.append('file',file);
      const data=await request(endpoint('upload'),{method:'POST',headers:headers(),body:formData});
      appendMessage({id:data.message.id,sender_type:'customer',body:'Image attachment',created_at:new Date().toISOString().slice(0,19).replace('T',' '),attachment:data.attachment});
      lastMessageId=Math.max(lastMessageId,Number(data.message.id)||0);
    }catch(_){showError('Image upload failed. Check the format and try again')}
    finally{busy=false;sendButton.disabled=false;fileInput.value=''}
  });
  document.addEventListener('visibilitychange',()=>{if(document.hidden)stopPolling();else if(isOpen)startPolling()});
})();