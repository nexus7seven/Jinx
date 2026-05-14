<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Lead</title>
</head>
<body style="margin:0; background:#0b1220; color:#f8fafc; font-family:Arial, sans-serif; min-height:100vh;">

<div style="max-width:720px; margin:0 auto; padding:40px 20px;">
    <div style="background:#111827; border:1px solid #1f2937; border-radius:18px; padding:24px; box-shadow:0 18px 40px rgba(0,0,0,.35);">
        <h1 style="margin:0 0 8px 0; font-size:28px;">Partner Lead Submission</h1>
        <p style="margin:0 0 24px 0; color:#94a3b8;">
            Submitting for {{ $partner->name }}
        </p>

        @if ($errors->any())
            <div style="margin-bottom:18px; padding:14px; border-radius:12px; background:#3f1d1d; border:1px solid #7f1d1d; color:#fecaca;">
                <div style="font-weight:bold; margin-bottom:8px;">Please fix the following:</div>
                <ul style="margin:0; padding-left:18px;">
                    @foreach ($errors->all() as $error)
                        <li style="margin-bottom:4px;">{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('partner.lead.store', ['token' => $partner->token]) }}">
            @csrf

            <div style="margin-bottom:14px;">
                <label style="display:block; margin-bottom:6px; font-size:14px;">Title</label>
                <select name="title"
                    style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #334155; background:#0f172a; color:#f8fafc; font-size:14px;">
                    <option value="" @selected(old('title', '') === '')>Select title</option>
                    @foreach (\App\Models\Lead::TITLES as $t)
                        <option value="{{ $t }}" @selected(old('title') === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div>
                    <label style="display:block; margin-bottom:6px; font-size:14px;">First name</label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required
                        style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #334155; background:#0f172a; color:#f8fafc;">
                </div>

                <div>
                    <label style="display:block; margin-bottom:6px; font-size:14px;">Last name</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required
                        style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #334155; background:#0f172a; color:#f8fafc;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div>
                    <label style="display:block; margin-bottom:6px; font-size:14px;">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone') }}" required
                        style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #334155; background:#0f172a; color:#f8fafc;">
                </div>

                @unless($partner->minimal_submission_form)
                    <div>
                        <label style="display:block; margin-bottom:6px; font-size:14px;">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}"
                            style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #334155; background:#0f172a; color:#f8fafc;">
                    </div>
                @endunless
            </div>

            @unless($partner->minimal_submission_form)
                <div style="margin-bottom:14px;">
                    <label style="display:block; margin-bottom:6px; font-size:14px;">Address</label>
                    <input type="text" name="address1" value="{{ old('address1') }}"
                        style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #334155; background:#0f172a; color:#f8fafc;">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; margin-bottom:6px; font-size:14px;">Postcode</label>
                    <input type="text" name="postcode" value="{{ old('postcode') }}"
                        style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #334155; background:#0f172a; color:#f8fafc;">
                </div>
            @endunless

            <div style="margin-bottom:18px;">
                <label style="display:block; margin-bottom:6px; font-size:14px;">Notes</label>
                <textarea name="notes" rows="6"
                    style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #334155; background:#0f172a; color:#f8fafc; resize:vertical;">{{ old('notes') }}</textarea>
            </div>

            <input type="text" name="website" value="" tabindex="-1" autocomplete="off"
                style="position:absolute; left:-9999px; opacity:0; pointer-events:none;">

            <button type="submit"
                style="width:100%; padding:14px 18px; border:none; border-radius:14px; background:#22c55e; color:#052e16; font-size:16px; font-weight:700; cursor:pointer;">
                {{ $partner->single_stage_submission ? 'Submit Lead' : 'Continue to Debts' }}
            </button>
        </form>
    </div>
</div>

</body>
</html>