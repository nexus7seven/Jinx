<aside class="wip-assistant" id="wipAssistant">
    <div class="wip-assistant__head">
        <div><strong>Jinx Assistant</strong><div id="wipAssistantSubtitle">Your WIP desk assistant</div></div>
        <button type="button" id="wipAssistantReset">New chat</button>
    </div>
    <div class="wip-assistant__history" id="wipAssistantHistory"><div class="wip-assistant__muted">Loading…</div></div>
    <form id="wipAssistantComposer" class="wip-assistant__composer">
        <textarea id="wipAssistantInput" rows="4" placeholder="Ask about your WIP, book a callback, or ask Jinx something…"></textarea>
        <div class="wip-assistant__sendrow"><span id="wipAssistantStatus">Connecting…</span><button type="submit" id="wipAssistantSend">Send</button></div>
    </form>
</aside>
<style>
.wip-assistant{position:sticky;top:18px;background:#0f172a;border:1px solid #334155;border-radius:14px;overflow:hidden;min-height:620px;display:flex;flex-direction:column;box-shadow:0 16px 40px rgba(0,0,0,.2)}
.wip-assistant__head{padding:14px 16px;border-bottom:1px solid #334155;display:flex;justify-content:space-between;gap:10px;align-items:center}.wip-assistant__head strong{font-size:16px}.wip-assistant__head div div{font-size:11px;color:#64748b;margin-top:3px}.wip-assistant__head button{background:#111827;color:#94a3b8;border:1px solid #334155;border-radius:7px;padding:6px 9px;cursor:pointer}
.wip-assistant__history{flex:1;min-height:430px;max-height:calc(100vh - 300px);overflow:auto;padding:12px}.wip-assistant__msg{padding:9px 10px;border-radius:10px;margin-bottom:9px;white-space:pre-wrap;font-size:13px;line-height:1.4}.wip-assistant__msg--user{margin-left:25px;background:#1d4ed8}.wip-assistant__msg--assistant{margin-right:15px;background:#111827;border:1px solid #334155}.wip-assistant__label{display:block;font-size:9px;font-weight:800;text-transform:uppercase;opacity:.6;margin-bottom:3px}
.wip-assistant__composer{padding:12px;border-top:1px solid #334155}.wip-assistant__composer textarea{box-sizing:border-box;width:100%;resize:vertical;background:#0b1220;color:#f8fafc;border:1px solid #334155;border-radius:9px;padding:10px;font:inherit;font-size:13px}.wip-assistant__sendrow{display:flex;align-items:center;justify-content:space-between;margin-top:8px;font-size:11px;color:#64748b}.wip-assistant__sendrow button{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer}.wip-assistant__muted{color:#64748b;font-size:12px}
</style>
<script>
(()=>{const history=document.getElementById('wipAssistantHistory'),form=document.getElementById('wipAssistantComposer'),input=document.getElementById('wipAssistantInput'),send=document.getElementById('wipAssistantSend'),status=document.getElementById('wipAssistantStatus'),reset=document.getElementById('wipAssistantReset'),csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
const html=m=>`<div class="wip-assistant__msg wip-assistant__msg--${m.role==='user'?'user':'assistant'}"><span class="wip-assistant__label">${m.role==='user'?'You':'Jinx'}</span>${esc(m.content)}</div>`;
const append=m=>{if(history.querySelector('[data-empty]'))history.innerHTML='';history.insertAdjacentHTML('beforeend',html(m));history.scrollTop=history.scrollHeight};
const busy=(on,text)=>{input.disabled=on;send.disabled=on;send.style.opacity=on?'.55':'1';if(text)status.textContent=text};
async function boot(){try{let r=await fetch('/assistant/wip',{headers:{Accept:'application/json'}}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Unable to load');history.innerHTML=d.messages?.length?d.messages.map(html).join(''):'<div class="wip-assistant__muted" data-empty="1">No WIP conversation yet. Ask me what needs doing or book a callback.</div>';history.scrollTop=history.scrollHeight;status.textContent='Ready';document.getElementById('wipAssistantSubtitle').textContent=`WIP desk · ${d.knowledge_count||0} shared rules remembered`;}catch(e){status.textContent='Unavailable';}}
form.addEventListener('submit',async e=>{e.preventDefault();let message=input.value.trim();if(!message||send.disabled)return;append({role:'user',content:message});input.value='';busy(true,'Jinx is thinking…');try{let r=await fetch('/assistant/wip/message',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({message})}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Assistant error');append(d.message);status.textContent='Ready';if(/callback booked/i.test(d.message.content))setTimeout(()=>location.reload(),500);}catch(e){append({role:'assistant',content:`I couldn't respond: ${e.message}`});status.textContent='Error';}finally{busy(false);input.focus();}});
input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}});
reset.addEventListener('click',async()=>{if(!confirm('Start a new WIP assistant conversation? Shared knowledge will remain.'))return;busy(true,'Starting new chat…');try{await fetch('/assistant/wip/reset',{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf}});history.innerHTML='<div class="wip-assistant__muted" data-empty="1">New WIP conversation started.</div>';status.textContent='Ready';}finally{busy(false);}});boot();})();
</script>
