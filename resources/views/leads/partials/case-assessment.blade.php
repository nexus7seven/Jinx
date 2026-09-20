<div id="caseAssessmentApp" data-lead-id="{{ $lead->id }}" data-loaded="0">
    <style>
        .case-assessment-card { background:#111827; border:1px solid #374151; border-radius:14px; padding:20px; box-sizing:border-box; }
        .case-assessment-header { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
        .case-assessment-title { margin:0; font-size:24px; color:#f8fafc; }
        .case-assessment-subtitle { margin-top:5px; color:#94a3b8; font-size:13px; line-height:1.45; max-width:800px; }
        .case-assessment-header-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .case-assessment-refresh, .case-assessment-secondary { border:1px solid #334155; background:#172036; color:#dbeafe; border-radius:8px; padding:9px 12px; font-weight:700; cursor:pointer; }
        .case-assessment-secondary { background:#0f172a; color:#cbd5e1; }
        .case-assessment-refresh:hover, .case-assessment-secondary:hover { border-color:#60a5fa; }
        .case-assessment-summary { display:grid; grid-template-columns:repeat(5,minmax(120px,1fr)); gap:10px; margin-bottom:14px; }
        .case-assessment-stat { background:#020617; border:1px solid #1e293b; border-radius:10px; padding:12px; min-width:0; }
        .case-assessment-stat-label { color:#94a3b8; font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:800; }
        .case-assessment-stat-value { margin-top:5px; color:#f8fafc; font-size:20px; font-weight:900; overflow-wrap:anywhere; }
        .case-assessment-readiness { margin-bottom:14px; padding:12px 14px; border:1px solid #334155; border-radius:10px; background:#0f172a; }
        .case-assessment-readiness-note { margin-top:5px; color:#cbd5e1; font-size:13px; line-height:1.45; }
        .case-assessment-latest { margin-top:10px; color:#cbd5e1; font-size:12px; line-height:1.45; }

        .case-assessment-workspace { display:grid; grid-template-columns:220px minmax(0,1fr); gap:14px; align-items:start; }
        .case-assessment-side-nav { position:sticky; top:0; align-self:start; background:#0b1220; border:1px solid #273449; border-radius:12px; padding:8px; max-height:calc(100vh - 210px); overflow:auto; }
        .case-assessment-side-kicker { padding:7px 8px 9px; color:#64748b; font-size:9px; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
        .case-assessment-nav-btn { width:100%; display:flex; align-items:center; justify-content:space-between; gap:8px; border:1px solid transparent; border-radius:8px; background:transparent; color:#94a3b8; padding:9px 10px; text-align:left; font:inherit; font-size:12px; font-weight:800; cursor:pointer; margin-bottom:3px; }
        .case-assessment-nav-btn:hover { background:#111827; color:#f8fafc; border-color:#334155; }
        .case-assessment-nav-btn.is-active { background:#172554; border-color:#1d4ed8; color:#dbeafe; }
        .case-assessment-nav-btn.is-alert { color:#fde68a; }
        .case-assessment-nav-count { min-width:22px; height:20px; display:inline-flex; align-items:center; justify-content:center; border-radius:999px; background:#1e293b; color:#cbd5e1; font-size:9px; font-weight:900; padding:0 5px; }
        .case-assessment-nav-btn.is-alert .case-assessment-nav-count { background:#78350f; color:#fef3c7; }
        .case-assessment-main-panel { min-width:0; }
        .case-assessment-workspace-panel { display:none; min-width:0; }
        .case-assessment-workspace-panel.is-active { display:block; }
        .case-assessment-panel-heading { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin:0 0 12px; }
        .case-assessment-panel-title { margin:0; color:#f8fafc; font-size:19px; }
        .case-assessment-panel-description { margin-top:4px; color:#94a3b8; font-size:12px; line-height:1.45; }

        .case-assessment-attention { margin:14px 0 18px; padding:14px; border:1px solid #92400e; background:#451a03; border-radius:12px; }
        .case-assessment-attention-title { color:#fef3c7; font-size:14px; font-weight:900; margin-bottom:8px; }
        .case-assessment-attention-list { display:flex; gap:7px; flex-wrap:wrap; }
        .case-assessment-attention-item { border:1px solid #a16207; background:#422006; color:#fde68a; border-radius:999px; padding:7px 10px; font-size:11px; font-weight:800; cursor:pointer; }
        .case-assessment-attention-item:hover { border-color:#fbbf24; }

        .case-assessment-form-section { margin-top:12px; border:1px solid #334155; background:#0f172a; border-radius:12px; overflow:hidden; }
        .case-assessment-form-section > summary { list-style:none; cursor:pointer; padding:13px 15px; display:flex; justify-content:space-between; align-items:center; gap:12px; }
        .case-assessment-form-section > summary::-webkit-details-marker { display:none; }
        .case-assessment-form-section-title { color:#f8fafc; font-weight:900; font-size:15px; }
        .case-assessment-form-section-description { margin-top:3px; color:#94a3b8; font-size:11px; line-height:1.4; }
        .case-assessment-form-section-count { color:#64748b; font-size:11px; white-space:nowrap; }
        .case-assessment-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px 12px; padding:0 14px 14px; }
        .case-assessment-form-field { border:1px solid #273449; background:#020617; border-radius:10px; padding:11px; min-width:0; transition:border-color .15s ease, background .15s ease; }
        .case-assessment-form-field.is-missing { border-color:#92400e; background:#1c1207; }
        .case-assessment-form-field.is-derived { background:#071326; border-color:#1e3a5f; }
        .case-assessment-form-label-row { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:7px; }
        .case-assessment-form-label { color:#e2e8f0; font-size:12px; font-weight:800; line-height:1.35; }
        .case-assessment-form-source { color:#64748b; font-size:9px; white-space:nowrap; }
        .case-assessment-form-help { margin-top:6px; color:#64748b; font-size:10px; line-height:1.4; }
        .case-assessment-form-help.is-missing { color:#fbbf24; }
        .case-assessment-input-wrap { position:relative; }
        .case-assessment-input-prefix { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#64748b; font-size:13px; pointer-events:none; }
        .case-assessment-input { width:100%; box-sizing:border-box; min-height:38px; border:1px solid #334155; border-radius:8px; background:#0b1220; color:#f8fafc; padding:8px 10px; font-size:14px; outline:none; }
        .case-assessment-input.has-prefix { padding-left:25px; }
        .case-assessment-input:focus { border-color:#60a5fa; }
        .case-assessment-input:disabled { opacity:.75; cursor:not-allowed; background:#0f172a; }
        select.case-assessment-input { cursor:pointer; }
        .case-assessment-custom-input { margin-top:7px; }
        .case-assessment-add-row { display:flex; align-items:center; gap:8px; margin:0 0 12px; max-width:420px; }
        .case-assessment-add-label { color:#94a3b8; font-size:11px; font-weight:800; white-space:nowrap; }
        .case-assessment-readonly { min-height:38px; display:flex; align-items:center; padding:8px 10px; box-sizing:border-box; border:1px solid #1e3a5f; border-radius:8px; background:#071326; color:#bfdbfe; font-size:14px; font-weight:800; }
        .case-assessment-save-state { margin-top:4px; color:#64748b; font-size:9px; min-height:12px; }

        .case-assessment-boolean { display:inline-flex; gap:4px; padding:2px; border:1px solid #334155; border-radius:8px; background:#0f172a; }
        .case-assessment-boolean-btn { min-width:54px; min-height:34px; border:0; border-radius:6px; padding:6px 10px; background:transparent; color:#94a3b8; font-size:11px; font-weight:900; cursor:pointer; }
        .case-assessment-boolean-btn:hover { background:#1e293b; color:#f8fafc; }
        .case-assessment-boolean-btn.is-selected[data-value="true"] { background:#166534; color:#dcfce7; }
        .case-assessment-boolean-btn.is-selected[data-value="false"] { background:#7f1d1d; color:#fee2e2; }
        .case-assessment-boolean-btn:disabled { opacity:.55; cursor:wait; }

        .assessment-badge { display:inline-flex; align-items:center; border-radius:999px; border:1px solid #334155; padding:3px 7px; font-size:10px; font-weight:900; white-space:nowrap; }
        .assessment-badge-known, .assessment-badge-basic-pass, .assessment-badge-clear-fit, .assessment-badge-satisfied, .assessment-badge-ready-for-reasoning { border-color:#166534; background:#052e16; color:#bbf7d0; }
        .assessment-badge-derived { border-color:#1d4ed8; background:#172554; color:#bfdbfe; }
        .assessment-badge-missing, .assessment-badge-unknown { border-color:#92400e; background:#451a03; color:#fde68a; }
        .assessment-badge-blocked { border-color:#991b1b; background:#450a0a; color:#fecaca; }
        .assessment-badge-fit-with-actions, .assessment-badge-exception-escalation { border-color:#854d0e; background:#422006; color:#fef08a; }
        .assessment-badge-not-applicable, .assessment-badge-not-recorded { border-color:#475569; background:#0f172a; color:#94a3b8; }

        .case-assessment-section { margin-top:20px; }
        .case-assessment-section-title { margin:0 0 9px; font-size:16px; color:#e2e8f0; }
        .case-assessment-routes { display:grid; grid-template-columns:repeat(5,minmax(180px,1fr)); gap:10px; }
        .case-assessment-route { border:1px solid #334155; background:#020617; border-radius:10px; padding:12px; min-width:0; }
        .case-assessment-route-head { display:flex; justify-content:space-between; gap:8px; align-items:center; }
        .case-assessment-route-name { color:#f8fafc; font-weight:900; font-size:14px; }
        .case-assessment-route-meta { margin-top:6px; color:#64748b; font-size:10px; }
        .case-assessment-route-findings { margin:9px 0 0; padding-left:17px; color:#cbd5e1; font-size:11px; line-height:1.45; }
        .case-assessment-route-findings li { margin-bottom:6px; }
        .case-assessment-route-source { display:block; margin-top:2px; color:#64748b; font-size:10px; }

        .case-assessment-debt { border:1px solid #334155; background:#0f172a; border-radius:10px; margin-bottom:10px; overflow:hidden; }
        .case-assessment-debt > summary { cursor:pointer; padding:12px 14px; list-style:none; display:flex; justify-content:space-between; gap:12px; align-items:center; }
        .case-assessment-debt > summary::-webkit-details-marker { display:none; }
        .case-assessment-debt-title { color:#f8fafc; font-weight:900; }
        .case-assessment-debt-meta { color:#94a3b8; font-size:11px; }
        .case-assessment-debt-body { padding:0 12px 12px; }

        .case-assessment-audit { margin-top:20px; border:1px solid #273449; border-radius:12px; background:#0b1220; overflow:hidden; }
        .case-assessment-audit > summary { list-style:none; cursor:pointer; padding:13px 15px; color:#cbd5e1; font-size:13px; font-weight:800; }
        .case-assessment-audit > summary::-webkit-details-marker { display:none; }
        .case-assessment-audit-body { padding:0 12px 12px; }
        .case-assessment-table-wrap { overflow:auto; border:1px solid #1e293b; border-radius:10px; }
        .case-assessment-table { width:100%; border-collapse:collapse; min-width:820px; background:#020617; }
        .case-assessment-table th { text-align:left; padding:9px 10px; color:#94a3b8; font-size:10px; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid #1e293b; background:#0f172a; position:sticky; top:0; z-index:1; }
        .case-assessment-table td { padding:10px; border-bottom:1px solid rgba(30,41,59,.75); color:#e2e8f0; font-size:12px; vertical-align:top; line-height:1.45; }
        .case-assessment-table tr:last-child td { border-bottom:0; }
        .case-assessment-key { margin-top:3px; color:#64748b; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:10px; }
        .case-assessment-source-detail { margin-top:3px; color:#64748b; font-size:10px; }
        .case-assessment-edit { border:1px solid #334155; background:#111827; color:#93c5fd; border-radius:7px; padding:5px 8px; font-size:11px; font-weight:700; cursor:pointer; white-space:nowrap; }
        .case-assessment-edit:hover { border-color:#60a5fa; }

        .case-assessment-loading, .case-assessment-error { border:1px solid #334155; background:#020617; border-radius:10px; padding:16px; color:#94a3b8; }
        .case-assessment-error { border-color:#7f1d1d; color:#fecaca; background:#3f1d1d; }

        @media (max-width:1200px) {
            .case-assessment-summary { grid-template-columns:repeat(2,minmax(120px,1fr)); }
            .case-assessment-routes { grid-template-columns:repeat(2,minmax(180px,1fr)); }
        }
        @media (max-width:900px) {
            .case-assessment-workspace { grid-template-columns:1fr; }
            .case-assessment-side-nav { position:static; display:flex; gap:6px; overflow:auto; max-height:none; padding:7px; }
            .case-assessment-side-kicker { display:none; }
            .case-assessment-nav-btn { width:auto; min-width:max-content; margin:0; }
        }
        @media (max-width:780px) {
            .case-assessment-summary, .case-assessment-routes, .case-assessment-form-grid { grid-template-columns:1fr; }
        }
    </style>

    <div class="case-assessment-card">
        <div class="case-assessment-header">
            <div>
                <h2 class="case-assessment-title">Case Assessment</h2>
                <div class="case-assessment-subtitle">A live case form and reasoning view. Fill facts here or tell Jinx in the assistant — both use the same underlying case, debt and Financial Statement data.</div>
            </div>
            <div class="case-assessment-header-actions">
                <button type="button" id="caseAssessmentOpenFs" class="case-assessment-secondary">Full Financial Statement</button>
                <button type="button" id="caseAssessmentRefresh" class="case-assessment-refresh">Refresh assessment</button>
            </div>
        </div>

        <div id="caseAssessmentContent">
            <div class="case-assessment-loading">Open this tab to load the current case assessment.</div>
        </div>
    </div>

    <script>
    (() => {
        const app = document.getElementById('caseAssessmentApp');
        if (!app || app.dataset.initialised === '1') return;
        app.dataset.initialised = '1';

        const leadId = app.dataset.leadId;
        const content = document.getElementById('caseAssessmentContent');
        const refreshButton = document.getElementById('caseAssessmentRefresh');
        const openFsButton = document.getElementById('caseAssessmentOpenFs');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        let loading = false;
        let currentAssessmentData = null;
        let activeSectionKey = sessionStorage.getItem('jinxCaseAssessmentSection:' + leadId) || 'overview';

        const esc = (value) => String(value ?? '')
            .replaceAll('&','&amp;')
            .replaceAll('<','&lt;')
            .replaceAll('>','&gt;')
            .replaceAll('"','&quot;')
            .replaceAll("'",'&#039;');

        const slug = (value) => String(value ?? 'unknown').toLowerCase().replaceAll('_','-').replace(/[^a-z0-9-]+/g,'-');

        function badge(status, label = null) {
            const text = label || String(status || 'Unknown').replaceAll('_',' ');
            return '<span class="assessment-badge assessment-badge-' + slug(status) + '">' + esc(text) + '</span>';
        }

        function stat(label, value) {
            return '<div class="case-assessment-stat"><div class="case-assessment-stat-label">' + esc(label) + '</div><div class="case-assessment-stat-value">' + esc(value) + '</div></div>';
        }

        function renderSummary(data) {
            const s = data.summary || {};
            const readinessLabel = s.ready ? 'Ready for reasoning' : (s.readiness_state || 'Unknown').replaceAll('_',' ');
            const note = s.ready
                ? 'All currently applicable material reasoning facts are present.'
                : (s.next_question ? 'Next material fact: ' + esc(s.next_question) : 'The case is not yet ready for full reasoning.');

            let latest = '';
            if (s.latest_saved_assessment) {
                const a = s.latest_saved_assessment;
                latest = '<div class="case-assessment-latest"><strong>Last saved full assessment:</strong> '
                    + esc(a.preferred_route || '—') + ' · ' + badge(a.status || 'UNKNOWN')
                    + (a.assessed_at ? ' · ' + esc(a.assessed_at) : '')
                    + (a.rationale ? '<div style="margin-top:5px;">' + esc(a.rationale) + '</div>' : '')
                    + '</div>';
            } else {
                latest = '<div class="case-assessment-latest">No full case-reasoning decision has been saved yet.</div>';
            }

            return '<div class="case-assessment-summary">'
                + stat('Readiness', readinessLabel)
                + stat('Known', s.known ?? 0)
                + stat('Missing', s.missing ?? 0)
                + stat('N/A hidden', s.not_applicable ?? 0)
                + stat('Not collected', s.not_recorded ?? 0)
                + '</div>'
                + '<div class="case-assessment-readiness">' + badge(s.ready ? 'ready_for_reasoning' : 'missing', readinessLabel)
                + '<div class="case-assessment-readiness-note">' + note + '</div>' + latest + '</div>';
        }

        function renderAttention(items) {
            if (!Array.isArray(items) || !items.length) return '';
            return '<div class="case-assessment-attention">'
                + '<div class="case-assessment-attention-title">' + esc(items.length) + ' item' + (items.length === 1 ? '' : 's') + ' need attention</div>'
                + '<div class="case-assessment-attention-list">'
                + items.map(item => '<button type="button" class="case-assessment-attention-item" data-focus-key="' + esc(item.fact_key) + '"'
                    + (item.debt_id ? ' data-focus-debt="' + esc(item.debt_id) + '"' : '')
                    + '>' + esc(item.label) + '</button>').join('')
                + '</div></div>';
        }

        function commonData(field, debtId = null) {
            return ' data-scope="' + esc(field.scope) + '"'
                + ' data-fact-key="' + esc(field.fact_key) + '"'
                + ' data-data-type="' + esc(field.data_type) + '"'
                + (debtId ? ' data-debt-id="' + esc(debtId) + '"' : '');
        }

        function formControl(field, debtId = null) {
            if (!field.editable) {
                return '<div class="case-assessment-readonly">' + esc(field.display_value || '—') + '</div>';
            }

            const common = commonData(field, debtId);
            const control = field.control || (field.data_type === 'boolean' ? 'boolean' : 'input');

            if (control === 'boolean') {
                return '<div class="case-assessment-boolean" role="group" aria-label="' + esc(field.label) + '">'
                    + '<button type="button" class="case-assessment-boolean-btn' + (field.value === true ? ' is-selected' : '') + '" data-assessment-boolean="1" data-value="true"' + common + '>Yes</button>'
                    + '<button type="button" class="case-assessment-boolean-btn' + (field.value === false ? ' is-selected' : '') + '" data-assessment-boolean="1" data-value="false"' + common + '>No</button>'
                    + '</div>';
            }

            if (['select','select_custom','select_custom_number'].includes(control)) {
                const current = field.ui_value === null || field.ui_value === undefined ? '' : String(field.ui_value);
                const options = Array.isArray(field.options) ? field.options : [];
                const optionHtml = ['<option value="">Select…</option>'].concat(options.map(option => {
                    const value = String(option.value ?? '');
                    return '<option value="' + esc(value) + '"' + (value === current ? ' selected' : '') + '>' + esc(option.label ?? value) + '</option>';
                })).join('');

                const customType = control === 'select_custom_number' ? 'number' : 'text';
                const customExtra = control === 'select_custom_number' ? ' min="5" step="1" inputmode="numeric"' : '';
                const customValue = field.custom_value === null || field.custom_value === undefined ? '' : String(field.custom_value);
                const showCustom = current === '__custom__';
                const custom = control === 'select'
                    ? ''
                    : '<input class="case-assessment-input case-assessment-custom-input" type="' + customType + '"'
                        + ' value="' + esc(customValue) + '"' + customExtra + common
                        + ' data-assessment-custom-input="1"'
                        + ' style="' + (showCustom ? '' : 'display:none;') + '"'
                        + ' placeholder="' + (control === 'select_custom_number' ? 'Enter exact number' : 'Enter other value') + '">';

                return '<select class="case-assessment-input"' + common
                    + ' data-assessment-select="1" data-control="' + esc(control) + '">' + optionHtml + '</select>'
                    + custom
                    + '<div class="case-assessment-save-state" data-save-state="' + esc(field.fact_key) + '"></div>';
            }

            const raw = field.value === null || field.value === undefined ? '' : String(field.value);
            let type = 'text';
            let extra = '';
            let prefix = '';
            let inputClass = 'case-assessment-input';

            if (field.data_type === 'money' || field.data_type === 'money_or_none') {
                type = 'number';
                extra = ' min="0" step="0.01" inputmode="decimal"';
                prefix = '<span class="case-assessment-input-prefix">£</span>';
                inputClass += ' has-prefix';
            } else if (field.data_type === 'integer') {
                type = 'number';
                extra = ' min="0" step="1" inputmode="numeric"';
            } else if (field.data_type === 'percentage') {
                type = 'number';
                extra = ' min="0" max="100" step="0.01" inputmode="decimal"';
            } else if (field.data_type === 'date') {
                type = 'date';
            }

            return '<div class="case-assessment-input-wrap">' + prefix
                + '<input class="' + inputClass + '" type="' + type + '" value="' + esc(raw) + '"'
                + extra + common + ' data-assessment-input="1" autocomplete="off">'
                + '</div><div class="case-assessment-save-state" data-save-state="' + esc(field.fact_key) + '"></div>';
        }

        function renderFormField(field, debtId = null) {
            const classes = ['case-assessment-form-field'];
            if (field.status === 'missing') classes.push('is-missing');
            if (!field.editable) classes.push('is-derived');

            const help = field.help
                ? '<div class="case-assessment-form-help' + (field.status === 'missing' ? ' is-missing' : '') + '">' + esc(field.help) + '</div>'
                : '';

            return '<div class="' + classes.join(' ') + '" data-form-field="' + esc(field.fact_key) + '"'
                + (debtId ? ' data-form-debt="' + esc(debtId) + '"' : '') + '>'
                + '<div class="case-assessment-form-label-row"><div class="case-assessment-form-label">' + esc(field.label) + '</div>'
                + '<div class="case-assessment-form-source">' + esc(field.source || '') + '</div></div>'
                + formControl(field, debtId)
                + help
                + '</div>';
        }

        function renderFormSection(section) {
            const missing = (section.fields || []).filter(field => field.status === 'missing').length;
            const addable = Array.isArray(section.addable_fields) ? section.addable_fields : [];
            const addControl = addable.length
                ? '<div class="case-assessment-add-row"><span class="case-assessment-add-label">' + esc(section.add_control_label || 'Add field') + '</span>'
                    + '<select class="case-assessment-input" data-add-form-field="' + esc(section.key) + '">'
                    + '<option value="">Choose…</option>'
                    + addable.map(field => '<option value="' + esc(field.fact_key) + '">' + esc(field.label) + '</option>').join('')
                    + '</select></div>'
                : '';

            return '<div class="case-assessment-panel-heading"><div><h3 class="case-assessment-panel-title">' + esc(section.label) + '</h3>'
                + '<div class="case-assessment-panel-description">' + esc(section.description || '') + '</div></div>'
                + '<div class="case-assessment-form-section-count">' + esc(section.fields.length) + ' fields'
                + (missing ? ' · ' + esc(missing) + ' missing' : '') + '</div></div>'
                + addControl
                + '<div class="case-assessment-form-grid" style="padding:0;">' + (section.fields || []).map(field => renderFormField(field)).join('') + '</div>';
        }

        function renderDebtForms(debts) {
            if (!Array.isArray(debts) || !debts.length) {
                return '<section class="case-assessment-section"><h3 class="case-assessment-section-title">Debt information</h3><div class="case-assessment-loading">No debts are recorded.</div></section>';
            }

            const cards = debts.map(debt => {
                const fields = (debt.facts || []).filter(field => field.status !== 'not_applicable');
                const conflict = debt.voting_conflict
                    ? '<div class="case-assessment-form-help is-missing">' + esc(debt.voting_conflict_reason || 'Voting mapping conflict') + '</div>'
                    : '';

                return '<details class="case-assessment-debt">'
                    + '<summary><div><div class="case-assessment-debt-title">' + esc(debt.creditor) + ' · ' + esc(debt.balance_display) + '</div>'
                    + '<div class="case-assessment-debt-meta">Voting: ' + esc(debt.voting_house) + ' · ' + esc(debt.voting_percent) + '% of known debt</div>' + conflict + '</div>'
                    + '<span class="case-assessment-debt-meta">Edit account facts</span></summary>'
                    + '<div class="case-assessment-debt-body"><div class="case-assessment-form-grid">'
                    + fields.map(field => renderFormField({
                        ...field,
                        scope:'debt',
                        help:field.assessment
                    }, debt.debt_id)).join('')
                    + '</div></div></details>';
            }).join('');

            return '<section class="case-assessment-section"><h3 class="case-assessment-section-title">Debt information</h3>' + cards + '</section>';
        }

        function renderRoutes(routes) {
            if (!Array.isArray(routes) || !routes.length) return '';
            const cards = routes.map(route => {
                const findings = Array.isArray(route.findings) && route.findings.length
                    ? '<ul class="case-assessment-route-findings">' + route.findings.map(f =>
                        '<li>' + badge(f.status) + ' ' + esc(f.message || '')
                        + (f.source ? '<span class="case-assessment-route-source">' + esc(f.source) + '</span>' : '')
                        + '</li>'
                    ).join('') + '</ul>'
                    : '<div class="case-assessment-route-meta">No current non-passing deterministic findings.</div>';

                return '<div class="case-assessment-route">'
                    + '<div class="case-assessment-route-head"><div class="case-assessment-route-name">' + esc(route.destination) + '</div>' + badge(route.status) + '</div>'
                    + '<div class="case-assessment-route-meta">' + esc(route.passed_checks || 0) + ' checks currently satisfied'
                    + (route.unresolved_voting_percent > 0 ? ' · ' + esc(route.unresolved_voting_percent) + '% voting unresolved' : '') + '</div>'
                    + findings + '</div>';
            }).join('');

            return '<section class="case-assessment-section"><h3 class="case-assessment-section-title">Current route checks</h3><div class="case-assessment-routes">' + cards + '</div></section>';
        }

        function factTable(rows) {
            if (!Array.isArray(rows) || !rows.length) {
                return '<div class="case-assessment-loading">No active data points in this section.</div>';
            }

            const body = rows.map(row => '<tr>'
                + '<td><strong>' + esc(row.label) + '</strong><div class="case-assessment-key">' + esc(row.fact_key) + '</div></td>'
                + '<td>' + esc(row.display_value) + '</td>'
                + '<td>' + esc(row.source || '—') + (row.source_detail ? '<div class="case-assessment-source-detail">' + esc(row.source_detail) + '</div>' : '') + '</td>'
                + '<td>' + esc(row.assessment || '—') + '</td>'
                + '<td>' + badge(row.status, row.status_label) + '</td>'
                + '</tr>').join('');

            return '<div class="case-assessment-table-wrap"><table class="case-assessment-table">'
                + '<thead><tr><th>Data point</th><th>Current value</th><th>Source</th><th>How assessed</th><th>Status</th></tr></thead>'
                + '<tbody>' + body + '</tbody></table></div>';
        }

        function renderAudit(groups) {
            return '<div class="case-assessment-panel-heading"><div><h3 class="case-assessment-panel-title">Assessment detail & provenance</h3>'
                + '<div class="case-assessment-panel-description">Technical view of the active fact registry, where each value came from, and how Jinx is using it.</div></div></div>'
                + (groups || []).map(group => '<section class="case-assessment-section"><h3 class="case-assessment-section-title">' + esc(group.label) + '</h3>'
                    + factTable(group.facts || []) + '</section>').join('');
        }

        function workspaceSections(data) {
            const sections = [];
            sections.push({
                key:'overview',
                label:'Overview',
                count:null,
                alert:false,
                html:renderSummary(data)
            });

            const attention = data.needs_attention || [];
            if (attention.length) {
                sections.push({
                    key:'attention',
                    label:'Needs attention',
                    count:attention.length,
                    alert:true,
                    html:'<div class="case-assessment-panel-heading"><div><h3 class="case-assessment-panel-title">Needs attention</h3>'
                        + '<div class="case-assessment-panel-description">Only the currently applicable missing facts are shown here. Click one to jump straight to its field.</div></div></div>'
                        + renderAttention(attention)
                });
            }

            (data.form_sections || []).forEach(section => {
                const missing = (section.fields || []).filter(field => field.status === 'missing').length;
                sections.push({
                    key:'form:' + section.key,
                    label:section.label,
                    count:missing || null,
                    alert:missing > 0,
                    html:renderFormSection(section)
                });
            });

            const debtMissing = (data.debts || []).reduce((total, debt) => total + (debt.facts || []).filter(f => f.status === 'missing').length, 0);
            sections.push({
                key:'debts',
                label:'Debts',
                count:debtMissing || null,
                alert:debtMissing > 0,
                html:'<div class="case-assessment-panel-heading"><div><h3 class="case-assessment-panel-title">Debt information</h3>'
                    + '<div class="case-assessment-panel-description">Creditor-by-creditor reasoning data and voting-house resolution.</div></div></div>'
                    + renderDebtForms(data.debts || [])
            });

            const routeIssues = (data.routes || []).filter(route => !['BASIC_PASS','CLEAR_FIT','SATISFIED'].includes(route.status)).length;
            sections.push({
                key:'routes',
                label:'Route checks',
                count:routeIssues || null,
                alert:false,
                html:'<div class="case-assessment-panel-heading"><div><h3 class="case-assessment-panel-title">Route checks</h3>'
                    + '<div class="case-assessment-panel-description">Current deterministic route checks. This does not start a new full AI case-reasoning run.</div></div></div>'
                    + renderRoutes(data.routes || [])
            });

            sections.push({
                key:'audit',
                label:'Detail / audit',
                count:null,
                alert:false,
                html:renderAudit(data.groups || [])
            });

            return sections;
        }

        function activateWorkspaceSection(key, focus = false) {
            const available = Array.from(content.querySelectorAll('[data-assessment-workspace-panel]')).map(panel => panel.dataset.assessmentWorkspacePanel);
            if (!available.includes(key)) key = available.includes('overview') ? 'overview' : available[0];
            if (!key) return;

            activeSectionKey = key;
            sessionStorage.setItem('jinxCaseAssessmentSection:' + leadId, key);

            content.querySelectorAll('[data-assessment-nav]').forEach(button => {
                const on = button.dataset.assessmentNav === key;
                button.classList.toggle('is-active', on);
                button.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            content.querySelectorAll('[data-assessment-workspace-panel]').forEach(panel => {
                panel.classList.toggle('is-active', panel.dataset.assessmentWorkspacePanel === key);
            });

            const activeButton = content.querySelector('[data-assessment-nav="' + CSS.escape(key) + '"]');
            const label = activeButton ? activeButton.dataset.assessmentLabel : 'Case Assessment';
            window.dispatchEvent(new CustomEvent('jinx:assessment-section', { detail:{ key, label } }));

            if (focus) {
                const panel = content.querySelector('[data-assessment-workspace-panel="' + CSS.escape(key) + '"]');
                if (panel) panel.scrollIntoView({ block:'start' });
            }
        }

        function render(data) {
            currentAssessmentData = data;
            const sections = workspaceSections(data);
            if (!sections.some(section => section.key === activeSectionKey)) {
                activeSectionKey = (data.needs_attention || []).length ? 'attention' : 'overview';
            }

            const nav = sections.map(section => '<button type="button" class="case-assessment-nav-btn'
                    + (section.alert ? ' is-alert' : '') + '" data-assessment-nav="' + esc(section.key) + '" data-assessment-label="' + esc(section.label) + '">'
                    + '<span>' + esc(section.label) + '</span>'
                    + (section.count ? '<span class="case-assessment-nav-count">' + esc(section.count) + '</span>' : '')
                    + '</button>').join('');

            const panels = sections.map(section => '<div class="case-assessment-workspace-panel" data-assessment-workspace-panel="' + esc(section.key) + '">'
                    + section.html + '</div>').join('');

            content.innerHTML = '<div class="case-assessment-workspace">'
                + '<nav class="case-assessment-side-nav" aria-label="Case Assessment sections"><div class="case-assessment-side-kicker">Case sections</div>' + nav + '</nav>'
                + '<div class="case-assessment-main-panel">' + panels + '</div></div>';

            bindControls();
            bindWorkspaceNav();
            activateWorkspaceSection(activeSectionKey, false);
        }

        async function load(force = false) {
            if (loading) return;
            if (!force && app.dataset.loaded === '1') return;
            loading = true;
            refreshButton.disabled = true;
            refreshButton.style.opacity = '.6';
            if (app.dataset.loaded !== '1') content.innerHTML = '<div class="case-assessment-loading">Loading the current case form…</div>';
            try {
                const response = await fetch('/lead/' + leadId + '/case-assessment', {
                    headers: { 'Accept':'application/json' },
                    credentials:'same-origin'
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load case assessment');
                app.dataset.loaded = '1';
                render(data.assessment);
            } catch (error) {
                content.innerHTML = '<div class="case-assessment-error">' + esc(error.message) + '</div>';
            } finally {
                loading = false;
                refreshButton.disabled = false;
                refreshButton.style.opacity = '1';
            }
        }

        function setSaving(control, saving, message = '') {
            const booleanGroup = control.closest('.case-assessment-boolean');
            const peers = booleanGroup ? Array.from(booleanGroup.querySelectorAll('button')) : [control];
            peers.forEach(peer => peer.disabled = saving);

            const field = control.closest('.case-assessment-form-field');
            const state = field ? field.querySelector('.case-assessment-save-state') : null;
            if (state) state.textContent = message || (saving ? 'Saving…' : '');
        }

        async function saveFact(control, value) {
            setSaving(control, true, 'Saving…');
            try {
                const response = await fetch('/lead/' + leadId + '/case-assessment/fact', {
                    method:'PATCH',
                    credentials:'same-origin',
                    headers:{
                        'Accept':'application/json',
                        'Content-Type':'application/json',
                        'X-CSRF-TOKEN':csrf
                    },
                    body:JSON.stringify({
                        scope:control.dataset.scope,
                        fact_key:control.dataset.factKey,
                        debt_id:control.dataset.debtId ? Number(control.dataset.debtId) : null,
                        value:value
                    })
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    const validation = data.errors ? Object.values(data.errors).flat().join(' ') : null;
                    throw new Error(validation || data.message || 'Unable to update fact');
                }

                render(data.assessment);
                window.dispatchEvent(new CustomEvent('jinx:case-assessment-updated', {
                    detail:{ fact_key:control.dataset.factKey, scope:control.dataset.scope }
                }));
            } catch (error) {
                setSaving(control, false, '');
                alert(error.message);
            }
        }

        function bindWorkspaceNav() {
            content.querySelectorAll('[data-assessment-nav]').forEach(button => {
                button.addEventListener('click', () => activateWorkspaceSection(button.dataset.assessmentNav, true));
            });
        }

        function addOptionalField(sectionKey, factKey) {
            if (!currentAssessmentData) return;
            const section = (currentAssessmentData.form_sections || []).find(item => item.key === sectionKey);
            if (!section) return;
            const addable = Array.isArray(section.addable_fields) ? section.addable_fields : [];
            const field = addable.find(item => item.fact_key === factKey);
            if (!field) return;

            section.fields = Array.isArray(section.fields) ? section.fields : [];
            section.fields.push(field);
            section.addable_fields = addable.filter(item => item.fact_key !== factKey);
            render(currentAssessmentData);

            window.setTimeout(() => {
                const target = content.querySelector('[data-form-field="' + CSS.escape(factKey) + '"]');
                if (!target) return;
                const input = target.querySelector('input,select');
                if (input) input.focus();
            }, 50);
        }

        function bindControls() {
            content.querySelectorAll('[data-assessment-boolean="1"]').forEach(button => {
                button.addEventListener('click', async () => {
                    await saveFact(button, button.dataset.value === 'true');
                });
            });

            content.querySelectorAll('[data-assessment-select="1"]').forEach(select => {
                select.addEventListener('change', async () => {
                    const value = select.value;
                    const control = select.dataset.control || 'select';
                    const field = select.closest('.case-assessment-form-field');
                    const custom = field ? field.querySelector('[data-assessment-custom-input="1"]') : null;

                    if (value === '__custom__') {
                        if (custom) {
                            custom.style.display = '';
                            window.setTimeout(() => {
                                custom.focus();
                                if (typeof custom.select === 'function') custom.select();
                            }, 0);
                        }
                        return;
                    }

                    if (custom) custom.style.display = 'none';
                    if (value === '') return;
                    await saveFact(select, value);
                });
            });

            content.querySelectorAll('[data-assessment-custom-input="1"]').forEach(input => {
                input.addEventListener('change', async () => {
                    let value = input.value.trim();
                    if (value === '') return;
                    await saveFact(input, value);
                });
                input.addEventListener('keydown', event => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        input.blur();
                    }
                });
            });

            content.querySelectorAll('[data-add-form-field]').forEach(select => {
                select.addEventListener('change', () => {
                    if (!select.value) return;
                    addOptionalField(select.dataset.addFormField, select.value);
                });
            });

            content.querySelectorAll('[data-assessment-input="1"]').forEach(input => {
                input.addEventListener('change', async () => {
                    let value = input.value;
                    if (value === '' && ['money','money_or_none','integer','percentage'].includes(input.dataset.dataType)) value = 0;
                    if (value === '' && input.dataset.dataType === 'text') return;
                    await saveFact(input, value);
                });
                input.addEventListener('keydown', event => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        input.blur();
                    }
                });
            });

            content.querySelectorAll('[data-focus-key]').forEach(button => {
                button.addEventListener('click', () => {
                    const selector = '[data-form-field="' + CSS.escape(button.dataset.focusKey) + '"]'
                        + (button.dataset.focusDebt ? '[data-form-debt="' + CSS.escape(button.dataset.focusDebt) + '"]' : '');
                    const target = content.querySelector(selector);
                    if (!target) return;

                    const panel = target.closest('[data-assessment-workspace-panel]');
                    if (panel) activateWorkspaceSection(panel.dataset.assessmentWorkspacePanel, false);

                    window.setTimeout(() => {
                        let parent = target.parentElement;
                        while (parent && parent !== content) {
                            if (parent.tagName === 'DETAILS') parent.open = true;
                            parent = parent.parentElement;
                        }
                        target.scrollIntoView({ behavior:'smooth', block:'center' });
                        const focusable = target.querySelector('input,button,select,textarea');
                        if (focusable) focusable.focus();
                    }, 80);
                });
            });
        }

        refreshButton.addEventListener('click', () => load(true));
        openFsButton.addEventListener('click', () => {
            const button = document.querySelector('[data-case-tab="financial-statement"]');
            if (button) button.click();
        });

        window.addEventListener('jinx:case-assessment:activate', () => load(true));
        window.addEventListener('jinx:case-updated', () => {
            if (document.querySelector('[data-case-panel="case-assessment"]')?.classList.contains('is-active')) load(true);
            else app.dataset.loaded = '0';
        });

        window.refreshCaseAssessment = () => load(true);

        if ((window.location.hash || '').replace('#','') === 'case-assessment') {
            window.setTimeout(() => load(true), 0);
        }
    })();
    </script>
</div>
