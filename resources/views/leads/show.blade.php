<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jinx Lead {{ $lead->id }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body style="margin:0; font-family: Arial, sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">

<div style="max-width:980px; margin:0 auto; padding:20px 20px 40px 20px; box-sizing:border-box;">

    <div style="margin:0 0 12px 0;">
        <a
            href="/wip"
            style="display:inline-flex; align-items:center; gap:6px; min-height:40px; padding:8px 12px; border-radius:10px; border:1px solid #374151; background:#111827; color:#e5e7eb; text-decoration:none; font-size:13px; font-weight:700;"
        >
            <span aria-hidden="true">←</span>
            <span>Back to WIP</span>
        </a>
    </div>

    <div
        id="caseNotesStickyWrap"
        style="position:fixed; top:72px; left:50%; transform:translateX(-50%); width:min(980px, calc(100vw - 40px)); z-index:200; pointer-events:none;"
    >
        <div style="width:fit-content; pointer-events:auto;">
            <div style="display:inline-block; background:#111827; border:1px solid #374151; border-radius:12px; padding:8px; box-shadow:0 10px 30px rgba(0,0,0,0.35);">
                <div style="margin:0;">
                    <button
                        type="button"
                        id="caseNotesToggle"
                        aria-label="Toggle scribble notes"
                        title="Scribble notes"
                        style="display:inline-flex; align-items:center; justify-content:center; width:56px; height:56px; background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:12px; padding:0; font-size:30px; line-height:1; cursor:pointer;"
                    >
                        🗒️
                    </button>
                </div>

                <div
                    id="caseNotesPanel"
                    style="display:none; margin-top:12px; background:#0f172a; border:1px solid #334155; border-radius:12px; padding:14px;"
                >
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                        <h2 style="margin:0; font-size:18px;">Case Notes</h2>
                        <div id="caseNotesStatus" style="font-size:12px; color:#9ca3af;">Ready</div>
                    </div>

                    <textarea
                        id="caseNotesInput"
                        style="width:100%; min-height:220px; resize:vertical; box-sizing:border-box; padding:12px 14px; border-radius:10px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:14px; line-height:1.5; margin-bottom:12px;"
                        placeholder="Write quick scribble notes here..."
                    >{{ old('case_notes', $lead->case_notes ?? '') }}</textarea>

                    <div style="display:flex; justify-content:flex-end;">
                        <button
                            type="button"
                            id="caseNotesSaveBtn"
                            style="min-height:42px; background:#2563eb; color:#ffffff; border:0; border-radius:10px; padding:10px 14px; font-size:13px; font-weight:700; cursor:pointer;"
                        >
                            Save Notes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="caseNotesSpacer" style="height:56px;"></div>

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:28px; box-sizing:border-box; margin-bottom:20px;">
        <h1 style="margin:0 0 18px 0; font-size:28px; line-height:1.2;">Jinx Lead {{ $lead->id }}</h1>

        @php
            $leadDisplayName = $lead->formattedName();
        @endphp
        @if($leadDisplayName !== '')
            <div style="margin:-8px 0 18px 0; font-size:20px; line-height:1.3; font-weight:600; color:#e5e7eb;">
                {{ $leadDisplayName }}
            </div>
        @endif

        <div id="saveStatus" style="margin-bottom:22px; font-size:14px; color:#9ca3af;">
            Ready
        </div>

        <div id="appointmentPrepSection" style="margin:0 0 20px 0; background:#0f172a; border:1px solid #334155; border-radius:12px; padding:14px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                <h2 style="margin:0; font-size:18px;">IVA Appointment Prep</h2>
                <div style="font-size:12px; color:#94a3b8;">Key talking points</div>
            </div>

            <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:12px;">
                <textarea
                    id="actionPointInput"
                    style="width:100%; min-height:80px; resize:vertical; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:14px;"
                    placeholder="Add an appointment prep point..."
                ></textarea>

                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                    <div id="actionPointStatus" style="font-size:12px; color:#9ca3af;">Ready</div>
                    <button
                        type="button"
                        id="addActionPointBtn"
                        style="min-height:42px; background:#10b981; color:#ffffff; border:0; border-radius:10px; padding:10px 14px; font-size:13px; font-weight:700; cursor:pointer;"
                    >
                        Add Prep Point
                    </button>
                </div>
            </div>

            <div id="actionPointsList" style="display:flex; flex-direction:column; gap:10px;">
                @forelse($lead->actionPoints as $point)
                    <div
                        class="action-point-row"
                        data-action-point-id="{{ $point->id }}"
                        style="background:#111827; border:1px solid #475569; border-radius:10px; padding:12px;"
                    >
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                            <div style="font-size:14px; color:#e5e7eb; line-height:1.5; white-space:pre-wrap; word-break:break-word; flex:1;">{{ $point->note }}</div>
                            <button
                                type="button"
                                class="delete-action-point-btn"
                                style="min-height:36px; background:#7f1d1d; color:#ffffff; border:0; border-radius:8px; padding:8px 10px; font-size:12px; font-weight:700; cursor:pointer; flex-shrink:0;"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                @empty
                    <div id="noActionPointsMessage" style="background:#020617; border:1px dashed #374151; border-radius:10px; padding:12px; color:#94a3b8; font-size:13px;">
                        No prep points yet.
                    </div>
                @endforelse
            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:16px; width:100%; box-sizing:border-box;">

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">VICIdial Lead ID</label>
                <input
                    type="text"
                    value="{{ $lead->vicidial_lead_id }}"
                    disabled
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#6b7280; margin:0;"
                >
            </div>

            @php
                $titleField = old('title', $lead->title);
            @endphp
            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Title</label>
                <select
                    data-field="title"
                    style="display:block; width:100%; max-width:420px; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0; font-size:14px;"
                >
                    <option value="" @selected($titleField === null || $titleField === '')>Select title</option>
                    @foreach (\App\Models\Lead::TITLES as $t)
                        <option value="{{ $t }}" @selected($titleField === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">First Name</label>
                <input
                    type="text"
                    data-field="first_name"
                    value="{{ $lead->first_name }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Last Name</label>
                <input
                    type="text"
                    data-field="last_name"
                    value="{{ $lead->last_name }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Date of Birth</label>
                <input
                    type="text"
                    data-field="dob"
                    value="{{ $lead->dob }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Phone</label>
                <input
                    type="text"
                    data-field="phone_number"
                    value="{{ $lead->phone_number }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
                <div style="margin-top:8px; font-size:12px; color:#94a3b8;">
                    Last dialled (dialler):
                    <span style="color:#cbd5e1;">
                        {{ $lastDialledAt ? $lastDialledAt->format('d M Y, H:i') : 'Never dialled' }}
                    </span>
                </div>
            </div>

            @if (trim((string) ($lead->phone_number ?? '')) !== '')
                <div style="margin-top:10px;">
                    @include('partials.lead-click-to-call', ['lead' => $lead])
                </div>
            @endif

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Email</label>
                <input
                    type="text"
                    data-field="email"
                    value="{{ $lead->email }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">WIP status</label>
                <select
                    id="lead-wip-status-select"
                    data-lead-id="{{ $lead->id }}"
                    style="display:block; width:100%; max-width:420px; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0; font-size:14px;"
                >
                    @foreach (\App\Models\Lead::WIP_STATUSES as $status)
                        <option value="{{ $status }}" @if($lead->wip_status === $status) selected @endif>{{ $status }}</option>
                    @endforeach
                </select>
            </div>

            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:12px 14px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:220px;">
                        <div style="font-size:13px; color:#9ca3af; margin-bottom:6px;">Temp Email</div>
                        <div id="tempMailAddress" style="font-size:16px; font-weight:700; word-break:break-word; line-height:1.4;">
                            {{ $lead->temp_mail ?: '—' }}
                        </div>
                        <div id="tempMailStatus" style="margin-top:6px; font-size:12px; color:#9ca3af;">
                            Ready
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button
                            type="button"
                            id="generateTempMailBtn"
                            style="background:#2563eb; color:#ffffff; border:0; border-radius:8px; padding:10px 14px; font-size:13px; cursor:pointer;"
                        >
                            Generate
                        </button>

                        <button
                            type="button"
                            id="copyTempMailBtn"
                            style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:8px; padding:10px 14px; font-size:13px; cursor:pointer;"
                        >
                            Copy
                        </button>
                    </div>
                </div>
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">House Number</label>
                <input
                    type="text"
                    data-field="house_number"
                    value="{{ $lead->house_number }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Postcode</label>
                <input
                    type="text"
                    data-field="postcode"
                    value="{{ $lead->postcode }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Address</label>
                <input
                    type="text"
                    data-field="address_line_1"
                    value="{{ $lead->address_line_1 }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
            </div>
        </div>
    </div>

    @include('partials.financial-statement-card')

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:22px; box-sizing:border-box; margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <h2 style="margin:0; font-size:24px;">Debts</h2>

            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <label for="practiceSelect" style="font-size:13px; color:#9ca3af;">Practice View</label>
                <select
                    id="practiceSelect"
                    style="padding:10px 12px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb;"
                >
                    @foreach($practices as $practice)
                        <option value="{{ $practice->key }}">{{ $practice->label }}</option>
                    @endforeach
                </select>

                <button
                    type="button"
                    id="openDebtModal"
                    style="background:#2563eb; color:#ffffff; border:0; border-radius:8px; padding:12px 16px; font-size:14px; cursor:pointer;"
                >
                    Add Debt
                </button>
            </div>
        </div>

        <div id="leadCreditCheckPanel" style="margin-bottom:18px; padding:14px; background:#020617; border:1px solid #374151; border-radius:10px;">
            <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
                <button
                    type="button"
                    id="runCreditCheckV3Btn"
                    data-run-url="{{ route('leads.credit-check-v3.run', $lead) }}"
                    data-panel-url="{{ route('leads.credit-check-v3.panel-poll', $lead) }}"
                    data-debts-section-url="{{ route('leads.debts-section-html', $lead) }}"
                    style="background:#6d28d9; color:#ffffff; border:0; border-radius:8px; padding:12px 16px; font-size:14px; cursor:pointer;"
                >
                    Run Credit Check
                </button>
                <span id="creditCheckListenerStatus" style="font-size:12px; color:#9ca3af;">—</span>
                <span id="creditCheckJobBadge" style="display:none; font-size:11px; padding:4px 8px; border-radius:999px; background:#1f2937; color:#e5e7eb;"></span>
            </div>
            <div id="creditCheckRunningWrap" style="display:none; margin-top:12px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:8px;">Elapsed <span id="creditCheckElapsed">0:00</span></div>
                <div
                    id="creditCheckActivityBox"
                    style="font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; line-height:1.5; color:#d1fae5; background:#0f172a; border:1px solid #1e293b; border-radius:8px; padding:10px 12px; max-height:180px; overflow-y:auto; white-space:pre-wrap;"
                ></div>
            </div>
        </div>

        <div id="leadDebtsRefreshMount">
            @include('leads.partials.lead-debts-section-inner')
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
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Source / Expected Source</label>
                <select
                    id="sourceExpected"
                    name="source_expected"
                    required
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb;"
                >
                    <option value="">Select source...</option>
                    <option value="credit_check">Credit check</option>
                    <option value="3wc">3WC</option>
                    <option value="screenshot_pdf">Screenshot / PDF</option>
                    <option value="live_chat">Live chat</option>
                    <option value="other">Other</option>
                </select>
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

<div id="ccV3KbaModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.72); z-index:10000; align-items:center; justify-content:center; padding:16px; box-sizing:border-box; flex-direction:row;">
    <div style="background:#111827; border:1px solid #4b5563; border-radius:14px; max-width:560px; width:100%; max-height:88vh; overflow:auto; padding:22px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.55);">
        <h2 style="margin:0 0 10px; font-size:18px; color:#f9fafb;">Security questions</h2>
        <p style="margin:0 0 16px; font-size:13px; color:#9ca3af; line-height:1.55;">
            Select one answer per question.
        </p>
        <form id="ccV3KbaForm" style="margin:0;"></form>
        <div style="margin-top:18px; display:flex; flex-wrap:wrap; gap:10px;">
            <button type="button" id="ccV3KbaSubmit" style="background:#2563eb; color:#fff; border:0; border-radius:8px; padding:10px 18px; font-size:14px; cursor:pointer;">Submit answers</button>
            <button type="button" id="ccV3KbaClose" style="background:#374151; color:#f9fafb; border:0; border-radius:8px; padding:10px 16px; font-size:13px; cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<script>
    const creditors = @json(
        $creditors->map(fn($creditor) => [
            'id' => $creditor->id,
            'name' => $creditor->name,
        ])->values()
    );

    const saveStatus = document.getElementById('saveStatus');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const caseNotesStickyWrap = document.getElementById('caseNotesStickyWrap');
    const caseNotesSpacer = document.getElementById('caseNotesSpacer');
    const caseNotesToggle = document.getElementById('caseNotesToggle');
    const caseNotesPanel = document.getElementById('caseNotesPanel');
    const caseNotesInput = document.getElementById('caseNotesInput');
    const caseNotesSaveBtn = document.getElementById('caseNotesSaveBtn');
    const caseNotesStatus = document.getElementById('caseNotesStatus');
    const actionPointInput = document.getElementById('actionPointInput');
    const addActionPointBtn = document.getElementById('addActionPointBtn');
    const actionPointStatus = document.getElementById('actionPointStatus');
    const actionPointsList = document.getElementById('actionPointsList');

    let caseNotesOpen = false;
    let caseNotesSaveTimer = null;
    let caseNotesLastSavedValue = caseNotesInput ? caseNotesInput.value : '';

    function updateCaseNotesSpacer() {
        if (!caseNotesStickyWrap || !caseNotesSpacer || !caseNotesToggle) return;

        if (!caseNotesOpen) {
            caseNotesSpacer.style.height = '56px';
            return;
        }

        const box = caseNotesStickyWrap.getBoundingClientRect();
        caseNotesSpacer.style.height = Math.max(56, Math.ceil(box.height) + 8) + 'px';
    }

    function setCaseNotesOpen(nextOpen) {
        if (!caseNotesPanel || !caseNotesToggle) return;

        caseNotesOpen = nextOpen;
        caseNotesPanel.style.display = caseNotesOpen ? 'block' : 'none';
        caseNotesToggle.innerHTML = '🗒️';

        requestAnimationFrame(updateCaseNotesSpacer);
    }

    function setCaseNotesStatus(message, color = '#9ca3af') {
        if (!caseNotesStatus) return;

        caseNotesStatus.textContent = message;
        caseNotesStatus.style.color = color;
    }

    async function saveCaseNotes() {
        if (!caseNotesInput) return;

        const value = caseNotesInput.value;

        if (value === caseNotesLastSavedValue) {
            setCaseNotesStatus('Saved', '#10b981');
            return;
        }

        setCaseNotesStatus('Saving...', '#fbbf24');

        try {
            const response = await fetch('/lead/{{ $lead->id }}/case-notes', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    case_notes: value,
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error('Failed saving notes');
            }

            caseNotesLastSavedValue = value;
            setCaseNotesStatus('Saved', '#10b981');
        } catch (e) {
            setCaseNotesStatus('Save failed', '#ef4444');
        }
    }

    function renderActionPointRow(item) {
        const row = document.createElement('div');
        row.className = 'action-point-row';
        row.dataset.actionPointId = item.id;
        row.style.cssText = 'background:#111827; border:1px solid #475569; border-radius:10px; padding:12px;';

        row.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                <div class="action-point-note" style="font-size:14px; color:#e5e7eb; line-height:1.5; white-space:pre-wrap; word-break:break-word; flex:1;"></div>
                <button
                    type="button"
                    class="delete-action-point-btn"
                    style="min-height:36px; background:#7f1d1d; color:#ffffff; border:0; border-radius:8px; padding:8px 10px; font-size:12px; font-weight:700; cursor:pointer; flex-shrink:0;"
                >
                    Delete
                </button>
            </div>
        `;

        row.querySelector('.action-point-note').textContent = item.note ?? '';

        return row;
    }

    function setActionPointStatus(message, color = '#9ca3af') {
        if (!actionPointStatus) return;

        actionPointStatus.textContent = message;
        actionPointStatus.style.color = color;
    }

    function removeNoActionPointsMessage() {
        const emptyMessage = document.getElementById('noActionPointsMessage');
        if (emptyMessage) {
            emptyMessage.remove();
        }
    }

    async function addActionPoint() {
        if (!actionPointInput || !addActionPointBtn || !actionPointsList) return;

        const note = actionPointInput.value.trim();

        if (!note) {
            setActionPointStatus('Write a prep point first.', '#f59e0b');
            return;
        }

        addActionPointBtn.disabled = true;
        setActionPointStatus('Adding...', '#fbbf24');

        try {
            const response = await fetch('/lead/{{ $lead->id }}/action-points', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ note }),
            });

            const data = await response.json();

            if (!response.ok || !data.success || !data.item) {
                throw new Error('Failed adding prep point');
            }

            removeNoActionPointsMessage();
            const row = renderActionPointRow(data.item);
            actionPointsList.prepend(row);
            actionPointInput.value = '';
            setActionPointStatus('Added.', '#10b981');
        } catch (e) {
            setActionPointStatus('Add failed.', '#ef4444');
        } finally {
            addActionPointBtn.disabled = false;
        }
    }

    async function deleteActionPoint(id, row, button) {
        if (!id || !actionPointsList) return;

        button.disabled = true;
        button.textContent = 'Deleting...';

        try {
            const response = await fetch(`/lead/{{ $lead->id }}/action-points/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error('Failed deleting prep point');
            }

            row.remove();
            setActionPointStatus('Deleted.', '#10b981');

            if (!actionPointsList.querySelector('.action-point-row')) {
                const empty = document.createElement('div');
                empty.id = 'noActionPointsMessage';
                empty.style.cssText = 'background:#020617; border:1px dashed #374151; border-radius:10px; padding:12px; color:#94a3b8; font-size:13px;';
                empty.textContent = 'No prep points yet.';
                actionPointsList.appendChild(empty);
            }
        } catch (e) {
            setActionPointStatus('Delete failed.', '#ef4444');
            button.disabled = false;
            button.textContent = 'Delete';
        }
    }

    if (caseNotesToggle) {
        caseNotesToggle.addEventListener('click', function () {
            setCaseNotesOpen(!caseNotesOpen);
        });
    }

    if (caseNotesSaveBtn) {
        caseNotesSaveBtn.addEventListener('click', async function () {
            await saveCaseNotes();
        });
    }

    if (caseNotesInput) {
        caseNotesInput.addEventListener('input', function () {
            setCaseNotesStatus('Typing...', '#9ca3af');

            if (caseNotesSaveTimer) {
                clearTimeout(caseNotesSaveTimer);
            }

            caseNotesSaveTimer = setTimeout(() => {
                saveCaseNotes();
            }, 700);

            requestAnimationFrame(updateCaseNotesSpacer);
        });
    }

    if (addActionPointBtn) {
        addActionPointBtn.addEventListener('click', async function () {
            await addActionPoint();
        });
    }

    if (actionPointInput) {
        actionPointInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                addActionPoint();
            }
        });
    }

    if (actionPointsList) {
        actionPointsList.addEventListener('click', function (event) {
            const deleteButton = event.target.closest('.delete-action-point-btn');
            if (!deleteButton) return;

            const row = deleteButton.closest('.action-point-row');
            if (!row) return;

            const id = row.dataset.actionPointId;
            deleteActionPoint(id, row, deleteButton);
        });
    }

    if (caseNotesToggle && caseNotesPanel) {
        setCaseNotesOpen(false);
        requestAnimationFrame(updateCaseNotesSpacer);
        window.addEventListener('resize', updateCaseNotesSpacer);
    }

    const leadFields = document.querySelectorAll('input[data-field], select[data-field]');
    const originalLeadValues = {};

    leadFields.forEach(fieldEl => {
        originalLeadValues[fieldEl.dataset.field] = fieldEl.value;

        const persist = async function () {
            const field = this.dataset.field;
            const value = this.value;

            if (value === originalLeadValues[field]) return;

            saveStatus.textContent = 'Saving...';
            saveStatus.style.color = '#fbbf24';

            try {
                const response = await fetch('/lead/{{ $lead->id }}/autosave', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ field, value })
                });

                const data = await response.json();

                if (!data.success) throw new Error();

                originalLeadValues[field] = value;
                saveStatus.textContent = 'Saved';
                saveStatus.style.color = '#10b981';
            } catch (e) {
                saveStatus.textContent = 'Save failed';
                saveStatus.style.color = '#ef4444';
            }
        };

        if (fieldEl.tagName === 'SELECT') {
            fieldEl.addEventListener('change', persist);
        } else {
            fieldEl.addEventListener('blur', persist);
        }
    });

    const tempMailStatus = document.getElementById('tempMailStatus');
    const tempMailAddress = document.getElementById('tempMailAddress');
    const generateTempMailBtn = document.getElementById('generateTempMailBtn');
    const copyTempMailBtn = document.getElementById('copyTempMailBtn');

    function setTempMailStatus(message, color = '#9ca3af') {
        tempMailStatus.textContent = message;
        tempMailStatus.style.color = color;
    }

    generateTempMailBtn.addEventListener('click', async function () {
        generateTempMailBtn.disabled = true;
        generateTempMailBtn.textContent = 'Generating...';
        setTempMailStatus('Generating temp email...', '#fbbf24');

        try {
            const response = await fetch('/leads/{{ $lead->id }}/temp-mail/generate', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            });

            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.message || 'Generate failed');
            }

            tempMailAddress.textContent = data.email || '—';
            setTempMailStatus('Temp email generated.', '#10b981');
        } catch (e) {
            setTempMailStatus('Generate failed.', '#ef4444');
        } finally {
            generateTempMailBtn.disabled = false;
            generateTempMailBtn.textContent = 'Generate';
        }
    });

    copyTempMailBtn.addEventListener('click', async function () {
        const value = tempMailAddress.textContent.trim();

        if (!value || value === '—') {
            setTempMailStatus('No temp email to copy.', '#f59e0b');
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            setTempMailStatus('Temp email copied.', '#10b981');
        } catch (e) {
            setTempMailStatus('Copy failed.', '#ef4444');
        }
    });

    const practiceSelect = document.getElementById('practiceSelect');
    let debtList = document.getElementById('debtList');
    let warningBox = document.getElementById('warningBox');
    let totalDebtValue = document.getElementById('totalDebtValue');
    let eligibleBalanceValue = document.getElementById('eligibleBalanceValue');
    let acceptPercentValue = document.getElementById('acceptPercentValue');
    let rejectPercentValue = document.getElementById('rejectPercentValue');
    let dominantHouseValue = document.getElementById('dominantHouseValue');

    function bindDebtSectionRefs() {
        debtList = document.getElementById('debtList');
        warningBox = document.getElementById('warningBox');
        totalDebtValue = document.getElementById('totalDebtValue');
        eligibleBalanceValue = document.getElementById('eligibleBalanceValue');
        acceptPercentValue = document.getElementById('acceptPercentValue');
        rejectPercentValue = document.getElementById('rejectPercentValue');
        dominantHouseValue = document.getElementById('dominantHouseValue');
    }

    const runCreditCheckV3Btn = document.getElementById('runCreditCheckV3Btn');
    const creditCheckListenerStatus = document.getElementById('creditCheckListenerStatus');
    const creditCheckJobBadge = document.getElementById('creditCheckJobBadge');
    const creditCheckRunningWrap = document.getElementById('creditCheckRunningWrap');
    const creditCheckActivityBox = document.getElementById('creditCheckActivityBox');
    const creditCheckElapsed = document.getElementById('creditCheckElapsed');
    const leadDebtsRefreshMount = document.getElementById('leadDebtsRefreshMount');

    let ccV3PollTimer = null;
    let ccV3TickTimer = null;
    let ccV3JobId = null;
    let ccV3StartedAtMs = null;
    let ccV3LastDebtSignature = null;
    let ccV3LastKbaFingerprint = null;
    let ccV3SubmittedKbaFingerprint = null;
    let creditCheckWasRunning = false;

    function stopCreditCheckV3Poll() {
        if (ccV3PollTimer) {
            clearInterval(ccV3PollTimer);
            ccV3PollTimer = null;
        }
        if (ccV3TickTimer) {
            clearInterval(ccV3TickTimer);
            ccV3TickTimer = null;
        }
    }

    function formatCreditCheckElapsed(ms) {
        const s = Math.max(0, Math.floor(ms / 1000));
        const m = Math.floor(s / 60);
        const r = s % 60;
        return m + ':' + String(r).padStart(2, '0');
    }

    function updateCreditCheckElapsed() {
        if (!creditCheckElapsed || !ccV3StartedAtMs) return;
        creditCheckElapsed.textContent = formatCreditCheckElapsed(Date.now() - ccV3StartedAtMs);
    }

    async function refreshDebtsSectionIfNeeded(signatureFromPoll) {
        if (!leadDebtsRefreshMount || !runCreditCheckV3Btn || !signatureFromPoll) return;
        if (ccV3LastDebtSignature === signatureFromPoll) return;
        ccV3LastDebtSignature = signatureFromPoll;
        const url = runCreditCheckV3Btn.dataset.debtsSectionUrl;
        const res = await fetch(url, { headers: { 'Accept': 'text/html' } });
        if (!res.ok) return;
        const html = await res.text();
        leadDebtsRefreshMount.innerHTML = html;
        bindDebtSectionRefs();
        bindEditButtons();
        bindDeleteButtons();
        refreshDebtInterpretation();
    }

    async function refreshDebtsSectionForced(signatureToStore) {
        if (!leadDebtsRefreshMount || !runCreditCheckV3Btn) return;
        const url = runCreditCheckV3Btn.dataset.debtsSectionUrl;
        const res = await fetch(url, { headers: { 'Accept': 'text/html' } });
        if (!res.ok) return;
        const html = await res.text();
        leadDebtsRefreshMount.innerHTML = html;
        bindDebtSectionRefs();
        bindEditButtons();
        bindDeleteButtons();
        refreshDebtInterpretation();
        if (signatureToStore) {
            ccV3LastDebtSignature = signatureToStore;
        }
    }

    function fingerprintCcV3Questions(list) {
        if (!Array.isArray(list) || !list.length) return '';
        return list.map(function (q) {
            return String(q && q.id != null ? q.id : '') + '|' + String(q && q.question ? q.question : '');
        }).join('||');
    }

    function renderCcV3KbaForm(questions) {
        const form = document.getElementById('ccV3KbaForm');
        if (!form) return;
        form.innerHTML = '';
        questions.forEach(function (q, index) {
            const wrapper = document.createElement('div');
            wrapper.style.marginBottom = '16px';
            wrapper.style.padding = '12px 14px';
            wrapper.style.background = '#020617';
            wrapper.style.border = '1px solid #374151';
            wrapper.style.borderRadius = '10px';
            wrapper.dataset.questionBlock = '1';
            wrapper.dataset.questionId = q && q.id != null ? String(q.id) : '';

            const title = document.createElement('div');
            title.textContent = q && q.question ? String(q.question) : ('Question ' + (index + 1));
            title.style.marginBottom = '10px';
            title.style.fontWeight = '600';
            title.style.fontSize = '14px';
            wrapper.appendChild(title);

            const answers = Array.isArray(q && q.answers) ? q.answers : [];
            answers.forEach(function (ans) {
                const optLabel = document.createElement('label');
                optLabel.style.display = 'block';
                optLabel.style.marginBottom = '6px';
                optLabel.style.cursor = 'pointer';
                optLabel.style.fontSize = '13px';
                optLabel.style.color = '#e5e7eb';

                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'ccv3_q_' + index;
                const raw = ans && typeof ans === 'object' ? ans : {};
                const labelText = String(raw.label != null ? raw.label : '').trim();
                const fallback = String(raw.value != null ? raw.value : '').trim();
                const display = labelText || fallback;
                radio.value = fallback || labelText;
                radio.dataset.answerLabel = display;
                optLabel.appendChild(radio);
                optLabel.appendChild(document.createTextNode(' ' + display));
                wrapper.appendChild(optLabel);
            });

            form.appendChild(wrapper);
        });
    }

    function collectCcV3KbaAnswers() {
        const answers = [];
        const blocks = document.querySelectorAll('#ccV3KbaForm [data-question-block="1"]');
        let idx = 0;
        blocks.forEach(function (block) {
            const id = block.dataset.questionId || '';
            const selected = block.querySelector('input[type="radio"]:checked');
            const value = selected ? String(selected.value).trim() : '';
            const label = selected ? String(selected.dataset.answerLabel || '').trim() : '';
            answers.push({
                id: id,
                index: idx,
                value: value || null,
                label: label || null,
            });
            idx += 1;
        });
        return answers;
    }

    async function handlePanelPollPayload(data) {
        if (data.external_job_id) {
            ccV3JobId = data.external_job_id;
        }
        if (creditCheckListenerStatus) {
            creditCheckListenerStatus.textContent = data.listener_status || '—';
        }
        if (data.started_at) {
            const parsed = Date.parse(data.started_at);
            if (!Number.isNaN(parsed)) {
                ccV3StartedAtMs = parsed;
            }
        }
        const running = Boolean(data.running);

        if (!running && creditCheckWasRunning) {
            // Transition: running → idle
            stopCreditCheckV3Poll();

            try {
                await refreshDebtsSectionForced(data.debts_signature || '');
            } catch (e) { /* ignore */ }
        }

        // Update state AFTER handling transition
        creditCheckWasRunning = running;

        // Ensure polling is stopped when idle (covers page load / no job case)
        if (!running) {
            stopCreditCheckV3Poll();
        }
        if (creditCheckRunningWrap) {
            creditCheckRunningWrap.style.display = running ? 'block' : 'none';
        }
        if (creditCheckActivityBox) {
            const lines = Array.isArray(data.activity_lines) ? data.activity_lines : [];
            creditCheckActivityBox.textContent = running ? lines.join('\n') : '';
        }
        if (creditCheckJobBadge) {
            if (data.job_status && !running && (data.job_status === 'success' || data.job_status === 'failed')) {
                creditCheckJobBadge.style.display = 'inline-block';
                creditCheckJobBadge.textContent = data.job_status === 'success' ? 'Credit check completed' : 'Credit check failed';
                creditCheckJobBadge.style.background = data.job_status === 'success' ? '#14532d' : '#7f1d1d';
                creditCheckJobBadge.style.color = data.job_status === 'success' ? '#dcfce7' : '#fecaca';
            } else if (running) {
                creditCheckJobBadge.style.display = 'none';
            }
        }
        if (runCreditCheckV3Btn) {
            runCreditCheckV3Btn.disabled = running;
            runCreditCheckV3Btn.textContent = running ? 'Credit check running…' : 'Run Credit Check';
        }
        if (data.debts_signature) {
            try {
                await refreshDebtsSectionIfNeeded(data.debts_signature);
            } catch (e) { /* ignore */ }
        }
        const qs = data.security_questions;
        if (running && Array.isArray(qs) && qs.length > 0) {
            const fp = fingerprintCcV3Questions(qs);
            if (fp && fp !== ccV3SubmittedKbaFingerprint) {
                if (fp !== ccV3LastKbaFingerprint) {
                    ccV3LastKbaFingerprint = fp;
                    renderCcV3KbaForm(qs);
                }
                const modal = document.getElementById('ccV3KbaModal');
                if (modal) modal.style.display = 'flex';
            }
        } else {
            const modal = document.getElementById('ccV3KbaModal');
            if (modal) modal.style.display = 'none';
        }
    }

    async function pollCreditCheckPanelOnce() {
        if (!runCreditCheckV3Btn) return;
        const url = runCreditCheckV3Btn.dataset.panelUrl;
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!data.ok) return;
        await handlePanelPollPayload(data);
    }

    if (runCreditCheckV3Btn) {
        runCreditCheckV3Btn.addEventListener('click', async function () {
            runCreditCheckV3Btn.disabled = true;
            stopCreditCheckV3Poll();
            ccV3LastKbaFingerprint = null;
            ccV3SubmittedKbaFingerprint = null;
            ccV3JobId = null;
            ccV3StartedAtMs = null;
            if (creditCheckJobBadge) creditCheckJobBadge.style.display = 'none';
            try {
                const res = await fetch(runCreditCheckV3Btn.dataset.runUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({}),
                });
                const json = await res.json();
                ccV3JobId = json.jobId || null;
                if (!ccV3JobId) {
                    throw new Error(json.message || 'Could not start credit check');
                }
                ccV3StartedAtMs = Date.now();
                updateCreditCheckElapsed();
                await pollCreditCheckPanelOnce();
                ccV3PollTimer = setInterval(pollCreditCheckPanelOnce, 3500);
                ccV3TickTimer = setInterval(updateCreditCheckElapsed, 500);
            } catch (e) {
                alert('Could not start credit check.');
                runCreditCheckV3Btn.disabled = false;
                runCreditCheckV3Btn.textContent = 'Run Credit Check';
            }
        });

        pollCreditCheckPanelOnce().catch(function () {});
    }

    const ccV3KbaModal = document.getElementById('ccV3KbaModal');
    const ccV3KbaSubmit = document.getElementById('ccV3KbaSubmit');
    const ccV3KbaClose = document.getElementById('ccV3KbaClose');
    if (ccV3KbaClose && ccV3KbaModal) {
        ccV3KbaClose.addEventListener('click', function () {
            ccV3KbaModal.style.display = 'none';
        });
    }
    if (ccV3KbaSubmit) {
        ccV3KbaSubmit.addEventListener('click', async function () {
            if (!ccV3JobId) return;
            const answers = collectCcV3KbaAnswers();
            const missing = answers.some(function (a) { return !a.value; });
            if (missing) {
                alert('Please select an answer for every question.');
                return;
            }
            try {
                const res = await fetch('/leads/credit-check-v3/jobs/' + encodeURIComponent(ccV3JobId) + '/answers', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ answers: answers }),
                });
                if (!res.ok) {
                    throw new Error();
                }
                if (ccV3LastKbaFingerprint) {
                    ccV3SubmittedKbaFingerprint = ccV3LastKbaFingerprint;
                }
                if (ccV3KbaModal) ccV3KbaModal.style.display = 'none';
            } catch (e) {
                alert('Failed to submit answers.');
            }
        });
    }

    function formatMoney(value) {
        return '£' + Number(value).toLocaleString('en-GB', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

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

    function normaliseVotingType(value) {
        return String(value || '').trim().toLowerCase();
    }

    function formatVotingTypeLabel(value) {
        const normalised = normaliseVotingType(value);

        if (normalised === 'accept') return 'ACCEPT';
        if (normalised === 'reject') return 'REJECT';
        if (normalised === 'cbc') return 'CBC';
        if (normalised === 'non vote' || normalised === 'non-vote' || normalised === 'nonvote') return 'NON VOTE';

        return String(value || '').toUpperCase();
    }

    function getVotingTypePillStyle(value) {
        const normalised = normaliseVotingType(value);

        if (normalised === 'accept') {
            return 'background:#14532d; color:#dcfce7;';
        }

        if (normalised === 'reject') {
            return 'background:#7f1d1d; color:#fecaca;';
        }

        if (normalised === 'cbc') {
            return 'background:#92400e; color:#fde68a;';
        }

        if (normalised === 'non vote' || normalised === 'non-vote' || normalised === 'nonvote') {
            return 'background:#3f3f46; color:#f4f4f5;';
        }

        return 'background:#1f2937; color:#e5e7eb;';
    }

    function getVotingTypeForPractice(row, practiceKey) {
        if (practiceKey === 'practice2') return row.dataset.votePractice2;
        if (practiceKey === 'practice3') return row.dataset.votePractice3;
        return row.dataset.votePractice1;
    }

    function refreshDebtInterpretation() {
        if (!practiceSelect || !totalDebtValue || !eligibleBalanceValue || !acceptPercentValue || !rejectPercentValue || !dominantHouseValue || !warningBox) {
            return;
        }

        const practiceKey = practiceSelect.value;
        const rows = document.querySelectorAll('.debt-row');

        let totalDebt = 0;
        let eligibleTotal = 0;
        let acceptTotal = 0;
        let rejectTotal = 0;
        const houseTotals = {};

        rows.forEach(row => {
            const votingType = getVotingTypeForPractice(row, practiceKey);
            const votingTypeEl = row.querySelector('.debt-voting-type');

            if (votingTypeEl) {
                votingTypeEl.textContent = formatVotingTypeLabel(votingType);
                votingTypeEl.style.cssText = getVotingTypePillStyle(votingType) + ' padding:6px 10px; border-radius:999px; font-size:12px;';
            }

            const balance = parseFloat(row.dataset.balance || '0');
            const house = row.dataset.votingHouse || '';

            totalDebt += balance;

            if (votingType === 'accept' || votingType === 'reject') {
                eligibleTotal += balance;

                if (votingType === 'accept') {
                    acceptTotal += balance;
                }

                if (votingType === 'reject') {
                    rejectTotal += balance;
                }

                houseTotals[house] = (houseTotals[house] || 0) + balance;
            }
        });

        const acceptPercent = eligibleTotal > 0 ? (acceptTotal / eligibleTotal) * 100 : 0;
        const rejectPercent = eligibleTotal > 0 ? (rejectTotal / eligibleTotal) * 100 : 0;

        let dominantHouse = '-';
        let dominantHousePercent = 0;

        Object.entries(houseTotals).forEach(([house, total]) => {
            const percent = eligibleTotal > 0 ? (total / eligibleTotal) * 100 : 0;
            if (percent > dominantHousePercent) {
                dominantHousePercent = percent;
                dominantHouse = house;
            }
        });

        totalDebtValue.textContent = formatMoney(totalDebt);
        eligibleBalanceValue.textContent = formatMoney(eligibleTotal);
        acceptPercentValue.textContent = acceptPercent.toFixed(1) + '%';
        rejectPercentValue.textContent = rejectPercent.toFixed(1) + '%';
        dominantHouseValue.textContent = dominantHouse === '-' ? '-' : dominantHouse + ' (' + dominantHousePercent.toFixed(1) + '%)';

        const warnings = [];

        if (eligibleTotal > 0 && acceptPercent < 75) {
            warnings.push('Accept voting is below 75%.');
        }

        if (eligibleTotal > 0 && dominantHouse !== '-' && dominantHousePercent > 25) {
            warnings.push(dominantHouse + ' controls more than 25% of voting debt.');
        }

        if (eligibleTotal > 0 && rejectPercent >= 25) {
            warnings.push('Reject-heavy mix detected.');
        }

        if (warnings.length) {
            warningBox.style.display = 'block';
            warningBox.innerHTML = warnings.map(w => '<div style="margin-bottom:6px;">• ' + w + '</div>').join('');
        } else {
            warningBox.style.display = 'none';
            warningBox.innerHTML = '';
        }
    }

    practiceSelect.addEventListener('change', refreshDebtInterpretation);

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
    const sourceExpected = document.getElementById('sourceExpected');
    const debtReference = document.getElementById('debtReference');

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

    function buildDebtRowHtml(debt, votingType) {
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

                        <span style="${getVotingTypePillStyle(votingType)} padding:6px 10px; border-radius:999px; font-size:12px;" class="debt-voting-type">
                            ${formatVotingTypeLabel(votingType)}
                        </span>

                        <span style="background:#3f3f46; color:#f4f4f5; padding:6px 10px; border-radius:999px; font-size:12px;" class="debt-voting-house">
                            ${debt.voting_house}
                        </span>

                        <span
                            class="debt-evidence-status"
                            data-document-complete="${debt.document_complete ? '1' : '0'}"
                            style="background:${debt.document_complete ? '#14532d' : '#7c2d12'}; color:${debt.document_complete ? '#dcfce7' : '#fed7aa'}; padding:6px 10px; border-radius:999px; font-size:12px;"
                        >
                            ${debt.document_complete ? 'Evidence complete' : 'Evidence incomplete'}
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
        const practiceKey = practiceSelect.value;
        let votingType = debt.voting_practice1;

        if (practiceKey === 'practice2') votingType = debt.voting_practice2;
        if (practiceKey === 'practice3') votingType = debt.voting_practice3;

        row.dataset.debtId = debt.id;
        row.dataset.balance = Number(debt.balance).toFixed(2);
        row.dataset.votingHouse = debt.voting_house;
        row.dataset.votePractice1 = debt.voting_practice1;
        row.dataset.votePractice2 = debt.voting_practice2;
        row.dataset.votePractice3 = debt.voting_practice3;

        row.innerHTML = buildDebtRowHtml(debt, votingType);
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
        bindDeleteButtons();
        bindEditButtons();
        refreshDebtInterpretation();
    }

    function updateDebtRow(debt) {
        const row = document.getElementById('debt-row-' + debt.id);
        if (!row) return;

        applyDebtRowData(row, debt);
        bindDeleteButtons();
        bindEditButtons();
        refreshDebtInterpretation();
    }

    async function openEditDebtModal(debtId) {
        debtModalError.style.display = 'none';
        debtModalError.textContent = '';

        addDebtForm.reset();
        resetCreditorPicker();
        editingDebtId.value = debtId;
        showDebtModal('edit');

        submitDebtBtn.disabled = true;
        submitDebtBtn.textContent = 'Loading...';

        try {
            const response = await fetch('/debt/' + debtId, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Load failed');
            }

            const data = await response.json();

            creditorId.value = data.creditor_id || '';
            creditorSearch.value = data.creditor_name || '';
            debtBalance.value = data.balance || '';
            sourceExpected.value = data.source_expected || '';
            debtReference.value = data.reference || '';

            submitDebtBtn.disabled = false;
            submitDebtBtn.textContent = 'Save Changes';
        } catch (e) {
            debtModalError.style.display = 'block';
            debtModalError.textContent = 'Could not load debt for editing.';
            submitDebtBtn.disabled = false;
            submitDebtBtn.textContent = 'Save Changes';
        }
    }

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
            source_expected: sourceExpected.value,
            reference: debtReference.value
        };

        try {
            const url = isEdit
                ? '/debt/' + editingDebtId.value
                : '/lead/{{ $lead->id }}/debts';

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
                const text = await response.text();
                throw new Error(text || (isEdit ? 'Update failed' : 'Create failed'));
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
                    const response = await fetch('/debt/' + debtId, {
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

                    refreshDebtInterpretation();
                } catch (e) {
                    alert('Delete failed');
                }
            });
        });
    }

    bindEditButtons();
    bindDeleteButtons();
    refreshDebtInterpretation();

    (function () {
        const leadWipStatusSelect = document.getElementById('lead-wip-status-select');
        if (!leadWipStatusSelect) return;

        let originalWipStatus = leadWipStatusSelect.value;

        leadWipStatusSelect.addEventListener('change', async function () {
            const leadId = leadWipStatusSelect.dataset.leadId;
            const newValue = leadWipStatusSelect.value;

            try {
                const response = await fetch('/lead/' + leadId + '/wip-status', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ wip_status: newValue }),
                });

                if (!response.ok) {
                    throw new Error('Failed');
                }

                const data = await response.json();
                originalWipStatus = data.wip_status;
                leadWipStatusSelect.value = data.wip_status;
            } catch (e) {
                alert('Could not update WIP status.');
                leadWipStatusSelect.value = originalWipStatus;
            }
        });
    })();
</script>

@include('partials.financial-statement-init', [
    'financialStatementSaveUrl' => route('lead.financial-statement.update', $lead),
])

</body>
</html>