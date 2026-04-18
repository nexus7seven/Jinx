@php
    $isWip = request()->routeIs('wip.*');
    $isRemarketing = request()->routeIs('remarketing.*');
@endphp
<nav aria-label="Main" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid #1e293b;">
    <a href="{{ route('wip.index') }}"
       style="position:relative; display:inline-flex; align-items:center; gap:6px; text-decoration:none; padding:10px 14px; border-radius:10px; border:1px solid {{ $isWip ? '#2563eb' : '#374151' }}; background:{{ $isWip ? '#1d4ed8' : '#111827' }}; color:#fff; font-size:14px; font-weight:600;">
        <span>WIP</span>
        @if (($unseenReengagementCount ?? 0) > 0)
            @php
                $reN = (int) $unseenReengagementCount;
                $reLabel = $reN > 99 ? '99+' : (string) $reN;
            @endphp
            <span
                aria-label="{{ $reN }} unseen re-engagement{{ $reN === 1 ? '' : 's' }}"
                title="Unseen re-engagements"
                style="display:inline-flex; align-items:center; justify-content:center; min-width:1.25rem; height:1.25rem; padding:0 0.35rem; border-radius:999px; background:#dc2626; color:#fff; font-size:11px; font-weight:800; line-height:1; box-shadow:0 0 0 2px rgba(15,23,42,0.9);"
            >{{ $reLabel }}</span>
        @endif
    </a>
    <a href="{{ route('remarketing.index') }}"
       style="text-decoration:none; padding:10px 14px; border-radius:10px; border:1px solid {{ $isRemarketing ? '#2563eb' : '#374151' }}; background:{{ $isRemarketing ? '#1d4ed8' : '#111827' }}; color:#fff; font-size:14px; font-weight:600;">
        Remarketing
    </a>
</nav>
