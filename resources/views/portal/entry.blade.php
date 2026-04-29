<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Portal</title>
</head>
<body style="margin:0; background:#f8fafc; color:#0f172a; font-family:Arial, sans-serif; min-height:100vh;">
<div style="max-width:760px; margin:0 auto; padding:40px 20px;">
    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; padding:32px; box-shadow:0 20px 50px rgba(15,23,42,.08);">
        <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
            Secure customer portal
        </div>

        <h1 style="margin:0 0 12px 0; font-size:34px; line-height:1.15;">
            Let&rsquo;s build a clear picture of your situation
        </h1>

        <p style="margin:0 0 24px 0; color:#475569; font-size:17px; line-height:1.6;">
            This secure link lets you continue your details.
        </p>

        <div style="padding:18px 20px; border-radius:18px; background:#f8fafc; border:1px solid #e2e8f0; margin-bottom:22px;">
            <div style="font-size:13px; color:#64748b; margin-bottom:6px; text-transform:uppercase; letter-spacing:.04em;">
                Current step
            </div>
            <div style="font-size:22px; font-weight:700; color:#0f172a;">
                {{ $progress->current_step ?: 'welcome' }}
            </div>
        </div>

        <p style="margin:0; color:#64748b; font-size:14px; line-height:1.6;">
            We&rsquo;ll guide you through the next steps here. No forms are shown on this page yet.
        </p>
    </div>
</div>
</body>
</html>
