@extends('portal.layout')

@section('title', 'Verify Your Details')

@section('content')
<div class="portal-card" style="max-width:760px; margin:0 auto;">
    <h1 style="margin:0 0 12px 0; font-size:34px; line-height:1.15;">Let&rsquo;s keep your details private</h1>
    <p style="margin:0 0 24px 0; color:#475569; font-size:17px; line-height:1.6;">Before we continue, please confirm one detail so we know it&rsquo;s you.</p>

    @if ($errors->has('verification'))
        <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:14px; font-weight:700;">{{ $errors->first('verification') }}</div>
    @endif

    <form method="POST" action="{{ route('portal.verify', ['token' => request()->route('token')]) }}">
        @csrf
        <div style="margin-bottom:16px; padding:12px 14px; border:1px solid #dbeafe; border-radius:12px; background:#eff6ff; color:#1e3a8a; font-size:14px;">For your security, we only ask for postcode verification before opening your portal.</div>
        <div style="margin-bottom:22px;">
            <label for="postcode" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Postcode</label>
            <input id="postcode" name="postcode" type="text" value="{{ old('postcode') }}" autocomplete="postal-code" style="width:100%; padding:12px 14px; border-radius:14px; border:1px solid #cbd5e1;">
        </div>
        <button type="submit" style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#fff; font-size:16px; font-weight:700; cursor:pointer;">Continue</button>
    </form>
</div>
@endsection
