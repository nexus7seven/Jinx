<section class="bot-case-tracker" style="padding:22px;color:#dce8e7;background:#0d1616;border:1px solid #26443a;border-radius:12px">
 <h2 style="color:#92f4b3;margin:0 0 7px">Bot Case Tracker</h2>
 <p style="color:#a8beb4;margin:0 0 19px">Pacman's current understanding of this case and what it plans to do next.</p>
 <div id="botTrackerContent" aria-live="polite">Loading the latest case review…</div>
</section>
<script>
(function(){
 const mount=document.getElementById('botTrackerContent');
 const endpoint=@json(route('lead.bot-case-tracker.show',$lead));
 const escape=t=>String(t??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const list=(items,empty)=>items.length?'<ul style="padding-left:21px;line-height:1.8">'+items.map(x=>'<li>'+escape(x)+'</li>').join('')+'</ul>':'<p style="color:#8da59b">'+empty+'</p>';
 async function refresh(){
  try{
   const response=await fetch(endpoint,{credentials:'same-origin',headers:{Accept:'application/json'}});
   if(!response.ok)throw Error('Unable to load the tracker');
   const data=await response.json(),t=data.tracker;
   if(!t){mount.textContent='Pacman has not completed an initial review for this case yet.';return;}
   const obtained=Array.isArray(t.obtained)?t.obtained:[],missing=Array.isArray(t.missing)?t.missing:[];
   mount.innerHTML='<div style="margin-bottom:17px;padding:13px;background:#142521;border-radius:8px"><b>'+escape(t.bot)+'</b> · '+escape((t.ip_route||'Zebra').replaceAll('_',' '))+' · '+escape(t.state.replaceAll('_',' '))+'<br><small style="color:#8da59b">Updated '+escape(t.updated_at)+'</small><p>'+escape(t.summary||'Review in progress')+'</p></div>'+
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:14px">'+
    '<div style="padding:15px;border:1px solid #345e46;border-radius:8px"><h3 style="color:#a4ffc2">What we have ('+obtained.length+')</h3>'+list(obtained,'No established items recorded yet.')+'</div>'+
    '<div style="padding:15px;border:1px solid #786239;border-radius:8px"><h3 style="color:#ffdc89">What is missing ('+missing.length+')</h3>'+list(missing,'Nothing outstanding in the latest review.')+'</div></div>'+
    '<div style="margin-top:15px;padding:15px;border-left:3px solid #73eaa2;background:#11251d"><b>Next planned action</b><p>'+escape(t.next_action||'Awaiting the next case update.')+'</p></div>';
  }catch(e){mount.textContent='Tracker temporarily unavailable. Please refresh.';}
 }
 window.addEventListener('jinx:bot-case-tracker:activate',refresh);
})();
</script>
