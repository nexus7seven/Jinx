@php
    $fsH = $financialStatement['household'];
    $fsLimits = $fsClientPayload['household_limits'];
@endphp

<div id="financialStatementCard" style="background:#111827; border:1px solid #374151; border-radius:14px; padding:22px; box-sizing:border-box; margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <div>
            <h2 style="margin:0; font-size:24px;">Income &amp; expenditure</h2>
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
                <div style="display:flex; flex-direction:column; gap:12px; margin-top:12px;">
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
                        <div style="display:flex; flex-direction:column; gap:12px; margin-top:12px;">
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
