@extends('portal.layout')

@section('title', 'Portal Completed')

@section('content')
<div class="portal-card" style="max-width:560px; margin:40px auto 0; text-align:center;">
    <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dcfce7; color:#166534; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">Complete</div>

    <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">You&rsquo;re all set</h1>
    @if(!empty($emailedAt))
        <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">We&rsquo;ve sent your summary by email.</p>
    @elseif(!empty($hadEmailAddress))
        <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">We&rsquo;ve saved your summary and will send it by email shortly.</p>
    @else
        <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">We&rsquo;ve saved your summary.</p>
    @endif
    <p style="margin:0 0 8px 0; color:#334155; font-size:17px; line-height:1.6; font-weight:700;">You&rsquo;re not alone in this &mdash; we help people in your position every day.</p>
    <p style="margin:0 0 14px 0; color:#475569; font-size:16px; line-height:1.6;">If you&rsquo;d like to talk through your situation now, we can explain your options clearly and simply.</p>

    @php
        $portalWhatsAppUrl = config('services.portal.whatsapp_url');
        $portalCallUrl = config('services.portal.call_url');
    @endphp
    <div style="margin-top:14px; border:1px solid #dbeafe; border-radius:14px; padding:14px; background:#eff6ff;">
        <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:10px;">
        @if(!blank($portalWhatsAppUrl))
            <a href="{{ $portalWhatsAppUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-flex; align-items:center; justify-content:center; min-width:220px; min-height:50px; text-decoration:none; padding:12px 16px; border-radius:12px; background:#16a34a; color:#ffffff; font-size:16px; font-weight:700;">Message us on WhatsApp</a>
        @endif
        @if(!blank($portalCallUrl))
            <a href="{{ $portalCallUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-flex; align-items:center; justify-content:center; min-width:220px; min-height:50px; text-decoration:none; padding:12px 16px; border-radius:12px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700;">Call us now</a>
        @endif
        </div>
        <div style="margin-top:10px; color:#334155; font-size:14px; font-weight:600;">No pressure. No obligation. Just clear advice.</div>
    </div>
</div>
@endsection
