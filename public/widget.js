(() => {
'use strict';
const script = document.currentScript;
if (!script) return;
const demo = script.dataset.demo === 'true';
const api = (script.dataset.apiBase || new URL('/api', script.src).href).replace(/\/+$/, '');
const key = 'dimdian-chat:' + api + ':' + location.origin;
const root = document.createElement('div');
root.id = 'dimdian-chat-widget';
const shadow = root.attachShadow({mode:'open'});
const css = document.createElement('link');
css.rel = 'stylesheet'; css.href = new URL('widget.css', script.src).href; shadow.append(css);
const wrap = document.createElement('div');
wrap.innerHTML = '<button class="launcher" type="button" aria-expanded="false"><span class="spark">✦</span> Message us</button>' +
'<section class="panel" role="dialog" aria-label="Customer support chat" hidden>' +
'<header><div class="mark">D</div><div class="heading"><h2></h2><p></p></div><button class="close" type="button" aria-label="Close chat">×</button></header>' +
'<main class="conversation" aria-live="polite"><div class="welcome"><span class="eyebrow">WELCOME</span><h3>How can we help?</h3><p>Send us a message and our team will get back to you here.</p>' +
'<div class="fields"><label>Name <small>optional</small><input class="name" maxlength="100" autocomplete="name" placeholder="Your name"></label><label>Phone <small>optional</small><input class="phone" type="tel" maxlength="32" autocomplete="tel" placeholder="+234…"></label></div><label class="wa-consent"><input class="wa-opt-in" type="checkbox"><span>Send my messages to this number on WhatsApp so I can continue the conversation there.</span></label></div>' +
'<div class="messages"></div><p class="privacy">Please avoid sharing passwords or sensitive payment details.</p></main>' +
'<div class="composer-wrap"><form class="composer"><label class="sr" for="dimdian-message">Your message</label><textarea id="dimdian-message" rows="1" maxlength="4000" placeholder="Write a message…" required></textarea><button class="send" type="submit" aria-label="Send message">↑</button></form><div class="meta"><span class="status" role="status">Replies appear here.</span><span class="count">0 / 4000</span></div></div><footer>Powered by Dimdian</footer></section>';
shadow.append(wrap); document.body.append(root);
const $ = s => shadow.querySelector(s);
const launcher=$('.launcher'), panel=$('.panel'), form=$('.composer'), input=$('textarea'), messages=$('.messages'), welcome=$('.welcome');
$('.heading h2').textContent=script.dataset.title||'Chat with us';
$('.heading p').textContent=script.dataset.subtitle||'We usually reply as soon as we can.';
if(demo){$('.fields').hidden=true;$('.wa-consent').hidden=true;}
let session=null, cursor=0, opened=false, busy=false, timer=null, delay=3000;
const seen=new Set();
const messageNodes=new Map();
let pendingSubmission=null;
function newMessageId(){return window.crypto&&window.crypto.randomUUID?window.crypto.randomUUID():'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return(c==='x'?r:(r&3|8)).toString(16);});}
try {
 const saved=JSON.parse(sessionStorage.getItem(key)||'null');
 if(saved&&/^[a-f0-9]{64}$/.test(saved.token)){session={token:saved.token,expiresAt:saved.expiresAt||null};cursor=0; // Re-fetch history after a page reload; cursor only advances during this page lifetime.
 }
} catch (_) {}
function persist(){if(session){try{sessionStorage.setItem(key,JSON.stringify({token:session.token,expiresAt:session.expiresAt,cursor}));}catch(_){}}}
function status(t,kind){$('.status').textContent=t;$('.status').dataset.kind=kind||'normal';}
async function request(path,opts){
 const headers=Object.assign({Accept:'application/json'},opts&&opts.headers);
 if(session)headers['X-DIMDIAN-GUEST-TOKEN']=session.token;
 const res=await fetch(api+path,Object.assign({mode:'cors',cache:'no-store',credentials:'omit'},opts||{},{headers}));
 let body;try{body=await res.json();}catch(_){throw new Error('The chat service returned an unreadable response.');}
 if(!res.ok){const e=new Error(body.error&&body.error.message||'The request could not be completed.');e.status=res.status;e.code=body.error&&body.error.code;throw e;}
 return body;
}
function scroll(){const el=$('.conversation');el.scrollTop=el.scrollHeight;}
function deliveryLabel(state){return ({queued:'Queued',sent:'Sent',delivered:'Delivered',read:'Read',failed:'Not sent',received:'Received',web_only:'Website only'})[state]||'Saved';}
function applyDeliveryUpdates(rows){(Array.isArray(rows)?rows:[]).forEach(row=>{const node=messageNodes.get(row.id);if(!node)return;node.textContent=deliveryLabel(row.delivery_status);node.dataset.status=row.delivery_status;});}
function render(m){
 if(!m||!m.id||seen.has(m.id))return;seen.add(m.id);
 const article=document.createElement('article');article.className='message '+(m.sender==='visitor'?'visitor':'business');
 const body=document.createElement('p');body.className='body';body.textContent=String(m.message||'');
 const meta=document.createElement('div');meta.className='message-meta';
 const time=document.createElement('time');const date=m.created_at?new Date(m.created_at):new Date();
 time.dateTime=Number.isNaN(date.getTime())?new Date().toISOString():date.toISOString();
 time.textContent=date.toLocaleTimeString([],{hour:'numeric',minute:'2-digit'});
 meta.append(time);
 const label=document.createElement('span');label.textContent=m.sender==='visitor'?deliveryLabel(m.delivery_status):'Support';label.dataset.status=m.delivery_status||'';meta.append(label);
 if(m.sender==='visitor')messageNodes.set(m.id,label);
 article.append(body,meta);messages.append(article);
 const seq=Number(m.sequence_id);if(Number.isSafeInteger(seq)){cursor=Math.max(cursor,seq);persist();}
 welcome.hidden=true;scroll();
}
function notice(t){const n=document.createElement('div');n.className='notice';n.textContent=t;messages.append(n);scroll();}
function clear(){messages.replaceChildren();seen.clear();cursor=0;welcome.hidden=false;}
async function startSession(){
 const body=await request('/session.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:$('.name').value.trim()||null,phone:$('.phone').value.trim()||null,whatsapp_opt_in:$('.wa-opt-in').checked})});
 if(!body.data||!/^[a-f0-9]{64}$/.test(body.data.session_token||''))throw new Error('Could not initialize a chat session.');
 session={token:body.data.session_token,expiresAt:body.data.expires_at||null};cursor=0;persist();
 $('.name').disabled=true;$('.phone').disabled=true;$('.wa-opt-in').disabled=true;welcome.hidden=true;
}
function expired(){
 session=null;cursor=0;try{sessionStorage.removeItem(key);}catch(_){}
 $('.name').disabled=false;$('.phone').disabled=false;$('.wa-opt-in').disabled=false;clear();
 notice('Your previous chat session expired. Send a new message to start again.');
}
async function poll(){
 if(!opened||!session||document.hidden)return;
 try{
  let more=true;
  while(more&&opened){
   const result=await request('/messages.php?after='+encodeURIComponent(String(cursor))+'&limit=50');
   (Array.isArray(result.data)?result.data:[]).forEach(render);
   const meta=result.meta||{};applyDeliveryUpdates(meta.delivery_updates);if(Number.isSafeInteger(Number(meta.next_cursor))){cursor=Math.max(cursor,Number(meta.next_cursor));persist();}
   more=Boolean(meta.has_more);
  }
  delay=3000;
 }catch(e){
  if(e.status===401||e.status===409){expired();stop();status('Start a new conversation.','warning');return;}
  delay=Math.min(30000,delay*2);status('Connection interrupted. Retrying…','warning');
 }
}
function stop(){if(timer)clearTimeout(timer);timer=null;}
function schedule(){stop();if(opened&&session)timer=setTimeout(async()=>{await poll();schedule();},delay);}
async function send(text){
 if(busy)return;
 if(demo){
  busy=true;$('.send').disabled=true;input.disabled=true;status('Sending demo message…');
  const now=new Date().toISOString();
  render({id:newMessageId(),sender:'visitor',message:text,delivery_status:'web_only',created_at:now,sequence_id:1});
  input.value='';$('.count').textContent='0 / 4000';status('Demo message received. Simulating a reply…');
  setTimeout(()=>{
   if(!document.querySelector('#dimdian-chat-widget'))return;
   render({id:newMessageId(),sender:'business',message:'Thanks for your message! This is a simulated support reply. In the live installation, your team would reply through the connected support channel.',delivery_status:'received',created_at:new Date().toISOString(),sequence_id:2});
   status('Demo conversation — no message was sent to a real person.');
  },850);
  busy=false;$('.send').disabled=false;input.disabled=false;input.focus();
  return;
 }
 busy=true;
 if(!pendingSubmission||pendingSubmission.text!==text)pendingSubmission={id:newMessageId(),text};$('.send').disabled=true;input.disabled=true;status('Sending…');
 try{
  if(!session)await startSession();
  const result=await request('/messages.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:text,client_message_id:pendingSubmission.id})});
  if(result.data)render(result.data);
  pendingSubmission=null;
  input.value='';$('.count').textContent='0 / 4000';status('Message saved. Waiting for a reply.');
  delay=1000;await poll();schedule();
 }catch(e){
  if((e.status===401||e.status===409)&&session){expired();input.value=text;status('Your chat session ended. Send again to start a new chat.','warning');}
  else status(e.message||'Message could not be sent. Please try again.','warning');
 }finally{busy=false;$('.send').disabled=false;input.disabled=false;input.focus();}
}
function open(){opened=true;panel.hidden=false;launcher.hidden=true;if(session){welcome.hidden=true;$('.name').disabled=true;$('.phone').disabled=true;$('.wa-opt-in').disabled=true;poll().finally(schedule);}else{welcome.hidden=false;stop();}setTimeout(()=>input.focus(),60);}
function close(){opened=false;panel.hidden=true;launcher.hidden=false;stop();}
launcher.addEventListener('click',open);$('.close').addEventListener('click',close);
form.addEventListener('submit',e=>{e.preventDefault();const text=input.value.trim();if(text)send(text);});
input.addEventListener('input',()=>{$('.count').textContent=input.value.length+' / 4000';input.style.height='auto';input.style.height=Math.min(input.scrollHeight,120)+'px';});
input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();form.requestSubmit();}});
document.addEventListener('visibilitychange',()=>{if(!opened)return;if(document.hidden)stop();else if(session)poll().finally(schedule);});
if(demo)status('Demo mode — messages stay in this browser.');
else if(session)status('Your conversation is ready.');
if(script.dataset.open==='true')open();
})();