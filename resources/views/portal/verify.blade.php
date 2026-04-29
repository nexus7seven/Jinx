<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Details</title>
</head>
<body style="margin:0; background:#f8fafc; color:#0f172a; font-family:Arial, sans-serif; min-height:100vh;">
<div style="max-width:760px; margin:0 auto; padding:40px 20px;">
    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; padding:32px; box-shadow:0 20px 50px rgba(15,23,42,.08);">
        <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
            Secure customer portal
        </div>

        <h1 style="margin:0 0 12px 0; font-size:34px; line-height:1.15;">
            Let&rsquo;s keep your details private
        </h1>

        <p style="margin:0 0 24px 0; color:#475569; font-size:17px; line-height:1.6;">
            Before we continue, please confirm one detail so we know it&rsquo;s you.
        </p>

        @if ($errors->has('verification'))
            <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:14px; font-weight:700;">
                {{ $errors->first('verification') }}
            </div>
        @endif

        <form method="POST" action="{{ route('portal.verify', ['token' => request()->route('token')]) }}">
            @csrf

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:22px;">
                <div>
                    <label for="dob" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">
                        Date of birth
                    </label>
                    <input
                        id="dob"
                        name="dob"
                        type="date"
                        value="{{ old('dob') }}"
                        style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:14px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;"
                    >
                </div>

                <div>
                    <label for="postcode" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">
                        Postcode
                    </label>
                    <input
                        id="postcode"
                        name="postcode"
                        type="text"
                        value="{{ old('postcode') }}"
                        autocomplete="postal-code"
                        style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:14px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;"
                    >
                </div>
            </div>

            <button
                type="submit"
                style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;"
            >
                Continue
            </button>
        </form>
    </div>
</div>
</body>
</html>
