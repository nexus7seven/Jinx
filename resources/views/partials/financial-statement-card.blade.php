@php
    $fsH = $financialStatement['household'];
    $fsLimits = $fsClientPayload['household_limits'];
    $fsCalc = is_array($financialStatement['calculation'] ?? null) ? $financialStatement['calculation'] : [];
    $fsFlags = is_array($financialStatement['flags'] ?? null) ? $financialStatement['flags'] : [];
    $fsTarget = $fsCalc['target_di'] ?? ($financialStatement['facts']['target_di'] ?? null);
    $fsStatus = $financialStatement['status'] ?? null;
    $fsMoney = function ($value) {
        if ($value === null || $value === '') {
            return '—';
        }
        return '£'.number_format((float) $value, 2);
    };
    $fsHouseholdSummary = ((int) ($fsH['adults'] ?? 1)).' adult'.(((int) ($fsH['adults'] ?? 1)) === 1 ? '' : 's')
        .', '.((int) ($fsH['children_under_16'] ?? 0)).' under 16'
        .', '.((int) ($fsH['children_16_18'] ?? 0)).' aged 16–18';
    if (isset($fsH['size'])) {
        $fsHouseholdSummary .= ' · household size '.((int) $fsH['size']);
    }
    $fsWarningItems = [];
    foreach (['rule_required', 'calculator_required'] as $flagKey) {
        foreach ($fsFlags[$flagKey] ?? [] as $flagItem) {
            if (is_string($flagItem) && $flagItem !== '') {
                $fsWarningItems[] = $flagItem;
            }
        }
    }
    foreach ($fsCalc['sfs'] ?? [] as $band => $row) {
        if (! is_array($row)) {
            continue;
        }
        $max = (float) ($row['max'] ?? 0);
        $actual = (float) ($row['actual'] ?? 0);
        $label = match ($band) {
            'housekeeping' => 'Food / housekeeping',
            'comms' => 'Comms & leisure',
            'personal' => 'Personal',
            default => (string) $band,
        };
        if ($max > 0 && $actual > $max + 0.005) {
            $fsWarningItems[] = $label.' exceeds SFS guideline max.';
        }
    }
@endphp

<style>
    .fs-workspace-card { background:#111827; border:1px solid #374151; border-radius:14px; padding:18px 20px 24px; box-sizing:border-box; }
    .fs-workspace-heading { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
    .fs-workspace-title { margin:0; font-size:22px; color:#f8fafc; }
    .fs-workspace-subtitle { font-size:12px; color:#94a3b8; margin-top:4px; }
    .fs-save-status { font-size:13px; color:#9ca3af; }
    .fs-overview { position:sticky; top:0; z-index:20; display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:8px; margin-bottom:16px; padding:10px; border:1px solid #334155; border-radius:12px; background:#0b1220; box-shadow:0 8px 20px rgba(2, 6, 23, 0.35); }
    .fs-overview-metric { background:#020617; border:1px solid #1e293b; border-radius:10px; padding:8px 10px; min-width:0; }
    .fs-overview-metric-wide { grid-column: span 2; }
    .fs-overview-label { font-size:10px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.04em; line-height:1.2; margin-bottom:4px; }
    .fs-overview-value { font-size:16px; font-weight:700; color:#e2e8f0; line-height:1.3; word-break:break-word; }
    .fs-overview-value-sm { font-size:13px; font-weight:600; }
    .fs-household-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; margin-bottom:18px; }
    .fs-field-label { display:block; font-size:13px; color:#9ca3af; margin-bottom:6px; }
    .fs-input { display:block; width:100%; box-sizing:border-box; padding:10px 12px; border-radius:8px; border:1px solid #374151; background:#020617; color:#f9fafb; font-size:15px; min-height:40px; }
    .fs-form-section { background:#0f172a; border:1px solid #334155; border-radius:12px; padding:14px 16px 16px; margin-bottom:12px; }
    .fs-form-section-head { display:flex; justify-content:space-between; align-items:baseline; gap:12px; margin-bottom:12px; }
    .fs-form-section-title { margin:0; font-size:16px; font-weight:700; color:#f9fafb; }
    .fs-form-section-total { font-size:13px; font-weight:600; color:#94a3b8; }
    .fs-field-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px 16px; }
    .fs-expenditure-heading { margin:6px 0 12px; font-size:18px; color:#e5e7eb; }
    .fs-cap-meta { margin:0 0 12px; padding:10px 12px; border-radius:8px; border:1px solid #334155; background:#020617; font-size:13px; line-height:1.5; color:#cbd5e1; }
    .fs-cap-household { margin-top:4px; font-size:12px; color:#94a3b8; }
    .fs-cap-below { margin-top:4px; display:none; color:#fbbf24; }
    .fs-cap-over { margin-top:4px; display:none; color:#fecaca; }
    @media (max-width: 900px) {
        .fs-overview { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .fs-overview-metric-wide { grid-column: span 2; }
        .fs-household-grid, .fs-field-grid { grid-template-columns:1fr; }
    }
</style>

<div id="financialStatementCard" class="fs-workspace-card">
    <div class="fs-workspace-heading">
        <div>
            <h2 class="fs-workspace-title">Financial Statement</h2>
            <div class="fs-workspace-subtitle">Continuous I&amp;E form · schema v2 field mappings preserved</div>
        </div>
        <div id="financialStatementStatus" class="fs-save-status">Ready</div>
    </div>

    <div
        id="fsOverview"
        class="fs-overview"
        data-target-di="{{ $fsTarget === null || $fsTarget === '' ? '' : $fsTarget }}"
        data-status="{{ $fsStatus ?? '' }}"
        data-flags="{{ e(json_encode(array_values($fsWarningItems))) }}"
    >
        <div class="fs-overview-metric">
            <div class="fs-overview-label">Target DI</div>
            <div id="fs-overview-target-di" class="fs-overview-value">{{ $fsMoney($fsTarget) }}</div>
        </div>
        <div class="fs-overview-metric">
            <div class="fs-overview-label">Current DI</div>
            <div id="fs-summary-disposable-total" class="fs-overview-value">{{ $fsMoney($fsCalc['disposable_income'] ?? null) }}</div>
        </div>
        <div class="fs-overview-metric">
            <div class="fs-overview-label">Difference to Target</div>
            <div id="fs-overview-diff-target" class="fs-overview-value">{{ $fsMoney($fsCalc['variance_to_target'] ?? null) }}</div>
        </div>
        <div class="fs-overview-metric">
            <div class="fs-overview-label">Total Income</div>
            <div id="fs-summary-income-total" class="fs-overview-value">{{ $fsMoney($fsCalc['income_total'] ?? null) }}</div>
        </div>
        <div class="fs-overview-metric">
            <div class="fs-overview-label">Total Expenditure</div>
            <div id="fs-summary-expenditure-total" class="fs-overview-value">{{ $fsMoney($fsCalc['expenditure_total'] ?? null) }}</div>
        </div>
        <div class="fs-overview-metric fs-overview-metric-wide">
            <div class="fs-overview-label">Household</div>
            <div id="fs-overview-household" class="fs-overview-value fs-overview-value-sm">{{ $fsHouseholdSummary }}</div>
        </div>
        <div class="fs-overview-metric">
            <div class="fs-overview-label">SFS status</div>
            <div id="fs-overview-status" class="fs-overview-value fs-overview-value-sm">{{ $fsStatus ? str_replace('_', ' ', $fsStatus) : '—' }}</div>
        </div>
        <div class="fs-overview-metric fs-overview-metric-wide">
            <div class="fs-overview-label">SFS warnings</div>
            <div id="fs-overview-warnings" class="fs-overview-value fs-overview-value-sm">
                @if (count($fsWarningItems))
                    {{ implode(' ', $fsWarningItems) }}
                @else
                    None
                @endif
            </div>
        </div>
    </div>

    <div class="fs-household-grid">
        <div>
            <label for="fsAdults" class="fs-field-label">Adults</label>
            <select id="fsAdults" data-fs-household="adults" class="fs-input">
                @for ($a = $fsLimits['adults_min']; $a <= $fsLimits['adults_max']; $a++)
                    <option value="{{ $a }}" @selected((int) $fsH['adults'] === $a)>{{ $a }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label for="fsChildrenU16" class="fs-field-label">Children under 16</label>
            <select id="fsChildrenU16" data-fs-household="children_under_16" class="fs-input">
                @for ($c = $fsLimits['children_min']; $c <= $fsLimits['children_max']; $c++)
                    <option value="{{ $c }}" @selected((int) $fsH['children_under_16'] === $c)>{{ $c }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label for="fsChildren1618" class="fs-field-label">Children 16–18</label>
            <select id="fsChildren1618" data-fs-household="children_16_18" class="fs-input">
                @for ($c = $fsLimits['children_min']; $c <= $fsLimits['children_max']; $c++)
                    <option value="{{ $c }}" @selected((int) $fsH['children_16_18'] === $c)>{{ $c }}</option>
                @endfor
            </select>
        </div>
    </div>

    <section class="fs-form-section">
        <div class="fs-form-section-head">
            <h3 class="fs-form-section-title">Income</h3>
            <div class="fs-form-section-total">Total <span id="fs-sum-income">£0.00</span></div>
        </div>
        <div class="fs-field-grid">
            @foreach ($fsClientPayload['income'] as $row)
                @php
                    $amt = (float) ($financialStatement['income'][$row['code']] ?? 0);
                    $showAmt = abs($amt) >= 0.005 ? number_format($amt, 2, '.', '') : '';
                @endphp
                <div class="fs-field">
                    <label class="fs-field-label">{{ $row['label'] }}</label>
                    <input
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="0.01"
                        data-fs-income="{{ $row['code'] }}"
                        value="{{ $showAmt }}"
                        placeholder="0.00"
                        class="fs-input"
                    >
                </div>
            @endforeach
        </div>
    </section>

    <h3 class="fs-expenditure-heading">Expenditure</h3>
    @foreach ($fsClientPayload['expenditure_sections'] as $section)
        @php
            $capBand = $section['cap_band'] ?? null;
            $summaryId = 'fs-sum-'.$section['id'];
            $metaId = 'fs-meta-'.$section['id'];
        @endphp
        <section class="fs-form-section" data-fs-ie-card>
            <div class="fs-form-section-head">
                <h3 class="fs-form-section-title">{{ $section['title'] }}</h3>
                <div class="fs-form-section-total">Total <span id="{{ $summaryId }}">£0.00</span></div>
            </div>
            @if ($capBand)
                <div
                    id="{{ $metaId }}"
                    class="fs-cap-meta"
                    data-fs-cap-section="{{ $section['id'] }}"
                    data-fs-cap-band="{{ $capBand }}"
                >
                    <div>Guideline cap: <strong data-fs-cap-value>£0.00</strong></div>
                    <div class="fs-cap-household">
                        Household for this cap: <span data-fs-household-label></span>
                    </div>
                    <div><span data-fs-pct-wrap></span></div>
                    <div data-fs-below65 class="fs-cap-below"></div>
                    <div data-fs-over class="fs-cap-over"></div>
                </div>
            @endif
            <div class="fs-field-grid">
                @foreach ($section['lines'] as $line)
                    @php
                        $eAmt = (float) ($financialStatement['expenditure'][$line['code']] ?? 0);
                        $eShow = abs($eAmt) >= 0.005 ? number_format($eAmt, 2, '.', '') : '';
                    @endphp
                    <div class="fs-field">
                        <label class="fs-field-label">{{ $line['label'] }}</label>
                        <input
                            type="number"
                            inputmode="decimal"
                            min="0"
                            step="0.01"
                            data-fs-expenditure="{{ $line['code'] }}"
                            data-fs-section="{{ $section['id'] }}"
                            value="{{ $eShow }}"
                            placeholder="0.00"
                            class="fs-input"
                        >
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
