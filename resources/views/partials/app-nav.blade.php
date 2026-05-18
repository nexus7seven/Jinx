@php
    $isWip = request()->routeIs('wip.*');
    $isRemarketing = request()->routeIs('remarketing.*');
    $isDialDashboard = request()->routeIs('reports.data-dialling-dashboard');
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
    .app-nav__search-wrap {
        position: relative;
        display: inline-flex;
        flex-direction: column;
        min-width: min(280px, 85vw);
        margin-left: 6px;
    }
    .app-nav__search-box {
        width: 100%;
        box-sizing: border-box;
        padding: 7px 9px;
        border-radius: 8px;
        border: 1px solid rgba(71, 85, 105, 0.75);
        background: rgba(15, 23, 42, 0.92);
        color: #f8fafc;
        font-size: 12px;
    }
    .app-nav__search-box::placeholder {
        color: #64748b;
    }
    .app-nav__search-results {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        display: none;
        border: 1px solid rgba(51, 65, 85, 0.75);
        border-radius: 10px;
        background: #0f172a;
        overflow: hidden;
        z-index: 40;
        box-shadow: 0 14px 32px rgba(2, 6, 23, 0.55);
    }
    .app-nav__search-result {
        display: block;
        padding: 9px 10px;
        text-decoration: none;
        border-bottom: 1px solid rgba(51, 65, 85, 0.35);
        color: #cbd5e1;
    }
    .app-nav__search-result:last-child {
        border-bottom: none;
    }
    .app-nav__search-result:hover {
        background: rgba(51, 65, 85, 0.35);
        color: #f8fafc;
    }
    .app-nav__search-name {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: inherit;
    }
    .app-nav__search-meta {
        display: block;
        margin-top: 2px;
        font-size: 11px;
        color: #94a3b8;
    }
    .app-nav__search-empty {
        padding: 9px 10px;
        font-size: 12px;
        color: #94a3b8;
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
                <span
                    class="app-nav__badge"
                    aria-label="{{ $reN }} unseen re-engagement{{ $reN === 1 ? '' : 's' }}"
                    title="Unseen re-engagements"
                >{{ $reLabel }}</span>
            @endif
        </a>
        <a
            href="{{ route('remarketing.index') }}"
            class="app-nav__tab {{ $isRemarketing ? 'app-nav__tab--active' : '' }}"
            @if ($isRemarketing) aria-current="page" @endif
        >
            Remarketing
        </a>
        <a
            href="{{ route('reports.data-dialling-dashboard') }}"
            class="app-nav__tab {{ $isDialDashboard ? 'app-nav__tab--active' : '' }}"
            @if ($isDialDashboard) aria-current="page" @endif
        >
            Dialling Dashboard
        </a>
        <div class="app-nav__search-wrap" id="appNavSearchWrap">
            <input
                type="search"
                id="appNavSearchInput"
                class="app-nav__search-box"
                placeholder="Search by first name, surname, phone or VICIdial lead ID"
                autocomplete="off"
                aria-label="Search leads"
            >
            <div class="app-nav__search-results" id="appNavSearchResults" aria-live="polite"></div>
        </div>
    </div>
</nav>
@include('partials.lead-search-script')
<script>
    window.initJinxLeadSearch({
        containerId: 'appNavSearchWrap',
        inputId: 'appNavSearchInput',
        resultsId: 'appNavSearchResults',
        minLength: 2,
        debounceMs: 250,
        searchUrl: '{{ route('lead.search') }}',
        emptyClass: 'app-nav__search-empty',
        resultClass: 'app-nav__search-result',
        nameClass: 'app-nav__search-name',
        metaClass: 'app-nav__search-meta',
        noResultsText: 'No matching leads found',
    });
</script>
