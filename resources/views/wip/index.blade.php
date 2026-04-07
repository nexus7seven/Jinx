<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jinx WIP</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body style="margin:0; font-family: Arial, sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">

<div style="max-width:980px; margin:0 auto; padding:20px; box-sizing:border-box;">

    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap;">
        <div>
            <div style="font-size:26px; font-weight:700;">WIP</div>
            <div style="font-size:13px; color:#9ca3af; margin-top:4px;">Case queue</div>
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

    <div style="display:grid; gap:12px;">
        @forelse($leads as $lead)
            @php
                $caseName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));
                if ($caseName === '') {
                    $caseName = 'Lead #' . $lead->id;
                }

                $outstanding = (int) ($lead->checklist_outstanding_count ?? 0);
                $pillBg = $outstanding === 0 ? '#065f46' : '#92400e';
                $pillBorder = $outstanding === 0 ? '#10b981' : '#f59e0b';
            @endphp

            <div style="background:#111827; border:1px solid #374151; border-radius:16px; padding:14px;">
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                        <div style="min-width:0; flex:1;">
                            <a href="{{ url('/lead/' . $lead->id) }}"
                               style="color:#f9fafb; text-decoration:none; font-size:18px; font-weight:700; display:inline-block; word-break:break-word;">
                                {{ $caseName }}
                            </a>
                            <div style="font-size:12px; color:#9ca3af; margin-top:6px;">
                                Lead ID: {{ $lead->id }}
                            </div>
                        </div>

                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <span id="outstanding-pill-{{ $lead->id }}"
                                  style="display:inline-flex; align-items:center; padding:8px 12px; border-radius:999px; font-size:13px; font-weight:700; background:{{ $pillBg }}; border:1px solid {{ $pillBorder }}; color:#fff;">
                                {{ $outstanding }} outstanding
                            </span>
                        </div>
                    </div>

                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <select
                            data-lead-id="{{ $lead->id }}"
                            class="status-select"
                            style="flex:1; min-width:180px; background:#0f172a; color:#f9fafb; border:1px solid #374151; border-radius:10px; padding:12px; font-size:14px;">
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
                            data-case-name="{{ $caseName }}"
                            style="background:#2563eb; color:#fff; border:none; border-radius:10px; padding:12px 14px; font-size:14px; font-weight:700; cursor:pointer;">
                            Checklist
                        </button>
                    </div>
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
        }
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
                select.setAttribute('data-original', data.wip_status);
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
</script>

</body>
</html>