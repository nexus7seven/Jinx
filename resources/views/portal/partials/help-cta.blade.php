@php
    $portalWhatsAppUrl = config('services.portal.whatsapp_url');
    $portalCallUrl = config('services.portal.call_url');
@endphp

<div style="margin-top:18px; padding:16px; border-radius:14px; border:1px solid #dbeafe; background:#eff6ff;">
    <div style="font-weight:700; margin-bottom:6px; color:#1e3a8a;">Want help understanding this?</div>
    <div style="font-size:14px; color:#334155; line-height:1.6; margin-bottom:12px;">
        We recommend speaking directly with one of our team so we can explain what options may be available based on what we&rsquo;ve found.
    </div>
    <div style="display:flex; flex-wrap:wrap; gap:10px;">
        @if(!blank($portalWhatsAppUrl))
            <a href="{{ $portalWhatsAppUrl }}"
               style="display:inline-block; text-decoration:none; padding:10px 14px; border-radius:10px; background:#16a34a; color:#ffffff; font-size:14px; font-weight:700;">
                Message us now
            </a>
        @endif
        @if(!blank($portalCallUrl))
            <a href="{{ $portalCallUrl }}"
               style="display:inline-block; text-decoration:none; padding:10px 14px; border-radius:10px; background:#1d4ed8; color:#ffffff; font-size:14px; font-weight:700;">
                Speak to our team
            </a>
        @endif
    </div>
</div>
