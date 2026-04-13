<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jinx WIP</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        .wip-card {
            background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
            border: 1px solid #1e293b;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .wip-card__row1 {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .wip-card__title {
            margin: 0;
            min-width: 0;
            flex: 1;
        }
        .wip-card__title a {
            color: #f8fafc;
            text-decoration: none;
            font-size: 18px;
            font-weight: 600;
            line-height: 1.35;
            letter-spacing: -0.01em;
            display: inline-block;
            word-break: break-word;
        }
        .wip-card__title a:hover {
            color: #e2e8f0;
        }
        .wip-card-actions {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        .wip-card__badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
        }
        .wip-card-actions .jinx-ctc-btn {
            min-width: 4.25rem;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            color: #e2e8f0;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        .wip-card-actions .jinx-ctc-btn:hover {
            background: #273549;
            border-color: #475569;
        }
        .wip-card__meta {
            font-size: 11px;
            line-height: 1.55;
            color: #64748b;
            padding-top: 2px;
            border-top: 1px solid rgba(51, 65, 85, 0.5);
        }
        .wip-meta-k { color: #64748b; font-weight: 500; }
        .wip-meta-v { color: #94a3b8; font-weight: 400; }
        .wip-meta-dot { color: #3f4f63; margin: 0 0.28em; user-select: none; }
        .wip-meta-pill {
            display: inline-block;
            vertical-align: middle;
            margin: 1px 0 1px 0.35em;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(30, 41, 59, 0.9);
            border: 1px solid #334155;
            color: #cbd5e1;
        }
        .wip-card__controls {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: stretch;
        }
        .wip-card__controls .status-select {
            flex: 1;
            min-width: 200px;
            background: #0f172a;
            color: #f1f5f9;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            font-weight: 500;
        }
        .wip-card__controls .open-checklist-btn {
            background: transparent;
            color: #cbd5e1;
            border: 1px solid #475569;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .wip-card__controls .open-checklist-btn:hover {
            background: #1e293b;
            border-color: #64748b;
            color: #f1f5f9;
        }
        @keyframes wip-priority-glow {
            0%, 100% { box-shadow: 0 0 0 1px rgba(37,99,235,0.22), 0 0 20px rgba(37,99,235,0.06); }
            50% { box-shadow: 0 0 0 1px rgba(59,130,246,0.35), 0 0 24px rgba(59,130,246,0.1); }
        }
        .wip-card-priority {
            animation: wip-priority-glow 4.5s ease-in-out infinite;
            border-color: rgba(59, 130, 246, 0.45) !important;
        }
        @keyframes wip-undialled-pulse {
            0%, 100% {
                box-shadow:
                    0 0 0 2px rgba(245, 158, 11, 0.42),
                    0 0 22px rgba(245, 158, 11, 0.12);
            }
            50% {
                box-shadow:
                    0 0 0 2px rgba(251, 191, 36, 0.58),
                    0 0 34px rgba(251, 191, 36, 0.18);
            }
        }
        .wip-card-undialled-attention {
            animation: wip-undialled-pulse 2.4s ease-in-out infinite;
            border-color: rgba(245, 158, 11, 0.72) !important;
            background: linear-gradient(165deg, #1c1412 0%, #0f172a 55%, #0c1424 100%) !important;
        }
        .wip-card__badge--undialled {
            background: linear-gradient(135deg, #9a3412 0%, #c2410c 100%);
            border: 1px solid #fbbf24;
            color: #fffbeb;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-size: 10px;
            box-shadow: 0 0 14px rgba(251, 191, 36, 0.25);
        }
        #wip-refresh-btn.is-spinning svg {
            animation: wip-spin 0.65s linear infinite;
        }
        @keyframes wip-spin { to { transform: rotate(360deg); } }
        #wip-ops-toast {
            display: none;
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10000;
            max-width: min(520px, calc(100vw - 32px));
            background: #1e293b;
            border: 1px solid #334155;
            color: #f8fafc;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.45);
        }
    </style>
</head>
<body style="margin:0; font-family: Arial, sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">

<div style="max-width:980px; margin:0 auto; padding:20px; box-sizing:border-box;">

    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:12px;">
            <div>
                <div style="font-size:26px; font-weight:700;">WIP</div>
                <div style="font-size:13px; color:#9ca3af; margin-top:4px;">Case queue</div>
            </div>
            <button type="button" id="wip-refresh-btn" title="Reload"
                    style="display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; border-radius:10px; border:1px solid #374151; background:#111827; color:#e5e7eb; cursor:pointer;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M21 12a9 9 0 1 1-2.64-6.36"/>
                    <path d="M21 3v7h-7"/>
                </svg>
            </button>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ url('/wip?show=active') }}"
               style="text-decoration:none; padding:10px 14px; border-radius:10px; border:1px solid {{ $show === 'active' ? '#2563eb' : '#374151' }}; background:{{ $show === 'active' ? '#1d4ed8' : '#111827' }}; color:#fff; font-size:14px;">
                Active
            </a>

            <a href="{{ url('/wip?show=all') }}"
               style="text-decoration:none; padding:10px 14px; border-radius:10px; border:1px solid {{ $show === 'all' ? '#2563eb' : '#374151' }}; background:{{ $show === 'all' ? '#1d4ed8' : '#111827' }}; color:#fff; font-size:14px;">
                All
            </a>
        </div>
    </div>

    <div id="wip-ops-toast" role="status"></div>

    <div id="wip-filter-bar" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:14px;">
        <input
            type="search"
            id="wip-filter-name"
            autocomplete="off"
            placeholder="Filter by name…"
            aria-label="Filter leads by name"
            style="flex:1; min-width:180px; max-width:320px; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
        >
        <select
            id="wip-filter-status"
            aria-label="Filter by status"
            style="min-width:200px; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
        >
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}">{{ $status }}</option>
            @endforeach
        </select>
        <select
            id="wip-filter-source"
            aria-label="Filter by source"
            style="min-width:200px; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#f9fafb; font-size:14px;"
        >
            <option value="">All sources</option>
            @foreach($wip_source_filter_options ?? [] as $rawSource)
                @if($rawSource === '')
                    <option value="__EMPTY__">{{ \App\Support\LeadSourceDisplay::label(null) }}</option>
                @else
                    <option value="{{ $rawSource }}">{{ \App\Support\LeadSourceDisplay::label($rawSource) }}</option>
                @endif
            @endforeach
        </select>
    </div>

    <div id="wip-filter-no-matches" style="display:none; margin-bottom:12px; background:#111827; border:1px solid #374151; border-radius:16px; padding:18px; color:#9ca3af;">
        No cases match the current filters.
    </div>

    <div id="wip-leads-grid" style="display:grid; gap:12px;">
        @forelse($leads as $lead)
            @php
                $caseName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));
                if ($caseName === '') {
                    $caseName = 'Lead #' . $lead->id;
                }

                $outstanding = (int) ($lead->checklist_outstanding_count ?? 0);
                $pillBg = $outstanding === 0 ? '#065f46' : '#92400e';
                $pillBorder = $outstanding === 0 ? '#10b981' : '#f59e0b';
                $sourceLabel = \App\Support\LeadSourceDisplay::label($lead->source);
                $sourceRaw = $lead->source === null ? '' : trim((string) $lead->source);
                $lastDialled = $lead->last_dialled_at;
                $lastDialledText = $lastDialled ? $lastDialled->format('d M Y, H:i') : 'Never dialled';
                $createdText = $lead->created_at
                    ? $lead->created_at->format('j M Y, H:i')
                    : '—';
                $canCall = (bool) trim((string) ($lead->phone_number ?? ''));
                $needsImmediateAttention = $lead->needsImmediateAttention();
            @endphp

            <div
                class="wip-card wip-lead-row {{ $needsImmediateAttention ? 'wip-card-undialled-attention' : ($lead->isPriorityWip() ? 'wip-card-priority' : '') }}"
                data-lead-id="{{ $lead->id }}"
                data-lead-name="{{ strtolower($caseName) }}"
                data-wip-status="{{ $lead->wip_status }}"
                data-lead-source="{{ e($sourceRaw) }}"
            >
                <div class="wip-card__row1">
                    <div class="wip-card__title">
                        <a href="{{ url('/lead/' . $lead->id) }}">{{ $caseName }}</a>
                    </div>
                    <div class="wip-card-actions">
                        @if ($needsImmediateAttention)
                            <span class="wip-card__badge wip-card__badge--undialled" title="Priority intake, never dialled, created within {{ \App\Models\Lead::IMMEDIATE_ATTENTION_FRESH_HOURS }}h">New undialled</span>
                        @endif
                        <span id="outstanding-pill-{{ $lead->id }}"
                              class="wip-card__badge"
                              style="background:{{ $pillBg }}; border:1px solid {{ $pillBorder }}; color:#f8fafc;">
                            {{ $outstanding }} outstanding
                        </span>
                        @if ($canCall)
                            @include('partials.lead-click-to-call', ['lead' => $lead])
                        @endif
                    </div>
                </div>

                <div class="wip-card__meta">
                    <span class="wip-meta-k">Created</span> <span class="wip-meta-v">{{ $createdText }}</span>
                    <span class="wip-meta-dot" aria-hidden="true">·</span>
                    <span class="wip-meta-k">Last dialled</span> <span class="wip-meta-v">{{ $lastDialledText }}</span>
                    <span class="wip-meta-dot" aria-hidden="true">·</span>
                    <span class="wip-meta-pill">{{ $sourceLabel }}</span>
                </div>

                <div class="wip-card__controls">
                    <select
                        data-lead-id="{{ $lead->id }}"
                        class="status-select">
                        @foreach($statuses as $status)
                            <option value="{{ $status }}" {{ $lead->wip_status === $status ? 'selected' : '' }}>
                                {{ $status }}
                            </option>
                        @endforeach
                    </select>

                    <button
                        type="button"
                        class="open-checklist-btn"
                        data-lead-id="{{ $lead->id }}"
                        data-case-name="{{ $caseName }}">
                        Checklist
                    </button>
                </div>
            </div>
        @empty
            <div style="background:#111827; border:1px solid #374151; border-radius:16px; padding:18px; color:#9ca3af;">
                No cases found.
            </div>
        @endforelse
    </div>
</div>

<div id="checklist-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9999; padding:16px; box-sizing:border-box;">
    <div style="max-width:720px; margin:30px auto; background:#111827; border:1px solid #374151; border-radius:18px; overflow:hidden;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px; border-bottom:1px solid #374151;">
            <div>
                <div id="modal-case-name" style="font-size:20px; font-weight:700;">Checklist</div>
                <div id="modal-subtitle" style="font-size:12px; color:#9ca3af; margin-top:4px;">0 outstanding</div>
            </div>
            <button type="button" id="close-modal-btn" style="background:#1f2937; color:#fff; border:1px solid #374151; border-radius:10px; padding:10px 12px; cursor:pointer;">Close</button>
        </div>

        <div style="padding:16px;">
            <div style="display:flex; gap:8px; margin-bottom:14px;">
                <input
                    type="text"
                    id="new-item-name"
                    placeholder="Add checklist item"
                    style="flex:1; background:#0f172a; color:#fff; border:1px solid #374151; border-radius:10px; padding:12px; font-size:14px;"
                >
                <button
                    type="button"
                    id="add-item-btn"
                    style="background:#10b981; color:#fff; border:none; border-radius:10px; padding:12px 14px; font-weight:700; cursor:pointer;">
                    Add
                </button>
            </div>

            <div id="checklist-items" style="display:flex; flex-direction:column; gap:10px;"></div>
        </div>
    </div>
</div>

<script>
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const wipOpsAlertLeads = @json($ops_alert_leads ?? []);

    const wipFilterNameInput = document.getElementById('wip-filter-name');
    const wipFilterStatus = document.getElementById('wip-filter-status');
    const wipFilterSource = document.getElementById('wip-filter-source');
    const wipFilterNoMatches = document.getElementById('wip-filter-no-matches');

    function applyWipFilters() {
        if (!wipFilterNameInput || !wipFilterStatus || !wipFilterSource) return;

        const q = (wipFilterNameInput.value || '').trim().toLowerCase();
        const st = wipFilterStatus.value;
        const src = wipFilterSource.value;

        const rows = document.querySelectorAll('.wip-lead-row');
        let visible = 0;

        rows.forEach(function (card) {
            const name = card.dataset.leadName || '';
            const okName = !q || name.includes(q);
            const okStatus = !st || card.dataset.wipStatus === st;
            let okSource = true;
            if (src) {
                if (src === '__EMPTY__') {
                    okSource = (card.dataset.leadSource || '') === '';
                } else {
                    okSource = (card.dataset.leadSource || '') === src;
                }
            }
            const show = okName && okStatus && okSource;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (wipFilterNoMatches) {
            wipFilterNoMatches.style.display = (rows.length > 0 && visible === 0) ? 'block' : 'none';
        }
    }

    if (wipFilterNameInput) {
        wipFilterNameInput.addEventListener('input', applyWipFilters);
    }
    if (wipFilterStatus) {
        wipFilterStatus.addEventListener('change', applyWipFilters);
    }
    if (wipFilterSource) {
        wipFilterSource.addEventListener('change', applyWipFilters);
    }

    const modal = document.getElementById('checklist-modal');
    const modalCaseName = document.getElementById('modal-case-name');
    const modalSubtitle = document.getElementById('modal-subtitle');
    const checklistItemsWrap = document.getElementById('checklist-items');
    const newItemNameInput = document.getElementById('new-item-name');
    const addItemBtn = document.getElementById('add-item-btn');
    const closeModalBtn = document.getElementById('close-modal-btn');

    let currentLeadId = null;
    let currentCaseName = '';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text ?? '';
        return div.innerHTML;
    }

    function updateOutstandingPill(leadId, outstanding) {
        const pill = document.getElementById(`outstanding-pill-${leadId}`);
        if (!pill) return;

        pill.textContent = `${outstanding} outstanding`;

        if (outstanding === 0) {
            pill.style.background = '#065f46';
            pill.style.border = '1px solid #10b981';
        } else {
            pill.style.background = '#92400e';
            pill.style.border = '1px solid #f59e0b';
        }
    }

    function updateStatusSelect(leadId, status) {
        const select = document.querySelector(`.status-select[data-lead-id="${leadId}"]`);
        if (select) {
            select.value = status;
            select.setAttribute('data-original', status);
        }
        const card = document.querySelector(`.wip-lead-row[data-lead-id="${leadId}"]`);
        if (card) {
            card.dataset.wipStatus = status;
        }
        applyWipFilters();
    }

    function renderChecklist(items, counts) {
        modalSubtitle.textContent = `${counts.outstanding} outstanding`;

        if (!items.length) {
            checklistItemsWrap.innerHTML = `
                <div style="padding:14px; border:1px dashed #374151; border-radius:12px; color:#9ca3af; text-align:center;">
                    No checklist items yet.
                </div>
            `;
            return;
        }

        checklistItemsWrap.innerHTML = items.map(item => `
            <div data-item-id="${item.id}" style="background:#0f172a; border:1px solid #374151; border-radius:12px; padding:12px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" class="toggle-item" ${item.is_complete ? 'checked' : ''} style="width:18px; height:18px;">

                    <input
                        type="text"
                        class="item-name-input"
                        value="${escapeHtml(item.item_name)}"
                        style="flex:1; background:#111827; color:#fff; border:1px solid #374151; border-radius:8px; padding:10px; font-size:14px; text-decoration:${item.is_complete ? 'line-through' : 'none'};"
                    >

                    <button
                        type="button"
                        class="save-item-btn"
                        style="background:#2563eb; color:#fff; border:none; border-radius:8px; padding:10px 12px; font-size:13px; font-weight:700; cursor:pointer;">
                        Save
                    </button>

                    <button
                        type="button"
                        class="delete-item-btn"
                        style="background:#b91c1c; color:#fff; border:none; border-radius:8px; padding:10px 12px; font-size:13px; font-weight:700; cursor:pointer;">
                        Delete
                    </button>
                </div>
            </div>
        `).join('');
    }

    async function loadChecklist(leadId, caseName) {
        currentLeadId = leadId;
        currentCaseName = caseName;
        modalCaseName.textContent = caseName;
        modalSubtitle.textContent = 'Loading...';
        checklistItemsWrap.innerHTML = '<div style="color:#9ca3af;">Loading...</div>';
        newItemNameInput.value = '';

        modal.style.display = 'block';

        const response = await fetch(`/lead/${leadId}/checklist`, {
            headers: {
                'Accept': 'application/json',
            }
        });

        const data = await response.json();
        renderChecklist(data.items, data.counts);
        updateOutstandingPill(leadId, data.counts.outstanding);
        updateStatusSelect(leadId, data.lead.wip_status);
    }

    async function refreshChecklist() {
        if (!currentLeadId) return;
        await loadChecklist(currentLeadId, currentCaseName);
    }

    document.querySelectorAll('.open-checklist-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            await loadChecklist(btn.dataset.leadId, btn.dataset.caseName);
        });
    });

    closeModalBtn.addEventListener('click', () => {
        modal.style.display = 'none';
        currentLeadId = null;
        currentCaseName = '';
    });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.style.display = 'none';
            currentLeadId = null;
            currentCaseName = '';
        }
    });

    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', async () => {
            const leadId = select.dataset.leadId;
            const originalValue = select.getAttribute('data-original') || select.value;
            const newValue = select.value;

            try {
                const response = await fetch(`/lead/${leadId}/wip-status`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        wip_status: newValue
                    })
                });

                if (!response.ok) {
                    throw new Error('Failed to update status');
                }

                const data = await response.json();
                updateStatusSelect(leadId, data.wip_status);
            } catch (error) {
                alert('Could not update status.');
                select.value = originalValue;
            }
        });

        select.setAttribute('data-original', select.value);
    });

    addItemBtn.addEventListener('click', async () => {
        const itemName = newItemNameInput.value.trim();

        if (!currentLeadId || !itemName) {
            return;
        }

        const response = await fetch(`/lead/${currentLeadId}/checklist`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                item_name: itemName
            })
        });

        if (!response.ok) {
            alert('Could not add item.');
            return;
        }

        const data = await response.json();
        newItemNameInput.value = '';
        updateOutstandingPill(currentLeadId, data.counts.outstanding);
        updateStatusSelect(currentLeadId, data.wip_status);
        await refreshChecklist();
    });

    checklistItemsWrap.addEventListener('click', async (e) => {
        const itemRow = e.target.closest('[data-item-id]');
        if (!itemRow || !currentLeadId) return;

        const itemId = itemRow.dataset.itemId;

        if (e.target.classList.contains('save-item-btn')) {
            const input = itemRow.querySelector('.item-name-input');
            const itemName = input.value.trim();

            if (!itemName) {
                alert('Item name cannot be empty.');
                return;
            }

            const response = await fetch(`/lead/${currentLeadId}/checklist/${itemId}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    item_name: itemName
                })
            });

            if (!response.ok) {
                alert('Could not save item.');
                return;
            }

            await refreshChecklist();
        }

        if (e.target.classList.contains('delete-item-btn')) {
            const response = await fetch(`/lead/${currentLeadId}/checklist/${itemId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                }
            });

            if (!response.ok) {
                alert('Could not delete item.');
                return;
            }

            const data = await response.json();
            updateOutstandingPill(currentLeadId, data.counts.outstanding);
            updateStatusSelect(currentLeadId, data.wip_status);
            await refreshChecklist();
        }
    });

    checklistItemsWrap.addEventListener('change', async (e) => {
        const itemRow = e.target.closest('[data-item-id]');
        if (!itemRow || !currentLeadId) return;

        if (e.target.classList.contains('toggle-item')) {
            const itemId = itemRow.dataset.itemId;
            const checked = e.target.checked;

            const response = await fetch(`/lead/${currentLeadId}/checklist/${itemId}/toggle`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    is_complete: checked ? 1 : 0
                })
            });

            if (!response.ok) {
                alert('Could not update item.');
                return;
            }

            const data = await response.json();
            updateOutstandingPill(currentLeadId, data.counts.outstanding);
            updateStatusSelect(currentLeadId, data.wip_status);
            await refreshChecklist();
        }
    });

    (function () {
        const refreshBtn = document.getElementById('wip-refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                refreshBtn.classList.add('is-spinning');
                window.location.reload();
            });
        }

        const IDLE_MS = 60000;
        const CHECK_MS = 5000;
        let lastActivity = Date.now();

        function markActive() {
            lastActivity = Date.now();
        }

        ['click', 'keydown', 'scroll', 'touchstart', 'focusin'].forEach(function (ev) {
            window.addEventListener(ev, markActive, { passive: true });
        });

        function isFormFieldFocused() {
            const el = document.activeElement;
            if (!el) return false;
            const tag = el.tagName;
            if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
            if (tag === 'INPUT') {
                const t = el.type || 'text';
                if (t === 'button' || t === 'submit' || t === 'checkbox' || t === 'radio') return false;
                return true;
            }
            return false;
        }

        setInterval(function () {
            if (document.getElementById('checklist-modal') && document.getElementById('checklist-modal').style.display === 'block') {
                return;
            }
            if (isFormFieldFocused()) return;
            if (Date.now() - lastActivity < IDLE_MS) return;
            window.location.reload();
        }, CHECK_MS);

        const BASELINE_KEY = 'jinx_wip_ops_baseline_done';
        const MAX_SEEN_KEY = 'jinx_wip_max_seen_lead_id';

        function playSoftBeep() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                const ctx = new Ctx();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.type = 'sine';
                o.frequency.value = 880;
                g.gain.value = 0.035;
                o.connect(g);
                g.connect(ctx.destination);
                o.start();
                setTimeout(function () {
                    o.stop();
                    ctx.close();
                }, 100);
            } catch (e) {}
        }

        function runOpsAlerts() {
            if (!wipOpsAlertLeads || !wipOpsAlertLeads.length) return;

            const ids = wipOpsAlertLeads.map(function (r) { return r.id; });
            const currentMax = ids.length ? Math.max.apply(null, ids) : 0;

            try {
                if (!localStorage.getItem(BASELINE_KEY)) {
                    localStorage.setItem(MAX_SEEN_KEY, String(currentMax));
                    localStorage.setItem(BASELINE_KEY, '1');
                    return;
                }
            } catch (e) {
                return;
            }

            let storedMax = 0;
            try {
                storedMax = parseInt(localStorage.getItem(MAX_SEEN_KEY) || '0', 10) || 0;
            } catch (e) {}

            const fresh = wipOpsAlertLeads.filter(function (r) {
                return r.eligible && r.id > storedMax;
            });

            if (fresh.length) {
                const toast = document.getElementById('wip-ops-toast');
                if (toast) {
                    toast.style.display = 'block';
                    toast.textContent = fresh.length === 1
                        ? ('New lead: ' + (fresh[0].label || ('#' + fresh[0].id)))
                        : (fresh.length + ' new leads — latest: ' + (fresh[fresh.length - 1].label || ('#' + fresh[fresh.length - 1].id)));
                    setTimeout(function () {
                        toast.style.display = 'none';
                    }, 8000);
                }
                playSoftBeep();
            }

            try {
                localStorage.setItem(MAX_SEEN_KEY, String(Math.max(storedMax, currentMax)));
            } catch (e) {}
        }

        runOpsAlerts();
    })();
</script>

</body>
</html>