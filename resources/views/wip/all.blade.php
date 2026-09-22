<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>All Jinx leads</title>
    <style>
        body{margin:0;background:#0a0f1a;color:#f8fafc;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
        .wip-page{max-width:1680px;margin:0 auto;padding:18px 28px 28px;box-sizing:border-box}
        .wip-header-block{display:flex;flex-direction:column;gap:14px;margin-bottom:20px}
        .wip-header-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px}
        .wip-header-title{font-size:22px;letter-spacing:-.03em;font-weight:650}.wip-header-sub{font-size:12px;color:#64748b;margin-top:3px}
        .wip-refresh-btn{width:36px;height:36px;border:1px solid #475569;border-radius:8px;background:#172033;color:#cbd5e1;cursor:pointer}
        .wip-scope-segment{display:inline-flex;border:1px solid #475569;border-radius:8px;overflow:hidden;width:max-content}
        .wip-scope-segment__link{padding:5px 12px;color:#94a3b8;text-decoration:none;font-size:12px;border-right:1px solid #334155}
        .wip-scope-segment__link:last-child{border:0}.wip-scope-segment__link--active{background:rgba(59,130,246,.18);color:#f8fafc;font-weight:700}
        .wip-directory{max-width:1120px;margin:0 auto}.wip-directory__heading{display:flex;align-items:end;justify-content:space-between;gap:12px;margin-bottom:13px}
        .wip-directory h1{margin:0;font-size:24px;letter-spacing:-.03em}.wip-directory__hint{margin:5px 0 0;color:#94a3b8;font-size:12px}
        .wip-filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:15px;padding:12px;border:1px solid #334155;border-radius:10px;background:#0f172a}
        .wip-filter-bar input,.wip-filter-bar select{box-sizing:border-box;border:1px solid #475569;border-radius:8px;background:#0b1220;color:#f8fafc;padding:10px 12px;font:inherit;font-size:13px;min-width:170px}
        .wip-filter-bar input[type=search]{flex:1;min-width:220px}.wip-filter-bar input::placeholder{color:#64748b}
        .wip-filter-clear{font-size:12px;color:#93c5fd;text-decoration:none}
        .wip-all-summary{font-size:12px;color:#94a3b8;margin-bottom:9px}
        .wip-all-list{display:flex;flex-direction:column;gap:7px}
        .wip-all-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 15px;border:1px solid #293649;border-radius:9px;background:#111827;color:inherit;text-decoration:none;transition:background .15s,border-color .15s}
        .wip-all-row:hover,.wip-all-row:focus-visible{border-color:#60a5fa;background:#18253b}
        .wip-all-row__main{min-width:0;display:flex;flex-direction:column;gap:5px}
        .wip-all-row__name{font-weight:700;font-size:14px;color:#f8fafc}
        .wip-all-row__details{display:flex;flex-wrap:wrap;gap:5px 13px;font-size:11px;color:#94a3b8;overflow-wrap:anywhere}
        .wip-all-row__status{flex-shrink:0;font-size:11px;color:#cbd5e1;border:1px solid #475569;border-radius:999px;padding:5px 9px;background:#0b1220}
        .wip-all-empty{padding:35px 15px;text-align:center;color:#94a3b8;border:1px dashed #475569;border-radius:10px}
        .wip-all-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:16px;font-size:12px;color:#94a3b8}
        .wip-all-pagination a{padding:8px 12px;text-decoration:none;color:#bfdbfe;border:1px solid #475569;border-radius:8px;background:#111827}
        .wip-all-pagination a:hover{border-color:#60a5fa}.wip-all-pagination .is-disabled{opacity:.35}
        .wip-all-loading{opacity:.55;pointer-events:none}
        @media(max-width:720px){.wip-page{padding:14px}.wip-all-row{align-items:flex-start}.wip-all-row__status{max-width:110px;white-space:normal;text-align:center}}
    </style>
</head>
<body>
<div class="wip-page">
    @include('partials.app-nav')
    <header class="wip-header-block">
        <div class="wip-header-toolbar">
            <div><div class="wip-header-title">Jinx Workdesk</div><div class="wip-header-sub">All Jinx leads</div></div>
            <button type="button" class="wip-refresh-btn" id="wip-refresh-btn" title="Refresh results" aria-label="Refresh results">↻</button>
        </div>
        <nav class="wip-scope-segment" aria-label="Lead view">
            <a href="{{ url('/wip?show=active') }}" class="wip-scope-segment__link">Active</a>
            <a href="{{ url('/wip?show=all') }}" class="wip-scope-segment__link wip-scope-segment__link--active" aria-current="page">All</a>
        </nav>
    </header>
    <main class="wip-directory">
        <div class="wip-directory__heading">
            <div><h1>All leads</h1><p class="wip-directory__hint">Every Jinx lead, regardless of case status. Search the complete database.</p></div>
        </div>
        <form id="wip-filter-bar" class="wip-filter-bar" action="{{ route('wip.index') }}" method="GET" role="search">
            <input type="hidden" name="show" value="all">
            <input type="search" id="wip-filter-name" name="q" value="{{ $search }}" maxlength="120" autocomplete="off" placeholder="Search name, phone, email or ID…" aria-label="Search all Jinx leads">
            <select id="wip-filter-status" name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                @foreach($statuses as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
                @endforeach
            </select>
            <select id="wip-filter-source" name="source" aria-label="Filter by source">
                <option value="">All sources</option>
                @foreach($wip_source_filter_options as $option)
                    @if($option === '')
                        <option value="__EMPTY__" @selected($source === '__EMPTY__')>{{ \App\Support\LeadSourceDisplay::label(null) }}</option>
                    @else
                        <option value="{{ $option }}" @selected($source === $option)>{{ \App\Support\LeadSourceDisplay::label($option) }}</option>
                    @endif
                @endforeach
            </select>
            <a class="wip-filter-clear" href="{{ url('/wip?show=all') }}">Clear</a>
        </form>
        <div id="wip-all-results" aria-live="polite">
            <div class="wip-all-summary">
                Showing {{ $leads->firstItem() ?? 0 }}–{{ $leads->lastItem() ?? 0 }} of {{ number_format($leads->total()) }} Jinx leads
            </div>
            <div class="wip-all-list">
                @forelse($leads as $lead)
                    @php $name = trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')); @endphp
                    <a class="wip-all-row" href="{{ url('/lead/'.$lead->id) }}" data-lead-id="{{ $lead->id }}">
                        <div class="wip-all-row__main">
                            <span class="wip-all-row__name">{{ $name !== '' ? $name : 'Lead #'.$lead->id }}</span>
                            <span class="wip-all-row__details">
                                <span>Jinx #{{ $lead->id }}</span>
                                @if($lead->phone_number)<span>{{ $lead->phone_number }}</span>@endif
                                @if($lead->email)<span>{{ $lead->email }}</span>@endif
                                <span>{{ \App\Support\LeadSourceDisplay::label($lead->source) }}</span>
                            </span>
                        </div>
                        <span class="wip-all-row__status">{{ $lead->wip_status ?: 'No status' }}</span>
                    </a>
                @empty
                    <div class="wip-all-empty">No leads match your search.</div>
                @endforelse
            </div>
            @if($leads->hasPages())
                <nav class="wip-all-pagination" aria-label="All leads pages">
                    @if($leads->onFirstPage())<span class="is-disabled">← Previous</span>
                    @else<a href="{{ $leads->previousPageUrl() }}" rel="prev">← Previous</a>@endif
                    <span>Page {{ $leads->currentPage() }} of {{ $leads->lastPage() }}</span>
                    @if($leads->hasMorePages())<a href="{{ $leads->nextPageUrl() }}" rel="next">Next →</a>
                    @else<span class="is-disabled">Next →</span>@endif
                </nav>
            @endif
        </div>
    </main>
</div>
@include('wip.assistant')
<script>
(function () {
    const form = document.getElementById('wip-filter-bar');
    const search = document.getElementById('wip-filter-name');
    const results = document.getElementById('wip-all-results');
    let pending = null;
    let controller = null;
    let requestId = 0;

    function filterUrl() {
        const url = new URL(form.action, window.location.origin);
        url.search = new URLSearchParams(new FormData(form)).toString();
        return url;
    }

    async function load(url) {
        const id = ++requestId;
        controller?.abort();
        controller = new AbortController();
        results.classList.add('wip-all-loading');
        try {
            const response = await fetch(url, {
                signal: controller.signal,
                headers: { 'Accept': 'text/html', 'Cache-Control': 'no-cache' },
                cache: 'no-store',
            });
            if (!response.ok) throw new Error('Search unavailable');
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = doc.getElementById('wip-all-results');
            if (!next) throw new Error('Search results unavailable');
            if (id !== requestId) return;
            results.innerHTML = next.innerHTML;
            history.replaceState(null, '', url);
        } catch (error) {
            if (error.name !== 'AbortError' && id === requestId) window.location.assign(url);
        } finally {
            if (id === requestId) results.classList.remove('wip-all-loading');
        }
    }
    function schedule() {
        clearTimeout(pending);
        pending = setTimeout(() => load(filterUrl()), 280);
    }
    form.addEventListener('submit', event => {
        event.preventDefault();
        clearTimeout(pending);
        load(filterUrl());
    });
    search.addEventListener('input', schedule);
    form.querySelectorAll('select').forEach(select => select.addEventListener('change', () => {
        clearTimeout(pending);
        load(filterUrl());
    }));
    results.addEventListener('click', event => {
        const page = event.target.closest('.wip-all-pagination a');
        if (!page) return;
        event.preventDefault();
        load(page.href);
    });
    document.getElementById('wip-refresh-btn').addEventListener('click', () => load(window.location.href));
    window.addEventListener('popstate', () => window.location.reload());
})();
</script>
</body>
</html>
