<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lead Submitted</title>
</head>
<body style="margin:0; background:#0b1220; color:#f8fafc; font-family:Arial, sans-serif; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;">

<div style="max-width:560px; width:100%; background:#111827; border:1px solid #1f2937; border-radius:18px; padding:28px; text-align:center; box-shadow:0 18px 40px rgba(0,0,0,.35);">

    <h1 style="margin:0 0 10px 0; font-size:30px;">
        Lead Submitted
    </h1>

    <p style="margin:0 0 18px 0; color:#94a3b8;">
        {{ $partner->name }}
    </p>

    {{-- Success / duplicate message --}}
    @if(session('message'))
        <div style="
            margin:0 0 20px 0;
            padding:14px;
            border-radius:12px;
            font-weight:700;
            background:#1f2937;
            border:1px solid #374151;
            color:#facc15;
        ">
            {{ session('message') }}
        </div>
    @else
        <div style="
            margin:0 0 20px 0;
            padding:14px;
            border-radius:12px;
            background:#14532d;
            border:1px solid #166534;
            color:#dcfce7;
            font-weight:700;
        ">
            Lead submitted successfully
        </div>
    @endif

    <p style="margin:0 0 24px 0; color:#94a3b8; font-size:14px;">
        You can submit another lead below.
    </p>

    <a href="{{ route('partner.lead.create', ['token' => $partner->token]) }}"
       style="display:inline-block; padding:12px 18px; border-radius:12px; background:#22c55e; color:#052e16; font-weight:700; text-decoration:none;">
        Submit Another Lead
    </a>

</div>

</body>
</html>