@php
    $isWip = request()->routeIs('wip.*');
    $isRemarketing = request()->routeIs('remarketing.*');
@endphp
<style>
    .app-nav {
        margin-bottom: 22px;
        padding-bottom: 18px;
        border-bottom: 1px solid rgba(51, 65, 85, 0.45);
    }
    .app-nav__tabs {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px;
        padding: 3px;
        border-radius: 10px;
        border: 1px solid rgba(51, 65, 85, 0.55);
        background: rgba(15, 23, 42, 0.45);
    }
    .app-nav__tab {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        color: #94a3b8;
        border: 1px solid transparent;
        transition: color 0.15s ease, background 0.15s ease, border-color 0.15s ease;
    }
    .app-nav__tab:hover {
        color: #e2e8f0;
        background: rgba(51, 65, 85, 0.35);
    }
    .app-nav__tab--active {
        color: #f1f5f9;
        background: rgba(59, 130, 246, 0.12);
        border-color: rgba(59, 130, 246, 0.35);
    }
    .app-nav__badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        height: 18px;
        min-width: 18px;
        padding: 0 6px;
        border-radius: 999px;
        background: rgba(239, 68, 68, 0.15);
        border: 1px solid rgba(239, 68, 68, 0.35);
        color: #fca5a5;
        font-size: 10px;
        font-weight: 600;
        line-height: 1;
    }
    .app-nav__badge--dot {
        min-width: 6px;
        width: 6px;
        height: 6px;
        padding: 0;
        border-radius: 50%;
        background: rgba(248, 113, 113, 0.5);
        border: 1px solid rgba(239, 68, 68, 0.35);
    }
</style>
<nav class="app-nav" aria-label="Main">
    <div class="app-nav__tabs">
        <a
            href="{{ route('wip.index') }}"
            class="app-nav__tab {{ $isWip ? 'app-nav__tab--active' : '' }}"
            @if ($isWip) aria-current="page" @endif
        >
            <span>WIP</span>
            @if (($unseenReengagementCount ?? 0) > 0)
                @php
                    $reN = (int) $unseenReengagementCount;
                    $reLabel = $reN > 99 ? '99+' : (string) $reN;
                @endphp
                @if ($reN === 1)
                    <span
                        class="app-nav__badge app-nav__badge--dot"
                        aria-label="1 unseen re-engagement"
                        title="Unseen re-engagements"
                    ></span>
                @else
                    <span
                        class="app-nav__badge"
                        aria-label="{{ $reN }} unseen re-engagements"
                        title="Unseen re-engagements"
                    >{{ $reLabel }}</span>
                @endif
            @endif
        </a>
        <a
            href="{{ route('remarketing.index') }}"
            class="app-nav__tab {{ $isRemarketing ? 'app-nav__tab--active' : '' }}"
            @if ($isRemarketing) aria-current="page" @endif
        >
            Remarketing
        </a>
    </div>
</nav>
