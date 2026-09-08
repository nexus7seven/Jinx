@php
    $fsH = $financialStatement['household'];
    $fsLimits = $fsClientPayload['household_limits'];
    $fsStored = is_array($lead->financial_statement ?? null) ? $lead->financial_statement : [];
    $fsTargetDiRaw = $fsStored['facts']['target_di'] ?? $fsStored['calculation']['target_di'] ?? null;
    $fsTargetDi = is_numeric($fsTargetDiRaw) ? (float) $fsTargetDiRaw : null;
@endphp

<style>
    .fs-overview { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; padding: 12px; border: 1px solid #334155; border-radius: 12px; background: #0b1220; }
    .fs-ov-label { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; line-height: 1.2; margin-bottom: 4px; }
    .fs-ov-value { font-size: 16px; font-weight: 700; color: #e2e8f0; line-height: 1.25; }
    .fs-ov-note { margin-top: 4px; font-size: 11px; color: #94a3b8; line-height: 1.35; }
    .fs-ov-household { grid-column: span 2; }
    .fs-ov-sfs { grid-column: span 3; }
    .fs-field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 16px; margin-top: 12px; }
    @media (max-width: 1200px) {
        .fs-overview { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .fs-ov-household, .fs-ov-sfs { grid-column: span 3; }
    }
    @media (max-width: 720px) {
        .fs-overview { grid-template-columns: 1fr 1fr; }
        .fs-ov-household, .fs-ov-sfs { grid-column: span 2; }
        .fs-field-grid { grid-template-columns: 1fr; }
    }
</style>

<div id="financialStatementCard" data-fs-target-di="{{ $fsTargetDi === null ? '' : number_format($fsTargetDi, 2, '.', '') }}" style="background:#111827; border:1px solid #374151; border-radius:14px; padding:18px 20px; box-sizing:border-box;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <h2 style="margin:0; font-size:20px;">Financial Statement</h2>
            <div style="font-size:12px; color:#94a3b8; margin-top:6px;">SFS-style section caps for Comms &amp; Leisure, Food, Personal</div>
        </div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; align-self:center;">
            <button
                type="button"
                id="fsMinimiseAllIe"
                style="background:transparent; color:#94a3b8; border:1px solid #475569; border-radius:8px; padding:8px 12px; font-size:13px; cursor:pointer; white-space:nowrap;"
            >
                Minimise all
            </button>
            <div id="financialStatementStatus" style="font-size:13px; color:#9ca3af;">Ready</div>
        </div>
    </div>

    <div class="fs-overview">
        <div>
            <div class="fs-ov-label">Target DI</div>
            <div id="fs-overview-target-di" class="fs-ov-value">{{ $fsTargetDi === null ? 'Not set' : '£'.number_format($fsTargetDi, 2) }}</div>
            <div class="fs-ov-note">Stored target{{ $fsTargetDi === null ? ' is not on this form yet' : '' }}</div>
        </div>
        <div>
            <div class="fs-ov-label">Current DI</div>
            <div id="fs-summary-disposable-total" class="fs-ov-value">£0.00</div>
        </div>
        <div>
            <div class="fs-ov-label">Difference to target</div>
            <div id="fs-overview-variance" class="fs-ov-value">—</div>
        </div>
        <div>
            <div class="fs-ov-label">Total income</div>
            <div id="fs-summary-income-total" class="fs-ov-value">£0.00</div>
        </div>
        <div>
            <div class="fs-ov-label">Total expenditure</div>
            <div id="fs-summary-expenditure-total" class="fs-ov-value">£0.00</div>
        </div>
        <div class="fs-ov-household">
            <div class="fs-ov-label">Household</div>
            <div id="fs-overview-household" class="fs-ov-value" style="font-size:14px;">—</div>
        </div>
        <div class="fs-ov-sfs">
            <div class="fs-ov-label">SFS status / warnings</div>
            <div id="fs-overview-sfs" class="fs-ov-value" style="font-size:14px;">—</div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:20px;">
        <div>
            <label for="fsAdults" style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Adults</label>
            <select id="fsAdults" data-fs-household="adults" style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:16px;">
                @for ($a = $fsLimits['adults_min']; $a <= $fsLimits['adults_max']; $a++)
                    <option value="{{ $a }}" @selected((int) $fsH['adults'] === $a)>{{ $a }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label for="fsChildrenU16" style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Children under 16</label>
            <select id="fsChildrenU16" data-fs-household="children_under_16" style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:16px;">
                @for ($c = $fsLimits['children_min']; $c <= $fsLimits['children_max']; $c++)
                    <option value="{{ $c }}" @selected((int) $fsH['children_under_16'] === $c)>{{ $c }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label for="fsChildren1618" style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">Children 16–18</label>
            <select id="fsChildren1618" data-fs-household="children_16_18" style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:16px;">
                @for ($c = $fsLimits['children_min']; $c <= $fsLimits['children_max']; $c++)
                    <option value="{{ $c }}" @selected((int) $fsH['children_16_18'] === $c)>{{ $c }}</option>
                @endfor
            </select>
        </div>
    </div>

    <div style="margin-bottom:20px;">
        <details data-fs-ie-card style="background:#0f172a; border:1px solid #334155; border-radius:12px; padding:0;">
            <summary style="cursor:pointer; list-style:none; padding:14px 16px; font-size:16px; font-weight:700; color:#f9fafb;">
                Income
                <span style="float:right; font-weight:600; font-size:14px; color:#94a3b8;">
                    Total <span id="fs-sum-income">£0.00</span>
                </span>
            </summary>
            <div style="padding:0 16px 14px 16px; border-top:1px solid #1e293b;">
                <div class="fs-field-grid">
                    @foreach ($fsClientPayload['income'] as $row)
                        @php
                            $amt = (float) ($financialStatement['income'][$row['code']] ?? 0);
                            $showAmt = abs($amt) >= 0.005 ? number_format($amt, 2, '.', '') : '';
                        @endphp
                        <div>
                            <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">{{ $row['label'] }}</label>
                            <input
                                type="number"
                                inputmode="decimal"
                                min="0"
                                step="0.01"
                                data-fs-income="{{ $row['code'] }}"
                                value="{{ $showAmt }}"
                                placeholder="0.00"
                                style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:16px;"
                            >
                        </div>
                    @endforeach
                </div>
            </div>
        </details>
    </div>

    <div>
        <h3 style="margin:0 0 12px 0; font-size:18px; color:#e5e7eb;">Expenditure</h3>
        <div style="display:flex; flex-direction:column; gap:10px;">
            @foreach ($fsClientPayload['expenditure_sections'] as $section)
                @php
                    $capBand = $section['cap_band'] ?? null;
                    $summaryId = 'fs-sum-'.$section['id'];
                    $metaId = 'fs-meta-'.$section['id'];
                @endphp
                <details data-fs-ie-card style="background:#0f172a; border:1px solid #334155; border-radius:12px; padding:0;">
                    <summary style="cursor:pointer; list-style:none; padding:14px 16px; font-size:16px; font-weight:700; color:#f9fafb;">
                        {{ $section['title'] }}
                        <span style="float:right; font-weight:600; font-size:14px; color:#94a3b8;">
                            Total <span id="{{ $summaryId }}">£0.00</span>
                        </span>
                    </summary>
                    <div style="padding:0 16px 14px 16px; border-top:1px solid #1e293b;">
                        @if ($capBand)
                            <div
                                id="{{ $metaId }}"
                                data-fs-cap-section="{{ $section['id'] }}"
                                data-fs-cap-band="{{ $capBand }}"
                                style="margin:12px 0; padding:10px 12px; border-radius:8px; border:1px solid #334155; background:#020617; font-size:13px; line-height:1.5; color:#cbd5e1;"
                            >
                                <div>Guideline cap: <strong data-fs-cap-value>£0.00</strong></div>
                                <div style="margin-top:4px; font-size:12px; color:#94a3b8;">
                                    Household for this cap: <span data-fs-household-label></span>
                                </div>
                                <div style="margin-top:4px;"><span data-fs-pct-wrap></span></div>
                                <div data-fs-below65 style="margin-top:4px; display:none; color:#fbbf24;"></div>
                                <div data-fs-over style="margin-top:4px; display:none; color:#fecaca;"></div>
                            </div>
                        @endif
                        <div class="fs-field-grid">
                            @foreach ($section['lines'] as $line)
                                @php
                                    $eAmt = (float) ($financialStatement['expenditure'][$line['code']] ?? 0);
                                    $eShow = abs($eAmt) >= 0.005 ? number_format($eAmt, 2, '.', '') : '';
                                @endphp
                                <div>
                                    <label style="display:block; font-size:13px; color:#9ca3af; margin-bottom:6px;">{{ $line['label'] }}</label>
                                    <input
                                        type="number"
                                        inputmode="decimal"
                                        min="0"
                                        step="0.01"
                                        data-fs-expenditure="{{ $line['code'] }}"
                                        data-fs-section="{{ $section['id'] }}"
                                        value="{{ $eShow }}"
                                        placeholder="0.00"
                                        style="display:block; width:100%; box-sizing:border-box; padding:12px 14px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:16px;"
                                    >
                                </div>
                            @endforeach
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
    </div>
</div>
