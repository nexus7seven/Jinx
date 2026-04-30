<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Completed</title>
</head>
<body style="margin:0; background:#f8fafc; color:#0f172a; font-family:Arial, sans-serif; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;">
<div style="max-width:560px; width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; padding:32px; box-shadow:0 20px 50px rgba(15,23,42,.08); text-align:center;">
    <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dcfce7; color:#166534; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
        Complete
    </div>

    <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
        You&rsquo;re all set
    </h1>
    @if(!empty($emailedAt))
        <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">
            We&rsquo;ve sent your summary by email. If you&rsquo;d like help understanding what this means, message us now and one of the team can talk you through your options.
        </p>
    @elseif(!empty($hadEmailAddress))
        <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">
            We&rsquo;ve saved your summary and will send it by email shortly. If you&rsquo;d like help understanding what this means, message us now and one of the team can talk you through your options.
        </p>
    @else
        <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">
            We&rsquo;ve saved your summary. If you&rsquo;d like help understanding what this means, message us now.
        </p>
    @endif

    @php
        $portalWhatsAppUrl = config('services.portal.whatsapp_url');
        $portalCallUrl = config('services.portal.call_url');
    @endphp
    <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:10px; margin-top:14px;">
        @if(!blank($portalWhatsAppUrl))
            <a href="{{ $portalWhatsAppUrl }}"
               style="display:inline-block; text-decoration:none; padding:10px 14px; border-radius:10px; background:#16a34a; color:#ffffff; font-size:14px; font-weight:700;">
                Message us on WhatsApp
            </a>
        @endif
        @if(!blank($portalCallUrl))
            <a href="{{ $portalCallUrl }}"
               style="display:inline-block; text-decoration:none; padding:10px 14px; border-radius:10px; background:#1d4ed8; color:#ffffff; font-size:14px; font-weight:700;">
                Call us
            </a>
        @endif
    </div>
</div>
</body>
</html>
