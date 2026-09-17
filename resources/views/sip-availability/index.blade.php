@extends('layouts.app')

@section('title', 'SIP Availability - Jinx')

@push('head')
<style>
    .sip-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-end;margin-bottom:18px}.sip-head h1{margin:0;font-size:24px}.sip-sub{margin:5px 0 0;color:#94a3b8;font-size:13px}.sip-actions{display:flex;gap:8px;align-items:center}.sip-btn,.sip-select{border:1px solid #334155;background:#111827;color:#e2e8f0;border-radius:8px;padding:8px 11px;font:inherit}.sip-btn{cursor:pointer}.sip-btn:disabled,.sip-select:disabled{opacity:.55;cursor:wait}.sip-btn:not(:disabled):hover{background:#1e293b}.sip-status{font-size:12px;color:#94a3b8;margin-bottom:14px}.sip-error{display:none;padding:12px;border:1px solid rgba(239,68,68,.45);background:rgba(127,29,29,.2);border-radius:9px;color:#fecaca;margin-bottom:14px}.sip-day{border:1px solid #263244;background:#0f172a;border-radius:11px;margin-bottom:12px;overflow:hidden}.sip-day-head{padding:10px 13px;background:#111c2f;border-bottom:1px solid #263244;font-weight:650}.sip-slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px;padding:11px}.sip-slot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 10px;border:1px solid #334155;border-radius:8px;background:#111827;color:inherit;text-decoration:none}.sip-slot:hover{background:#172033;border-color:#475569}.sip-time{font-weight:700}.sip-person{color:#94a3b8;font-size:12px;text-align:right}.sip-empty{padding:28px;text-align:center;border:1px dashed #334155;border-radius:10px;color:#94a3b8}.sip-staff{margin-top:16px;color:#64748b;font-size:11px}.sip-loading{opacity:.55;pointer-events:none}@media(max-width:650px){.sip-head{align-items:flex-start;flex-direction:column}.sip-actions{width:100%}.sip-select{flex:1}}
</style>
@endpush

@section('content')
<div class="sip-head">
    <div><h1>SIP Availability</h1><p class="sip-sub">Combined live 1-hour appointment availability across eligible SIP staff. Click a slot to open that SIP in Setmore and complete the booking.</p></div>
    <div class="sip-actions">
        <select id="sipDays" class="sip-select"><option value="7" selected>Next 7 days</option><option value="14">Next 14 days</option><option value="21">Next 21 days</option><option value="31">Next 31 days</option></select>
        <button id="sipRefresh" class="sip-btn" type="button">Refresh</button>
    </div>
</div>
<div id="sipStatus" class="sip-status">Loading live Setmore availability…</div>
<div id="sipError" class="sip-error"></div>
<div id="sipResults"><div class="sip-empty">Loading…</div></div>
<div id="sipStaff" class="sip-staff"></div>
@endsection

@push('scripts')
<script>
(() => {
 const results=document.getElementById('sipResults'), status=document.getElementById('sipStatus'), error=document.getElementById('sipError'), staff=document.getElementById('sipStaff'), days=document.getElementById('sipDays'), refresh=document.getElementById('sipRefresh');
 const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
 async function load(){
   refresh.disabled=true; days.disabled=true; error.style.display='none'; results.classList.add('sip-loading'); status.textContent='Loading live Setmore availability…';
   try{
     const r=await fetch(`{{ route('sip-availability.data') }}?days=${encodeURIComponent(days.value)}`,{headers:{'Accept':'application/json'},cache:'no-store'}); let data; try{data=await r.json();}catch(_){throw new Error('Setmore availability returned an invalid response.');} if(!r.ok) throw new Error(data.message||'Could not load availability.'); if(!Array.isArray(data.slots)||!Array.isArray(data.staff)) throw new Error('Setmore returned invalid availability data.');
     const groups={}; data.slots.filter(s=>s&&s.date&&s.time&&s.staff_name&&s.booking_url).forEach(s=>(groups[s.date]??=[]).push(s));
     const dates=Object.keys(groups);
     results.innerHTML=dates.length?dates.map(date=>`<section class="sip-day"><div class="sip-day-head">${esc(groups[date][0].date_label)}</div><div class="sip-slots">${groups[date].map(s=>`<a class="sip-slot" href="${esc(s.booking_url)}" target="_blank" rel="noopener noreferrer" aria-label="Open ${esc(s.time)} with ${esc(s.staff_name)} in Setmore to complete booking" title="Open ${esc(s.staff_name)} in Setmore to complete booking"><span class="sip-time">${esc(s.time)}</span><span class="sip-person">${esc(s.staff_name)}</span></a>`).join('')}</div></section>`).join(''):'<div class="sip-empty">No available SIP appointments in this period.</div>';
     const fetched=new Date(data.fetched_at); const refreshed=Number.isNaN(fetched.getTime())?'just now':fetched.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}); const range=data.range_start&&data.range_end?`${data.range_start} → ${data.range_end} · `:''; status.textContent=`${data.slots.length} live slots across ${data.staff.length} staff · ${range}refreshed ${refreshed}`;
     staff.textContent=`Included: ${data.staff.filter(x=>x&&x.name).map(x=>x.name).join(', ')}. Excluded: ${(data.excluded||[]).join(', ')}.`;
     if(data.errors?.length){error.textContent=`Some staff could not be checked: ${data.errors.join(', ')}`; error.style.display='block';}
   }catch(e){results.innerHTML='<div class="sip-empty">Availability unavailable.</div>'; error.textContent=e.message; error.style.display='block'; status.textContent='Live availability failed to load.';}finally{results.classList.remove('sip-loading'); refresh.disabled=false; days.disabled=false;}
 }
 refresh.addEventListener('click',load); days.addEventListener('change',load); load();
})();
</script>
@endpush
