(() => {
'use strict';
const apiBase=document.body.dataset.apiBase||'/api/';
const endpoint=name=>{const [path,query]=name.split('?');return new URL(path+'.php'+(query?'?'+query:''),apiBase).href;};
let csrf='',staff=null,current=null,lastId=0,listTimer=null,threadTimer=null,knownLatest=new Map();
const $=id=>document.getElementById(id);
const loginView=$('login-view'),inboxView=$('inbox-view'),list=$('conversation-list'),messages=$('thread-messages');
async function api(name,{method='GET',body=null}={}){
 const headers={};if(body!==null)headers['Content-Type']='application/json';if(csrf)headers['X-CSRF-Token']=csrf;
 const response=await fetch(endpoint(name),{method,credentials:'same-origin',headers,body:body===null?undefined:JSON.stringify(body)});
 let data={};try{data=await response.json()}catch(_){}
 if(!response.ok)throw new Error(data.error||'request_failed');return data;
}
function showLogin(){loginView.hidden=false;inboxView.hidden=true}
function showInbox(){loginView.hidden=true;inboxView.hidden=false;$('staff-identity').textContent=staff.display_name+' · '+staff.role;$('ai-control').hidden=staff.role!=='admin';refreshList();startListPolling();if(staff.role==='admin')refreshAiSettings()}
function formatTime(value){if(!value)return '';return new Date(value.replace(' ','T')+'Z').toLocaleString([],{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}
function node(tag,className,text){const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=text;return el}
function renderList(items){
 list.replaceChildren();
 if(!items.length){list.appendChild(node('p','empty-state','No conversations in this view.'));document.title='Dimdian Support';return}
 let totalUnread=0;
 for(const item of items){
  const button=node('button','conversation-item'+(current&&current.public_id===item.public_id?' active':''));button.type='button';
  const top=node('div','conversation-top');top.append(node('span','conversation-name',item.customer_name||'Guest shopper'),node('span','conversation-time',formatTime(item.last_message_at)));
  const preview=node('div','conversation-preview',item.last_message||'No messages yet');
  const tags=node('div','conversation-tags');tags.append(node('span','tag',item.status.replace('_',' ')));
  if(item.context_sku)tags.append(node('span','tag','SKU '+item.context_sku));
  const unread=Number(item.unread_count)||0;if(unread){tags.append(node('span','unread',String(unread)));totalUnread+=unread}
  button.append(top,preview,tags);button.addEventListener('click',()=>selectConversation(item));list.appendChild(button);
 }
 document.title=totalUnread?'('+totalUnread+') Dimdian Support':'Dimdian Support';
}
async function refreshAiSettings(){
 try{
  const data=await api('ai-settings');
  const toggle=$('ai-enabled');
  toggle.checked=!!data.ai_enabled;
  toggle.disabled=!data.ai_available;
  $('ai-test-button').disabled=!data.ai_available;
  $('ai-mode-note').textContent=data.ai_available
   ?(data.ai_enabled?'AI is permitted for new eligible chats.':'AI is off. New chats go directly to staff.')
   :'AI provider is not configured yet. All chats stay with staff.';
  $('ai-control-error').textContent='';
 }catch(_){
  $('ai-enabled').checked=false;$('ai-enabled').disabled=true;$('ai-test-button').disabled=true;
  $('ai-mode-note').textContent='Could not load support mode. Human support remains available.';
 }
}
async function setAiMode(enabled){
 const toggle=$('ai-enabled');toggle.disabled=true;$('ai-control-error').textContent='';
 try{
  const data=await api('ai-settings',{method:'POST',body:{ai_enabled:enabled}});
  toggle.checked=!!data.ai_enabled;
  $('ai-mode-note').textContent=data.ai_enabled?'AI is permitted for new eligible chats.':'AI is off. New chats go directly to staff.';
  refreshList();
 }catch(error){
  toggle.checked=!enabled;
  $('ai-control-error').textContent=error.message==='ai_provider_not_configured'?'AI cannot be enabled until a provider is configured.':'Could not change AI mode. Please retry.';
  refreshAiSettings();
 }finally{await refreshAiSettings()}
}
async function refreshList(){
 try{
  const data=await api('staff-conversations?status='+encodeURIComponent($('status-filter').value));
  const items=data.conversations||[];
  for(const item of items){
   const latestId=Number(item.last_message_id)||0,old=knownLatest.get(item.public_id);
   if(old!==undefined&&latestId>old&&item.last_sender_type==='customer')playAlert();
   knownLatest.set(item.public_id,latestId);
  }
  renderList(items);
 }catch(error){if(error.message==='staff_auth_required')showLogin()}
}
function startListPolling(){stopListPolling();refreshList();listTimer=setInterval(refreshList,8000)}
function stopListPolling(){if(listTimer)clearInterval(listTimer);listTimer=null}
async function selectConversation(item){
 current=item;lastId=0;$('thread-empty').hidden=true;$('thread-view').hidden=false;
 $('customer-name').textContent=item.customer_name||'Guest shopper';
 $('customer-meta').textContent=[item.customer_email,item.customer_account_id?'Account '+item.customer_account_id:null,item.context_sku?'SKU '+item.context_sku:null,item.vehicle_summary?'Vehicle '+item.vehicle_summary:null,item.vehicle_vin?'VIN '+item.vehicle_vin:null].filter(Boolean).join(' · ');
 $('thread-status').value=item.status;syncReplyAvailability();
 const fitmentPanel=$('fitment-verify');fitmentPanel.hidden=staff.role!=='admin'||!item.vehicle_summary;
 $('fitment-product-id').value=item.vehicle_product_id||'';$('fitment-source').value='';$('fitment-result').textContent='';
 const link=$('product-link');if(item.context_url){link.href=item.context_url;link.hidden=false}else{link.hidden=true}
 messages.replaceChildren();$('reply-error').textContent='';
 await pollThread(true);refreshList();startThreadPolling();
}
async function loadAttachment(img,id){
 try{const response=await fetch(endpoint('staff-attachment?id='+encodeURIComponent(id)),{credentials:'same-origin'});if(response.ok)img.src=URL.createObjectURL(await response.blob())}catch(_){}
}
function syncReplyAvailability(){const closed=current&&current.status==='closed';$('reply-body').disabled=!!closed;$('reply-form').querySelector('button').disabled=!!closed;$('reply-body').placeholder=closed?'This conversation is closed.':'Reply to customer…'}
function appendMessage(message){
 const empty=messages.querySelector('.empty-state');if(empty)empty.remove();
 const bubble=node('article','bubble '+message.sender_type,message.body||'');
 if(message.attachment&&message.attachment.id){const img=node('img');img.alt='Customer image attachment';bubble.appendChild(img);loadAttachment(img,message.attachment.id)}
 bubble.appendChild(node('time','',formatTime(message.created_at)));messages.appendChild(bubble);messages.scrollTop=messages.scrollHeight;
}
async function pollThread(initial=false){
 if(!current||document.hidden)return;
 try{
  const data=await api('staff-messages?conversation_id='+encodeURIComponent(current.public_id)+'&after_id='+lastId);
  for(const message of data.messages||[]){appendMessage(message);lastId=Math.max(lastId,Number(message.id)||0)}
  if(data.status){current.status=data.status;$('thread-status').value=data.status;syncReplyAvailability()}
  if(initial&&lastId===0)messages.appendChild(node('p','empty-state','No messages yet.'));
 }catch(error){if(error.message==='staff_auth_required')showLogin()}
}
function startThreadPolling(){stopThreadPolling();threadTimer=setInterval(()=>pollThread(false),3000)}
function stopThreadPolling(){if(threadTimer)clearInterval(threadTimer);threadTimer=null}
function playAlert(){
 try{const Ctx=window.AudioContext||window.webkitAudioContext;if(!Ctx)return;const ctx=new Ctx(),osc=ctx.createOscillator(),gain=ctx.createGain();osc.frequency.value=740;gain.gain.value=.045;osc.connect(gain);gain.connect(ctx.destination);osc.start();osc.stop(ctx.currentTime+.12);osc.onended=()=>ctx.close()}catch(_){}
}
$('login-form').addEventListener('submit',async event=>{
 event.preventDefault();$('login-error').textContent='';const form=new FormData(event.currentTarget);
 try{
  const response=await fetch(endpoint('staff-login'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:form.get('email'),password:form.get('password')})});
  const data=await response.json();if(!response.ok)throw new Error(data.error||'sign_in_failed');
  csrf=data.csrf_token;staff=data.staff;event.currentTarget.reset();showInbox();
 }catch(error){$('login-error').textContent=error.message==='too_many_attempts'?'Too many attempts. Wait and try again.':'Email or password was not accepted.'}
});
$('logout-button').addEventListener('click',async()=>{
 try{await api('staff-session',{method:'POST',body:{}})}catch(_){}
 csrf='';staff=null;current=null;stopListPolling();stopThreadPolling();showLogin();
});
$('status-filter').addEventListener('change',()=>refreshList());
$('ai-enabled').addEventListener('change',event=>setAiMode(event.currentTarget.checked));
$('ai-test-button').addEventListener('click',async()=>{
 const button=$('ai-test-button');button.disabled=true;$('ai-mode-note').textContent='Testing provider connectivity…';
 try{await api('ai-settings',{method:'POST',body:{test_provider:true}});$('ai-mode-note').textContent='Provider test passed. AI is still off until you enable replies.';}
 catch(error){$('ai-mode-note').textContent=error.message==='ai_provider_not_configured'?'Configure the provider in private config.php first.':'Provider test failed. AI remains off; check server logs and provider settings.';}
 finally{await refreshAiSettings();}
});
$('thread-status').addEventListener('change',async()=>{
 if(!current)return;
 try{const data=await api('staff-status',{method:'POST',body:{conversation_id:current.public_id,status:$('thread-status').value}});current.status=data.status;syncReplyAvailability();refreshList()}
 catch(error){$('reply-error').textContent='Could not update conversation status.';$('thread-status').value=current.status}
});
$('fitment-save').addEventListener('click',async()=>{
 if(!current)return;const source=$('fitment-source').value.trim();if(!source){$('fitment-result').textContent='Enter the trusted verification source first.';return;}
 const button=$('fitment-save');button.disabled=true;$('fitment-result').textContent='Checking product and saving verified record…';
 try{const productValue=$('fitment-product-id').value;const body={conversation_id:current.public_id,verification_source:source};if(productValue)body.product_id=Number(productValue);
 const data=await api('fitments',{method:'POST',body});$('fitment-result').textContent='Verified fitment recorded for '+(data.product_name||('product #'+data.product_id))+'.';refreshList();}
 catch(error){$('fitment-result').textContent=error.message==='catalogue_not_configured'?'Connect the read-only WooCommerce API first.':error.message==='complete_vehicle_and_product_details_required'?'The vehicle must include engine, transmission and a product ID.':'Could not record verification. Check the source and product details.';}
 finally{button.disabled=false;}
 });
$('reply-form').addEventListener('submit',async event=>{
 event.preventDefault();if(!current)return;const body=$('reply-body').value.trim();if(!body)return;
 const button=event.currentTarget.querySelector('button');button.disabled=true;$('reply-error').textContent='';
 try{
  const data=await api('staff-messages',{method:'POST',body:{conversation_id:current.public_id,body}});
  appendMessage({...data.message,created_at:data.message.created_at||new Date().toISOString().slice(0,19).replace('T',' ')});
  lastId=Math.max(lastId,Number(data.message.id)||0);$('reply-body').value='';refreshList();
 }catch(error){$('reply-error').textContent='Reply could not be sent. Check the conversation status and retry.'}
 finally{button.disabled=false}
});
document.addEventListener('visibilitychange',()=>{if(document.hidden){stopListPolling();stopThreadPolling()}else if(staff){startListPolling();if(current)startThreadPolling()}});
(async()=>{
 try{const data=await api('staff-session');if(data.authenticated){staff=data.staff;csrf=data.csrf_token||'';showInbox()}else showLogin()}
 catch(_){showLogin()}
})();
})();
