<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Lead Debts</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body style="margin:0; font-family:Arial, sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">

<div style="max-width:980px; margin:0 auto; padding:20px 20px 40px 20px; box-sizing:border-box;">

    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin:0 0 16px 0;">
        <div>
            <div style="font-size:13px; color:#94a3b8; margin-bottom:6px;">Partner submission</div>
            <h1 style="margin:0; font-size:28px; line-height:1.2;">{{ $lead->first_name }} {{ $lead->last_name }}</h1>
        </div>

        <a
            href="{{ route('partner.lead.complete', ['token' => $partner->token, 'lead' => $lead->id]) }}"
            style="display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:10px 16px; border-radius:12px; background:#22c55e; color:#052e16; text-decoration:none; font-size:14px; font-weight:700;"
        >
            Finish Submission
        </a>
    </div>

    @if(session('message'))
        <div style="margin-bottom:16px; background:#1f2937; border:1px solid #374151; color:#facc15; border-radius:12px; padding:14px; font-weight:700;">
            {{ session('message') }}
        </div>
    @endif

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:20px; box-sizing:border-box; margin-bottom:20px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Lead ID</div>
                <div style="font-size:18px; font-weight:700;">{{ $lead->id }}</div>
            </div>

            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Phone</div>
                <div style="font-size:18px; font-weight:700;">{{ $lead->phone_number ?: '—' }}</div>
            </div>

            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Email</div>
                <div style="font-size:18px; font-weight:700; word-break:break-word;">{{ $lead->email ?: '—' }}</div>
            </div>

            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Postcode</div>
                <div style="font-size:18px; font-weight:700;">{{ $lead->postcode ?: '—' }}</div>
            </div>
        </div>
    </div>

    @include('partials.financial-statement-card')

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:22px; box-sizing:border-box; margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <h2 style="margin:0; font-size:24px;">Debts</h2>

            <button
                type="button"
                id="openDebtModal"
                style="background:#2563eb; color:#ffffff; border:0; border-radius:8px; padding:12px 16px; font-size:14px; cursor:pointer;"
            >
                Add Debt
            </button>
        </div>

        <div style="margin-bottom:16px; background:#020617; border:1px solid #374151; border-radius:10px; padding:14px; color:#94a3b8; font-size:13px;">
            Add the customer’s debts below. You can create, edit, and delete entries before finishing the submission.
        </div>

        <div id="debtList">
            @forelse($lead->debts as $debt)
                <div
                    class="debt-row"
                    id="debt-row-{{ $debt->id }}"
                    data-debt-id="{{ $debt->id }}"
                    style="background:#020617; border:1px solid #374151; border-radius:12px; padding:16px; margin-bottom:12px;"
                >
                    <div style="display:flex; justify-content:space-between; gap:14px; align-items:flex-start; flex-wrap:wrap;">
                        <div style="flex:1; min-width:240px;">
                            <div style="font-size:18px; font-weight:700; margin-bottom:8px;" class="debt-creditor-name">{{ $debt->creditor->name }}</div>

                            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
                                <span style="background:#1e293b; border:1px solid #334155; color:#e5e7eb; padding:6px 10px; border-radius:999px; font-size:12px;">
                                    £<span class="debt-balance">{{ number_format((float) $debt->balance, 2) }}</span>
                                </span>

                                <span style="background:#1d4ed8; color:#ffffff; padding:6px 10px; border-radius:999px; font-size:12px;" class="debt-source-tag">
                                    {{ match($debt->source_expected) {
                                        'credit_check' => 'Credit check',
                                        '3wc' => '3WC',
                                        'screenshot_pdf' => 'Screenshot / PDF',
                                        'live_chat' => 'Live chat',
                                        default => 'Other',
                                    } }}
                                </span>
                            </div>

                            <div style="font-size:13px; color:#9ca3af;">
                                Ref:
                                <span class="debt-reference">{{ $debt->reference ?: '—' }}</span>
                            </div>
                        </div>

                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button
                                type="button"
                                class="edit-debt-btn"
                                data-debt-id="{{ $debt->id }}"
                                style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:8px; padding:10px 12px; font-size:13px; cursor:pointer;"
                            >
                                Edit
                            </button>

                            <button
                                type="button"
                                class="delete-debt-btn"
                                data-debt-id="{{ $debt->id }}"
                                style="background:#7f1d1d; color:#ffffff; border:0; border-radius:8px; padding:10px 12px; font-size:13px; cursor:pointer;"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            @empty
                <div id="noDebtsMessage" style="background:#020617; border:1px dashed #374151; border-radius:12px; padding:18px; color:#9ca3af;">
                    No debts added yet.
                </div>
            @endforelse
        </div>

        <div style="margin-top:18px; display:flex; justify-content:flex-end;">
            <a
                href="{{ route('partner.lead.complete', ['token' => $partner->token, 'lead' => $lead->id]) }}"
                style="display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:10px 16px; border-radius:12px; background:#22c55e; color:#052e16; text-decoration:none; font-size:14px; font-weight:700;"
            >
                Finish Submission
            </a>
        </div>
    </div>
</div>

<div
    id="debtModalOverlay"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:999;"
></div>

<div
    id="debtModal"
    style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:min(92vw, 560px); background:#111827; border:1px solid #374151; border-radius:16px; padding:22px; z-index:1000; box-sizing:border-box;"
>
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px;">
        <h3 id="debtModalTitle" style="margin:0; font-size:22px;">Add Debt</h3>
        <button
            type="button"
            id="closeDebtModal"
            style="background:transparent; color:#9ca3af; border:0; font-size:22px; cursor:pointer;"
        >
            ×
        </button>
    </div>

    <div id="debtModalError" style="display:none; margin-bottom:14px; background:#3f1d1d; border:1px solid #7f1d1d; color:#fecaca; border-radius:10px; padding:12px;"></div>

    <form id="addDebtForm">
        <input type="hidden" id="editingDebtId" value="">
        <input type="hidden" id="creditorId" name="creditor_id" value="">

        <div style="display:flex; flex-direction:column; gap:14px;">

            <div style="position:relative;">
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Creditor</label>
                <input
                    type="text"
                    id="creditorSearch"
                    placeholder="Search creditor..."
                    autocomplete="off"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb;"
                >
                <div
                    id="creditorResults"
                    style="display:none; position:absolute; top:100%; left:0; right:0; margin-top:6px; background:#111827; border:1px solid #374151; border-radius:10px; overflow:hidden; max-height:220px; overflow-y:auto; z-index:1100;"
                ></div>
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Balance (£)</label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    id="debtBalance"
                    name="balance"
                    required
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb;"
                >
            </div>



            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Reference (optional)</label>
                <input
                    type="text"
                    id="debtReference"
                    name="reference"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb;"
                >
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:18px;">
            <button
                type="button"
                id="cancelDebtModal"
                style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:8px; padding:12px 14px; font-size:14px; cursor:pointer;"
            >
                Cancel
            </button>

            <button
                type="submit"
                id="submitDebtBtn"
                style="background:#2563eb; color:#ffffff; border:0; border-radius:8px; padding:12px 16px; font-size:14px; cursor:pointer;"
            >
                Create Debt
            </button>
        </div>
    </form>
</div>

<script>
    const creditors = @json(
        $creditors->map(fn($creditor) => [
            'id' => $creditor->id,
            'name' => $creditor->name,
        ])->values()
    );

    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const debtList = document.getElementById('debtList');
    const debtModal = document.getElementById('debtModal');
    const debtModalOverlay = document.getElementById('debtModalOverlay');
    const openDebtModal = document.getElementById('openDebtModal');
    const closeDebtModal = document.getElementById('closeDebtModal');
    const cancelDebtModal = document.getElementById('cancelDebtModal');
    const addDebtForm = document.getElementById('addDebtForm');
    const debtModalError = document.getElementById('debtModalError');
    const creditorSearch = document.getElementById('creditorSearch');
    const creditorId = document.getElementById('creditorId');
    const creditorResults = document.getElementById('creditorResults');
    const submitDebtBtn = document.getElementById('submitDebtBtn');
    const debtModalTitle = document.getElementById('debtModalTitle');
    const editingDebtId = document.getElementById('editingDebtId');
    const debtBalance = document.getElementById('debtBalance');
    const debtReference = document.getElementById('debtReference');

@php
    $storeDebtUrl = route('partner.lead.debts.store', [
        'token' => $partner->token,
        'lead' => $lead->id,
    ]);

    $updateDebtBaseUrl = route('partner.lead.debts.update', [
        'token' => $partner->token,
        'lead' => $lead->id,
        'debt' => '__DEBT__',
    ]);

    $deleteDebtBaseUrl = route('partner.lead.debts.delete', [
        'token' => $partner->token,
        'lead' => $lead->id,
        'debt' => '__DEBT__',
    ]);
@endphp

const storeDebtUrl = @json($storeDebtUrl);
const updateDebtBaseUrl = @json($updateDebtBaseUrl);
const deleteDebtBaseUrl = @json($deleteDebtBaseUrl);

    function sourceLabel(source) {
        const map = {
            credit_check: 'Credit check',
            '3wc': '3WC',
            screenshot_pdf: 'Screenshot / PDF',
            live_chat: 'Live chat',
            other: 'Other'
        };

        return map[source] || source;
    }

    function replaceDebtId(url, debtId) {
        return url.replace('__DEBT__', debtId);
    }

    function clearCreditorSelection() {
        creditorId.value = '';
    }

    function resetCreditorPicker() {
        creditorSearch.value = '';
        creditorId.value = '';
        creditorResults.innerHTML = '';
        creditorResults.style.display = 'none';
    }

    function renderCreditorResults(matches) {
        creditorResults.innerHTML = '';

        if (!matches.length) {
            creditorResults.style.display = 'none';
            return;
        }

        matches.forEach(creditor => {
            const item = document.createElement('button');
            item.type = 'button';
            item.textContent = creditor.name;
            item.style.display = 'block';
            item.style.width = '100%';
            item.style.textAlign = 'left';
            item.style.background = '#111827';
            item.style.color = '#f9fafb';
            item.style.border = '0';
            item.style.borderBottom = '1px solid #1f2937';
            item.style.padding = '12px 14px';
            item.style.cursor = 'pointer';

            item.addEventListener('click', function () {
                creditorSearch.value = creditor.name;
                creditorId.value = creditor.id;
                creditorResults.style.display = 'none';
            });

            creditorResults.appendChild(item);
        });

        creditorResults.style.display = 'block';
    }

    function findCreditorMatches(query) {
        return creditors
            .filter(c => c.name.toLowerCase().includes(query))
            .slice(0, 8);
    }

    function setDebtModalMode(mode) {
        if (mode === 'edit') {
            debtModalTitle.textContent = 'Edit Debt';
            submitDebtBtn.textContent = 'Save Changes';
        } else {
            debtModalTitle.textContent = 'Add Debt';
            submitDebtBtn.textContent = 'Create Debt';
            editingDebtId.value = '';
        }
    }

    function showDebtModal(mode = 'add') {
        setDebtModalMode(mode);
        debtModal.style.display = 'block';
        debtModalOverlay.style.display = 'block';
        debtModalError.style.display = 'none';
        debtModalError.textContent = '';
    }

    function hideDebtModal() {
        debtModal.style.display = 'none';
        debtModalOverlay.style.display = 'none';
        addDebtForm.reset();
        editingDebtId.value = '';
        debtModalError.style.display = 'none';
        debtModalError.textContent = '';
        setDebtModalMode('add');
        resetCreditorPicker();
    }

    function buildDebtRowHtml(debt) {
        return `
            <div style="display:flex; justify-content:space-between; gap:14px; align-items:flex-start; flex-wrap:wrap;">
                <div style="flex:1; min-width:240px;">
                    <div style="font-size:18px; font-weight:700; margin-bottom:8px;" class="debt-creditor-name">${debt.creditor_name}</div>

                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
                        <span style="background:#1e293b; border:1px solid #334155; color:#e5e7eb; padding:6px 10px; border-radius:999px; font-size:12px;">
                            £<span class="debt-balance">${Number(debt.balance).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </span>

                        <span style="background:#1d4ed8; color:#ffffff; padding:6px 10px; border-radius:999px; font-size:12px;" class="debt-source-tag">
                            ${sourceLabel(debt.source_expected)}
                        </span>
                    </div>

                    <div style="font-size:13px; color:#9ca3af;">
                        Ref: <span class="debt-reference">${debt.reference ? debt.reference : '—'}</span>
                    </div>
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button
                        type="button"
                        class="edit-debt-btn"
                        data-debt-id="${debt.id}"
                        style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:8px; padding:10px 12px; font-size:13px; cursor:pointer;"
                    >
                        Edit
                    </button>

                    <button
                        type="button"
                        class="delete-debt-btn"
                        data-debt-id="${debt.id}"
                        style="background:#7f1d1d; color:#ffffff; border:0; border-radius:8px; padding:10px 12px; font-size:13px; cursor:pointer;"
                    >
                        Delete
                    </button>
                </div>
            </div>
        `;
    }

    function applyDebtRowData(row, debt) {
        row.dataset.debtId = debt.id;
        row.innerHTML = buildDebtRowHtml(debt);
    }

    function buildDebtRow(debt) {
        const noDebtsMessage = document.getElementById('noDebtsMessage');
        if (noDebtsMessage) {
            noDebtsMessage.remove();
        }

        const row = document.createElement('div');
        row.className = 'debt-row';
        row.id = 'debt-row-' + debt.id;
        row.style.background = '#020617';
        row.style.border = '1px solid #374151';
        row.style.borderRadius = '12px';
        row.style.padding = '16px';
        row.style.marginBottom = '12px';

        applyDebtRowData(row, debt);

        debtList.prepend(row);
        bindEditButtons();
        bindDeleteButtons();
    }

    function updateDebtRow(debt) {
        const row = document.getElementById('debt-row-' + debt.id);
        if (!row) return;

        applyDebtRowData(row, debt);
        bindEditButtons();
        bindDeleteButtons();
    }

    async function openEditDebtModal(debtId) {
        const row = document.getElementById('debt-row-' + debtId);
        if (!row) return;

        debtModalError.style.display = 'none';
        debtModalError.textContent = '';

        const creditorName = row.querySelector('.debt-creditor-name')?.textContent?.trim() || '';
        const balance = row.querySelector('.debt-balance')?.textContent?.replace(/,/g, '') || '';
        const reference = row.querySelector('.debt-reference')?.textContent?.trim() || '';
        const sourceTag = row.querySelector('.debt-source-tag')?.textContent?.trim() || '';

        addDebtForm.reset();
        resetCreditorPicker();
        editingDebtId.value = debtId;
        showDebtModal('edit');

        creditorSearch.value = creditorName;
        const matchedCreditor = creditors.find(c => c.name === creditorName);
        creditorId.value = matchedCreditor ? matchedCreditor.id : '';

        debtBalance.value = balance;
        debtReference.value = reference === '—' ? '' : reference;

        const reverseSourceMap = {
            'Credit check': 'credit_check',
            '3WC': '3wc',
            'Screenshot / PDF': 'screenshot_pdf',
            'Live chat': 'live_chat',
            'Other': 'other',
        };

        sourceExpected.value = reverseSourceMap[sourceTag] || 'other';
    }

    openDebtModal.addEventListener('click', function () {
        addDebtForm.reset();
        editingDebtId.value = '';
        resetCreditorPicker();
        showDebtModal('add');
    });

    closeDebtModal.addEventListener('click', hideDebtModal);
    cancelDebtModal.addEventListener('click', hideDebtModal);
    debtModalOverlay.addEventListener('click', hideDebtModal);

    creditorSearch.addEventListener('input', function () {
        const q = this.value.toLowerCase().trim();

        clearCreditorSelection();

        if (!q) {
            creditorResults.innerHTML = '';
            creditorResults.style.display = 'none';
            return;
        }

        renderCreditorResults(findCreditorMatches(q));
    });

    creditorSearch.addEventListener('focus', function () {
        const q = this.value.toLowerCase().trim();
        if (!q) return;
        renderCreditorResults(findCreditorMatches(q));
    });

    document.addEventListener('click', function (e) {
        if (!creditorSearch.contains(e.target) && !creditorResults.contains(e.target)) {
            creditorResults.style.display = 'none';
        }
    });

    addDebtForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        debtModalError.style.display = 'none';
        debtModalError.textContent = '';

        if (!creditorId.value) {
            debtModalError.style.display = 'block';
            debtModalError.textContent = 'Please select a creditor from the search results.';
            return;
        }

        const isEdit = !!editingDebtId.value;

        submitDebtBtn.disabled = true;
        submitDebtBtn.textContent = isEdit ? 'Saving...' : 'Creating...';

        const payload = {
            creditor_id: creditorId.value,
            balance: debtBalance.value,
            reference: debtReference.value
        };

        try {
            const url = isEdit
                ? replaceDebtId(updateDebtBaseUrl, editingDebtId.value)
                : storeDebtUrl;

            const method = isEdit ? 'PUT' : 'POST';

            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                throw new Error(isEdit ? 'Update failed' : 'Create failed');
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(isEdit ? 'Update failed' : 'Create failed');
            }

            if (isEdit) {
                updateDebtRow(data.debt);
            } else {
                buildDebtRow(data.debt);
            }

            hideDebtModal();
        } catch (error) {
            debtModalError.style.display = 'block';
            debtModalError.textContent = isEdit
                ? 'Could not update debt. Check the fields and try again.'
                : 'Could not create debt. Check the fields and try again.';
        } finally {
            submitDebtBtn.disabled = false;
            submitDebtBtn.textContent = isEdit ? 'Save Changes' : 'Create Debt';
        }
    });

    function bindEditButtons() {
        document.querySelectorAll('.edit-debt-btn').forEach(button => {
            if (button.dataset.bound === '1') return;

            button.dataset.bound = '1';

            button.addEventListener('click', function () {
                const debtId = this.dataset.debtId;
                openEditDebtModal(debtId);
            });
        });
    }

    function bindDeleteButtons() {
        document.querySelectorAll('.delete-debt-btn').forEach(button => {
            if (button.dataset.bound === '1') return;

            button.dataset.bound = '1';

            button.addEventListener('click', async function () {
                const debtId = this.dataset.debtId;

                if (!confirm('Delete this debt?')) {
                    return;
                }

                try {
                    const response = await fetch(replaceDebtId(deleteDebtBaseUrl, debtId), {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json'
                        }
                    });

                    const data = await response.json();

                    if (!data.success) {
                        throw new Error();
                    }

                    const row = document.getElementById('debt-row-' + debtId);
                    if (row) {
                        row.remove();
                    }

                    if (!document.querySelector('.debt-row')) {
                        const empty = document.createElement('div');
                        empty.id = 'noDebtsMessage';
                        empty.style.background = '#020617';
                        empty.style.border = '1px dashed #374151';
                        empty.style.borderRadius = '12px';
                        empty.style.padding = '18px';
                        empty.style.color = '#9ca3af';
                        empty.textContent = 'No debts added yet.';
                        debtList.appendChild(empty);
                    }
                } catch (e) {
                    alert('Delete failed');
                }
            });
        });
    }

    bindEditButtons();
    bindDeleteButtons();
</script>

@include('partials.financial-statement-init', [
    'financialStatementSaveUrl' => route('partner.lead.financial-statement.update', ['token' => $partner->token, 'lead' => $lead->id]),
])

</body>
</html>