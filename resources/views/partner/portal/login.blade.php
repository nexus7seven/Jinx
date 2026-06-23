<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Portal Login</title>
</head>
<body style="margin:0; background:#0b1220; color:#f9fafb; font-family:Arial,sans-serif;">
<div style="max-width:420px; margin:80px auto; padding:24px; background:#111827; border:1px solid #374151; border-radius:14px;">
    <h1 style="margin:0 0 18px; font-size:24px;">Partner Portal</h1>

    @if($errors->any())
        <div style="margin-bottom:12px; color:#fecaca; background:#3f1d1d; border:1px solid #7f1d1d; padding:10px; border-radius:8px;">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('partner-portal.login.attempt') }}">
        @csrf

<label for="portal_email">Username</label>
<input
    id="portal_email"
    type="text"
    name="email"
    value="{{ old('email') }}"
    required
            style="width:100%; padding:10px; margin:6px 0 12px; background:#020617; border:1px solid #374151; color:#f9fafb; border-radius:8px; box-sizing:border-box;"
        >

        <label for="portal_password">Password</label>
        <input
            id="portal_password"
            type="password"
            name="password"
            required
            style="width:100%; padding:10px; margin:6px 0 12px; background:#020617; border:1px solid #374151; color:#f9fafb; border-radius:8px; box-sizing:border-box;"
        >

        <button
            type="submit"
            style="width:100%; padding:11px; background:#2563eb; border:0; border-radius:8px; color:#fff; font-weight:700; cursor:pointer;"
        >
            Log in
        </button>
    </form>
</div>
</body>
</html>
