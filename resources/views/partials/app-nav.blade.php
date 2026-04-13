@php
    $isWip = request()->routeIs('wip.*');
    $isRemarketing = request()->routeIs('remarketing.*');
@endphp
<nav aria-label="Main" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid #1e293b;">
    <a href="{{ route('wip.index') }}"
       style="text-decoration:none; padding:10px 14px; border-radius:10px; border:1px solid {{ $isWip ? '#2563eb' : '#374151' }}; background:{{ $isWip ? '#1d4ed8' : '#111827' }}; color:#fff; font-size:14px; font-weight:600;">
        WIP
    </a>
    <a href="{{ route('remarketing.index') }}"
       style="text-decoration:none; padding:10px 14px; border-radius:10px; border:1px solid {{ $isRemarketing ? '#2563eb' : '#374151' }}; background:{{ $isRemarketing ? '#1d4ed8' : '#111827' }}; color:#fff; font-size:14px; font-weight:600;">
        Remarketing
    </a>
</nav>
