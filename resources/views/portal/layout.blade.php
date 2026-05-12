<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Clear My Credit Portal')</title>
    <style>
        :root { --cmc-blue:#1d4ed8; --cmc-blue-dark:#1e3a8a; --cmc-ink:#0f172a; --cmc-muted:#475569; --cmc-bg:#f8fafc; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--cmc-bg); color:var(--cmc-ink); font-family: Inter, Arial, sans-serif; min-height:100vh; }
        .portal-shell { max-width: 860px; margin: 0 auto; padding: 22px 16px 36px; }
        .portal-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
        .portal-logo { background:#fff; border:1px solid #dbe3ef; border-radius:14px; padding:10px 14px; box-shadow:0 8px 20px rgba(15,23,42,.05); }
        .portal-logo img { display:block; max-width:210px; width:100%; height:auto; }
        .portal-secure { font-size:12px; font-weight:700; color:var(--cmc-blue-dark); background:#dbeafe; border-radius:999px; padding:8px 12px; text-transform:uppercase; letter-spacing:.04em; }
        .portal-card { background:#fff; border:1px solid #e2e8f0; border-radius:24px; padding:26px; box-shadow:0 16px 36px rgba(15,23,42,.08); }
        @keyframes portal-credit-spinner { to { transform: rotate(360deg); } }
        @keyframes portal-credit-shimmer {
            0% { transform: translateX(-120%); }
            100% { transform: translateX(240%); }
        }
        @media (max-width: 640px) { .portal-card { padding:20px; border-radius:18px; } .portal-logo img { max-width:170px; } }
    </style>
    @stack('head')
</head>
<body>
<div class="portal-shell">
    <div class="portal-header">
        <div class="portal-logo"><img src="/assets/img/cmc-horizontal-logo.svg" alt="Clear My Credit"></div>
        <div class="portal-secure">Secure Portal</div>
    </div>
    @yield('content')
</div>
@stack('scripts')
</body>
</html>
