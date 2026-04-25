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
                        @if($debt->creditor->name === 'Could Not Match' && $debt->reference)
                            <div style="font-size:12px; color:#a1a1aa; margin-bottom:6px;">Unmatched import — Ref shows the name taken from the credit report.</div>
                        @endif

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
