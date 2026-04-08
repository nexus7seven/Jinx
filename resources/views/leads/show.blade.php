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
        id="caseNotesWidget"
        style="position:sticky; top:10px; z-index:25; margin:0 0 14px 0; display:flex; justify-content:flex-end;"
    >
        <div style="width:100%; max-width:420px;">
            <button
                type="button"
                id="caseNotesToggle"
                style="display:inline-flex; align-items:center; gap:8px; min-height:42px; width:auto; max-width:100%; padding:10px 12px; border-radius:999px; border:1px solid #4b5563; background:#1f2937; color:#f9fafb; font-size:13px; font-weight:700; cursor:pointer;"
            >
                📝 Scribble Notes
            </button>

            <div
                id="caseNotesPanel"
                style="display:none; margin-top:10px; background:#111827; border:1px solid #374151; border-radius:12px; padding:12px;"
            >
                <div style="font-size:12px; color:#9ca3af; margin-bottom:8px;">
                    Private scratchpad for this case
                </div>

                <textarea
                    id="caseNotesInput"
                    style="width:100%; min-height:140px; resize:vertical; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:14px;"
                    placeholder="Write quick case thoughts here..."
                >{{ $lead->case_notes }}</textarea>

                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:10px; flex-wrap:wrap;">
                    <div id="caseNotesStatus" style="font-size:12px; color:#9ca3af;">Idle</div>

                    <button
                        type="button"
                        id="caseNotesSaveBtn"
                        style="min-height:40px; background:#2563eb; color:#ffffff; border:0; border-radius:8px; padding:10px 14px; font-size:13px; font-weight:700; cursor:pointer;"
                    >
                        Save Notes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:28px; box-sizing:border-box; margin-bottom:20px;">
        <h1 style="margin:0 0 18px 0; font-size:28px; line-height:1.2;">Jinx Lead {{ $lead->id }}</h1>

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
            </div>

            <div>
                <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Email</label>
                <input
                    type="text"
                    data-field="email"
                    value="{{ $lead->email }}"
                    style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin:0;"
                >
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
                    onclick="window.open('/leads/{{ $lead->id }}/credit-check-helper', '_blank')"
                    style="background:#7c3aed; color:#ffffff; border:0; border-radius:8px; padding:12px 16px; font-size:14px; cursor:pointer;"
                >
                    Run Credit Check
                </button>

                <button
                    type="button"
                    id="openDebtModal"
                    style="background:#2563eb; color:#ffffff; border:0; border-radius:8px; padding:12px 16px; font-size:14px; cursor:pointer;"
                >
                    Add Debt
                </button>
            </div>
        </div>

        <div id="debtSummaryStrip" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:18px;">
            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Total Debt</div>
                <div id="totalDebtValue" style="font-size:22px; font-weight:700;">£0.00</div>
            </div>
            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Voting Balance</div>
                <div id="eligibleBalanceValue" style="font-size:22px; font-weight:700;">£0.00</div>
            </div>
            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Accept %</div>
                <div id="acceptPercentValue" style="font-size:22px; font-weight:700;">0.0%</div>
            </div>
            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Reject %</div>
                <div id="rejectPercentValue" style="font-size:22px; font-weight:700;">0.0%</div>
            </div>
            <div style="background:#020617; border:1px solid #374151; border-radius:10px; padding:14px;">
                <div style="font-size:12px; color:#9ca3af; margin-bottom:6px;">Dominant House</div>
                <div id="dominantHouseValue" style="font-size:22px; font-weight:700;">-</div>
            </div>
        </div>

        <div id="warningBox" style="display:none; margin-bottom:16px; background:#3f1d1d; border:1px solid #7f1d1d; color:#fecaca; border-radius:10px; padding:14px;"></div>

        <div id="debtList">
            @forelse($lead->debts as $debt)
                <div
                    class="debt-row"
                    id="debt-row-{{ $debt->id }}"
                    data-debt-id="{{ $debt->id }}"
                    data-balance="{{ number_format((float) $debt->balance, 2, '.', '') }}"
                    data-voting-house="{{ $debt->creditor->voting_house }}"
                    data-vote-practice1="{{ $debt->creditor->voting_practice1 }}"
                    data-vote-practice2="{{ $debt->creditor->voting_practice2 }}"
                    data-vote-practice3="{{ $debt->creditor->voting_practice3 }}"
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

@php
    $initialVotingType = $debt->creditor->voting_practice1;

    $votingPillStyles = match(strtolower(trim($initialVotingType))) {
        'accept' => 'background:#14532d; color:#dcfce7;',
        'reject' => 'background:#7f1d1d; color:#fecaca;',
        'cbc' => 'background:#92400e; color:#fde68a;',
        'non vote', 'non-vote', 'nonvote' => 'background:#3f3f46; color:#f4f4f5;',
        default => 'background:#1f2937; color:#e5e7eb;',
    };

    $votingPillLabel = match(strtolower(trim($initialVotingType))) {
        'accept' => 'ACCEPT',
        'reject' => 'REJECT',
        'cbc' => 'CBC',
        'non vote', 'non-vote', 'nonvote' => 'NON VOTE',
        default => strtoupper($initialVotingType),
    };
@endphp

<span style="{{ $votingPillStyles }} padding:6px 10px; border-radius:999px; font-size:12px;" class="debt-voting-type">
    {{ $votingPillLabel }}
</span>

                                <span style="background:#3f3f46; color:#f4f4f5; padding:6px 10px; border-radius:999px; font-size:12px;" class="debt-voting-house">
                                    {{ $debt->creditor->voting_house }}
                                </span>

                                <span
                                    class="debt-evidence-status"
                                    data-document-complete="{{ $debt->document?->is_complete ? '1' : '0' }}"
                                    style="background:{{ $debt->document?->is_complete ? '#14532d' : '#7c2d12' }}; color:{{ $debt->document?->is_complete ? '#dcfce7' : '#fed7aa' }}; padding:6px 10px; border-radius:999px; font-size:12px;"
                                >
                                    {{ $debt->document?->is_complete ? 'Evidence complete' : 'Evidence incomplete' }}
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
    </div>

    <div id="credit-report-upload" style="background:#111827; border:1px solid #374151; border-radius:14px; padding:22px; box-sizing:border-box; margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <h2 style="margin:0; font-size:24px;">Credit Report Import</h2>
        </div>

        @if(session('credit_report_success'))
            <div style="margin-bottom:16px; background:#14532d; border:1px solid #166534; color:#dcfce7; border-radius:10px; padding:14px;">
                {{ session('credit_report_success') }}
            </div>
        @endif

        @if($errors->any())
            <div style="margin-bottom:16px; background:#3f1d1d; border:1px solid #7f1d1d; color:#fecaca; border-radius:10px; padding:14px;">
                {{ $errors->first() }}
            </div>
        @endif

        <form action="/lead/{{ $lead->id }}/credit-reports" method="POST" enctype="multipart/form-data" style="margin-bottom:18px;">
            @csrf

            <div style="margin-bottom:12px; font-size:13px; color:#9ca3af;">
                Upload saved MHT / MHTML pages from the credit check. Files are stored as background evidence and Jinx will try to import debts automatically.
            </div>

            <input
                type="file"
                name="report_files[]"
                multiple
                accept=".mht,.mhtml"
                style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; margin-bottom:12px;"
            >

            <button
                type="submit"
                style="background:#2563eb; color:#ffffff; border:0; border-radius:8px; padding:12px 16px; font-size:14px; cursor:pointer;"
            >
                Upload Credit Report Files
            </button>
        </form>

        <div style="display:flex; flex-direction:column; gap:14px;">
            @forelse(($creditReports ?? []) as $report)
                <div style="background:#020617; border:1px solid #374151; border-radius:12px; padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                        <div>
                            <div style="font-size:16px; font-weight:700; margin-bottom:6px;">
                                Report #{{ $report->id }}
                            </div>

                            <div style="font-size:13px; color:#9ca3af;">
                                {{ $report->provider }} · {{ $report->status }} · {{ $report->created_at }}
                            </div>
                        </div>

                        <button
                            type="button"
                            class="delete-report-btn"
                            data-report-id="{{ $report->id }}"
                            style="background:#7f1d1d; color:#ffffff; border:0; border-radius:8px; padding:10px 12px; font-size:13px; cursor:pointer;"
                        >
                            Delete Report Batch
                        </button>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:8px;">
                        @foreach($report->files as $file)
                            <div style="background:#111827; border:1px solid #374151; border-radius:10px; padding:12px; font-size:13px; color:#d1d5db;">
                                {{ $file->original_name }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div style="background:#020617; border:1px dashed #374151; border-radius:12px; padding:18px; color:#9ca3af;">
                    No credit report files uploaded yet.
                </div>
            @endforelse
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

<script>
    const creditors = @json(
        $creditors->map(fn($creditor) => [
            'id' => $creditor->id,
            'name' => $creditor->name,
        ])->values()
    );

    const saveStatus = document.getElementById('saveStatus');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const caseNotesToggle = document.getElementById('caseNotesToggle');
    const caseNotesPanel = document.getElementById('caseNotesPanel');
    const caseNotesInput = document.getElementById('caseNotesInput');
    const caseNotesSaveBtn = document.getElementById('caseNotesSaveBtn');
    const caseNotesStatus = document.getElementById('caseNotesStatus');
    const actionPointInput = document.getElementById('actionPointInput');
    const addActionPointBtn = document.getElementById('addActionPointBtn');
    const actionPointStatus = document.getElementById('actionPointStatus');
    const actionPointsList = document.getElementById('actionPointsList');
    const hasCaseNotesUI = !!(caseNotesToggle && caseNotesPanel && caseNotesInput && caseNotesSaveBtn && caseNotesStatus);
    const hasActionPointsUI = !!(actionPointInput && addActionPointBtn && actionPointStatus && actionPointsList);

    let caseNotesOpen = false;
    let caseNotesSaveTimer = null;
    let caseNotesLastSavedValue = hasCaseNotesUI ? caseNotesInput.value : '';

    function setCaseNotesOpen(nextOpen) {
        if (!hasCaseNotesUI) return;
        caseNotesOpen = nextOpen;
        caseNotesPanel.style.display = caseNotesOpen ? 'block' : 'none';
        caseNotesToggle.innerHTML = caseNotesOpen ? '📝 Hide Scribble Notes' : '📝 Scribble Notes';
    }

    function setCaseNotesStatus(message, color = '#9ca3af') {
        if (!hasCaseNotesUI) return;
        caseNotesStatus.textContent = message;
        caseNotesStatus.style.color = color;
    }

    async function saveCaseNotes() {
        if (!hasCaseNotesUI) return;
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
        if (!hasActionPointsUI) return;
        actionPointStatus.textContent = message;
        actionPointStatus.style.color = color;
    }

    function removeNoActionPointsMessage() {
        if (!hasActionPointsUI) return;
        const emptyMessage = document.getElementById('noActionPointsMessage');
        if (emptyMessage) {
            emptyMessage.remove();
        }
    }

    async function addActionPoint() {
        if (!hasActionPointsUI) return;
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
        if (!hasActionPointsUI) return;
        if (!id) return;

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

    if (hasCaseNotesUI) {
        caseNotesToggle.addEventListener('click', function () {
            setCaseNotesOpen(!caseNotesOpen);
        });

        caseNotesSaveBtn.addEventListener('click', async function () {
            await saveCaseNotes();
        });

        caseNotesInput.addEventListener('input', function () {
            setCaseNotesStatus('Typing...', '#9ca3af');

            if (caseNotesSaveTimer) {
                clearTimeout(caseNotesSaveTimer);
            }

            caseNotesSaveTimer = setTimeout(() => {
                saveCaseNotes();
            }, 700);
        });

        if (window.innerWidth >= 768) {
            setCaseNotesOpen(true);
        } else {
            setCaseNotesOpen(false);
        }
    }

    if (hasActionPointsUI) {
        addActionPointBtn.addEventListener('click', async function () {
            await addActionPoint();
        });

        actionPointInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                addActionPoint();
            }
        });

        actionPointsList.addEventListener('click', function (event) {
            const deleteButton = event.target.closest('.delete-action-point-btn');
            if (!deleteButton) return;

            const row = deleteButton.closest('.action-point-row');
            if (!row) return;

            const id = row.dataset.actionPointId;
            deleteActionPoint(id, row, deleteButton);
        });
    }

    const leadInputs = document.querySelectorAll('input[data-field]');
    const originalLeadValues = {};

    leadInputs.forEach(input => {
        originalLeadValues[input.dataset.field] = input.value;

        input.addEventListener('blur', async function () {
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
        });
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
    const debtList = document.getElementById('debtList');
    const warningBox = document.getElementById('warningBox');
    const totalDebtValue = document.getElementById('totalDebtValue');
    const eligibleBalanceValue = document.getElementById('eligibleBalanceValue');
    const acceptPercentValue = document.getElementById('acceptPercentValue');
    const rejectPercentValue = document.getElementById('rejectPercentValue');
    const dominantHouseValue = document.getElementById('dominantHouseValue');

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

    function bindDeleteReportButtons() {
        document.querySelectorAll('.delete-report-btn').forEach(button => {
            if (button.dataset.bound === '1') return;

            button.dataset.bound = '1';

            button.addEventListener('click', async function () {
                const reportId = this.dataset.reportId;

                if (!confirm('Delete this report batch?')) {
                    return;
                }

                try {
                    const response = await fetch('/credit-reports/' + reportId, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json'
                        }
                    });

                    if (!response.ok) {
                        window.location.reload();
                        return;
                    }

                    window.location.reload();
                } catch (e) {
                    alert('Delete report batch failed');
                }
            });
        });
    }

    bindEditButtons();
    bindDeleteButtons();
    bindDeleteReportButtons();
    refreshDebtInterpretation();
</script>

</body>
</html>
