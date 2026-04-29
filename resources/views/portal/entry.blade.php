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
        @if (($progress->current_step ?? 'welcome') === 'welcome')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Secure customer portal
            </div>

            <h1 style="margin:0 0 12px 0; font-size:34px; line-height:1.15;">
                Let&rsquo;s build a clear picture of your situation
            </h1>

            <p style="margin:0 0 24px 0; color:#475569; font-size:17px; line-height:1.6;">
                We&rsquo;ll guide you through a few simple steps so you can see where things stand.
            </p>

            <div style="display:grid; grid-template-columns:1fr; gap:12px; margin-bottom:24px;">
                <div style="padding:14px 16px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc;">
                    You can use rough estimates
                </div>
                <div style="padding:14px 16px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc;">
                    You can come back to this link for 30 days
                </div>
                <div style="padding:14px 16px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc;">
                    We&rsquo;ll close this session once you&rsquo;re finished
                </div>
            </div>

            <form method="POST" action="{{ route('portal.welcome.complete', ['token' => $rawToken]) }}">
                @csrf
                <button
                    type="submit"
                    style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;"
                >
                    Start
                </button>
            </form>
        @else
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#fef3c7; color:#92400e; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Next step
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Your next step is coming soon
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:17px; line-height:1.6;">
                You&rsquo;ve completed welcome. We&rsquo;re preparing the next step now.
            </p>

            <div style="padding:16px 18px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc; color:#334155;">
                Current step: <strong>{{ $progress->current_step }}</strong>
            </div>
        @endif
    </div>
</div>
</body>
</html>
