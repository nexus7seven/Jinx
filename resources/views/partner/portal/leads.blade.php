<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $partner->name }} Leads</title>
    <style>
        :root {
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #0b1220;
            color: #f9fafb;
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .page-wrap {
            width: min(1100px, 100% - 2rem);
            margin: 1.5rem auto 2rem;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .title {
            margin: 0;
            font-size: clamp(1.4rem, 2.5vw, 2rem);
            line-height: 1.2;
        }

        .subtitle {
            margin: .45rem 0 0;
            color: #9ca3af;
            font-size: .95rem;
        }

        .logout-btn {
            border: 1px solid #4b5563;
            background: #1f2937;
            color: #f9fafb;
            border-radius: 10px;
            padding: .62rem .95rem;
            font-weight: 600;
            cursor: pointer;
        }

        .panel {
            background: #111827;
            border: 1px solid #374151;
            border-radius: 14px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: .75rem;
            align-items: end;
        }

        .control,
        .search-input,
        .apply-btn {
            width: 100%;
            border-radius: 10px;
            border: 1px solid #374151;
            padding: .65rem .75rem;
            background: #0f172a;
            color: #f9fafb;
            font-size: .94rem;
        }

        .apply-btn {
            background: #1d4ed8;
            border-color: #2563eb;
            font-weight: 600;
            cursor: pointer;
        }

        .search-row {
            margin-top: .75rem;
        }

        .count-line {
            color: #9ca3af;
            font-size: .9rem;
            margin: .4rem 0 0;
        }

        .lead-list {
            display: grid;
            gap: .9rem;
            margin-top: .9rem;
        }

        .lead-card {
            background: #111827;
            border: 1px solid #374151;
            border-radius: 14px;
            padding: 1rem;
        }

        .lead-top {
            display: flex;
            justify-content: space-between;
            gap: .75rem;
            align-items: flex-start;
            flex-wrap: wrap;
            margin-bottom: .8rem;
        }

        .lead-name {
            margin: 0;
            font-size: 1.05rem;
            line-height: 1.2;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            padding: .28rem .72rem;
            font-size: .77rem;
            font-weight: 700;
            letter-spacing: .02em;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .status-pill {
            background: rgba(59, 130, 246, .15);
            border-color: rgba(96, 165, 250, .4);
            color: #bfdbfe;
        }

        .source-pill {
            background: rgba(245, 158, 11, .14);
            border-color: rgba(251, 191, 36, .35);
            color: #fde68a;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: .6rem .9rem;
        }

        .meta-item {
            color: #d1d5db;
            font-size: .9rem;
        }

        .meta-label {
            color: #9ca3af;
            font-size: .78rem;
            margin-bottom: .15rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .feedback-box {
            margin-top: .85rem;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: .75rem;
        }

        .feedback-label {
            color: #93c5fd;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: .35rem;
        }

        .feedback-text {
            color: #e5e7eb;
            font-size: .92rem;
            line-height: 1.45;
            white-space: pre-wrap;
            margin: 0;
        }

        .feedback-empty {
            color: #6b7280;
            font-size: .92rem;
            font-style: italic;
            margin: 0;
        }


        .notes-wrap {
            position: relative;
            margin-top: .75rem;
            display: inline-block;
        }

        .notes-trigger {
            background: #1f2937;
            border-color: #4b5563;
            color: #cbd5e1;
            cursor: pointer;
        }

        .notes-wrap:focus-within .notes-tooltip,
        .notes-wrap:hover .notes-tooltip {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .notes-tooltip {
            position: absolute;
            left: 0;
            top: calc(100% + .45rem);
            z-index: 20;
            width: max-content;
            max-width: min(420px, 85vw);
            max-height: 220px;
            overflow: auto;
            white-space: pre-wrap;
            background: #0b1220;
            border: 1px solid #475569;
            border-radius: 10px;
            color: #e5e7eb;
            padding: .7rem .8rem;
            font-size: .88rem;
            line-height: 1.4;
            box-shadow: 0 12px 24px rgba(0, 0, 0, .35);
            opacity: 0;
            transform: translateY(-4px);
            pointer-events: none;
            transition: opacity .15s ease, transform .15s ease;
        }

        .notes-muted {
            margin-top: .75rem;
            color: #6b7280;
            font-size: .82rem;
        }

        .empty-state {
            padding: 1rem;
            background: #111827;
            border: 1px dashed #4b5563;
            border-radius: 10px;
            color: #94a3b8;
        }

        @media (max-width: 640px) {
            .page-wrap {
                width: min(1100px, 100% - 1rem);
            }

            .panel,
            .lead-card {
                padding: .85rem;
            }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="top-bar">
        <div>
            <h1 class="title">Lead Report</h1>
            <p class="subtitle">{{ $partner->name }} partner portal · Status field: <strong>{{ $statusFieldUsed }}</strong></p>
        </div>

        <form method="POST" action="{{ route('partner-portal.logout') }}" style="margin:0;">
            @csrf
            <button type="submit" class="logout-btn">Logout</button>
        </form>
    </div>

    <div class="panel">
        <form method="GET">
            <div class="controls-grid">
                <label>
                    <span class="meta-label">From date</span>
                    <input type="date" name="from" value="{{ $from }}" class="control">
                </label>

                <label>
                    <span class="meta-label">To date</span>
                    <input type="date" name="to" value="{{ $to }}" class="control">
                </label>

                <button type="submit" class="apply-btn">Apply dates</button>
            </div>
        </form>

        <div class="search-row">
            <input
                id="leadSearch"
                class="search-input"
                placeholder="Instant search customer name, phone, status, submitted by, feedback, submitted notes"
            >
        </div>
        <p id="leadCount" class="count-line">Showing {{ $leads->count() }} leads</p>
    </div>

    <div id="leadCards" class="lead-list">
        @forelse($leads as $lead)
            @php
                $customerName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? '')) ?: 'Unknown customer';
                $status = $lead->wip_status ?: '—';
                $phone = $lead->phone_number ?: '—';
                $submittedBy = $lead->submitted_by_vicidial_user ?: '—';
                $feedback = trim((string)($lead->lead_feedback ?? ''));
                $caseNotes = trim((string)($lead->case_notes ?? ''));
                $dateReceived = optional($lead->created_at)->format('Y-m-d H:i') ?: '—';
            @endphp
            <article
                class="lead-card"
                data-search="{{ strtolower(trim($customerName . ' ' . $phone . ' ' . $status . ' ' . $submittedBy . ' ' . $feedback . ' ' . $caseNotes)) }}"
            >
                <div class="lead-top">
                    <div>
                        <div class="meta-label" style="margin-bottom:.25rem;">Customer</div>
                        <h2 class="lead-name">{{ $customerName }}</h2>
                    </div>
                    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                        <span class="pill status-pill">{{ $status }}</span>
                        <span class="pill source-pill">{{ $submittedBy }}</span>
                    </div>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="meta-label">Date received</div>
                        <div>{{ $dateReceived }}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Phone</div>
                        <div>{{ $phone }}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Submitted by</div>
                        <div>{{ $submittedBy }}</div>
                    </div>
                </div>

                <div class="feedback-box">
                    <div class="feedback-label">Lead feedback notes</div>
                    @if($feedback !== '')
                        <p class="feedback-text">{{ $feedback }}</p>
                    @else
                        <p class="feedback-empty">No feedback yet</p>
                    @endif
                </div>

                @if($caseNotes !== '')
                    <div class="notes-wrap">
                        <button type="button" class="pill notes-trigger" aria-label="View submitted notes">Submitted notes</button>
                        <div class="notes-tooltip" role="tooltip">{{ $caseNotes }}</div>
                    </div>
                @else
                    <div class="notes-muted">No submitted notes</div>
                @endif
            </article>
        @empty
            <div id="emptyState" class="empty-state">No leads found for this date range.</div>
        @endforelse
    </div>

    <div id="emptyStateSearch" class="empty-state" style="display:none; margin-top:.9rem;">No leads match your current search.</div>
</div>

<script>
    const searchInput = document.getElementById('leadSearch');
    const leadCards = [...document.querySelectorAll('.lead-card')];
    const emptyStateSearch = document.getElementById('emptyStateSearch');
    const leadCount = document.getElementById('leadCount');

    function filterLeads() {
        const query = (searchInput?.value || '').toLowerCase().trim();
        let visibleCount = 0;

        leadCards.forEach((card) => {
            const isMatch = card.dataset.search.includes(query);
            card.style.display = isMatch ? 'block' : 'none';
            if (isMatch) visibleCount++;
        });

        if (emptyStateSearch) {
            emptyStateSearch.style.display = visibleCount === 0 && leadCards.length > 0 ? 'block' : 'none';
        }

        if (leadCount) {
            leadCount.textContent = `Showing ${visibleCount} lead${visibleCount === 1 ? '' : 's'}`;
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterLeads);
        filterLeads();
    }
</script>
</body>
</html>
