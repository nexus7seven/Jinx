<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $partner->name }} Leads</title>
</head>
<body style="margin:0; background:#0b1220; color:#f9fafb; font-family:Arial,sans-serif;">
<div style="max-width:1000px; margin:20px auto; padding:20px; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
        <h1 style="margin:0;">{{ $partner->name }} Lead Report</h1>

        <form method="POST" action="{{ route('partner-portal.logout') }}" style="margin:0;">
            @csrf
            <button
                type="submit"
                style="background:#374151; color:#fff; border:0; border-radius:8px; padding:10px; cursor:pointer;"
            >
                Logout
            </button>
        </form>
    </div>

    <p style="color:#94a3b8;">Status field used: <strong>{{ $statusFieldUsed }}</strong></p>

    <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; margin:14px 0;">
        <input
            type="date"
            name="from"
            value="{{ $from }}"
            style="padding:10px; border-radius:8px; background:#020617; border:1px solid #374151; color:#f9fafb;"
        >

        <input
            type="date"
            name="to"
            value="{{ $to }}"
            style="padding:10px; border-radius:8px; background:#020617; border:1px solid #374151; color:#f9fafb;"
        >

        <button
            type="submit"
            style="padding:10px 14px; background:#2563eb; color:#fff; border:0; border-radius:8px; cursor:pointer;"
        >
            Apply dates
        </button>
    </form>

    <input
        id="leadSearch"
        placeholder="Instant search name, phone, status, submitted by, feedback"
        style="width:100%; padding:11px; background:#020617; border:1px solid #374151; color:#f9fafb; border-radius:8px; box-sizing:border-box;"
    >

    <div id="leadCards" style="display:flex; flex-direction:column; gap:12px; margin-top:14px;">
        @forelse($leads as $lead)
            <div
                class="lead-card"
                data-search="{{ strtolower(trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '') . ' ' . ($lead->phone_number ?? '') . ' ' . ($lead->wip_status ?? '') . ' ' . ($lead->submitted_by_vicidial_user ?? '') . ' ' . ($lead->lead_feedback ?? ''))) }}"
                style="background:#111827; border:1px solid #374151; border-radius:12px; padding:14px;"
            >
                <div><strong>Date received:</strong> {{ optional($lead->created_at)->format('Y-m-d H:i') }}</div>
                <div><strong>Customer name:</strong> {{ trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')) ?: '—' }}</div>
                <div><strong>Phone:</strong> {{ $lead->phone_number ?: '—' }}</div>
                <div><strong>Status:</strong> {{ $lead->wip_status ?: '—' }}</div>
                <div><strong>Submitted by:</strong> {{ $lead->submitted_by_vicidial_user ?: '—' }}</div>
                <div><strong>Lead feedback notes:</strong> {{ $lead->lead_feedback ?: '—' }}</div>
            </div>
        @empty
            <div
                id="emptyState"
                style="padding:16px; background:#111827; border:1px dashed #374151; border-radius:10px; color:#94a3b8;"
            >
                No leads found for this date range.
            </div>
        @endforelse
    </div>
</div>

<script>
    const searchInput = document.getElementById('leadSearch');
    const leadCards = [...document.querySelectorAll('.lead-card')];
    const emptyState = document.getElementById('emptyState');

    function filterLeads() {
        const query = (searchInput.value || '').toLowerCase();
        let visibleCount = 0;

        leadCards.forEach((card) => {
            const isMatch = card.dataset.search.includes(query);
            card.style.display = isMatch ? 'block' : 'none';
            if (isMatch) visibleCount++;
        });

        if (emptyState) {
            emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterLeads);
    }
</script>
</body>
</html>
