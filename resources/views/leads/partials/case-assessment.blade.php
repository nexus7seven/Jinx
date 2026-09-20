<div id="caseAssessmentApp" data-lead-id="{{ $lead->id }}" data-loaded="0">
    <style>
        .case-assessment-card { background:#111827; border:1px solid #374151; border-radius:14px; padding:20px; box-sizing:border-box; }
        .case-assessment-header { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
        .case-assessment-title { margin:0; font-size:24px; color:#f8fafc; }
        .case-assessment-subtitle { margin-top:5px; color:#94a3b8; font-size:13px; line-height:1.45; max-width:760px; }
        .case-assessment-refresh { border:1px solid #334155; background:#172036; color:#dbeafe; border-radius:8px; padding:9px 12px; font-weight:700; cursor:pointer; }
        .case-assessment-refresh:hover { border-color:#60a5fa; }
        .case-assessment-summary { display:grid; grid-template-columns:repeat(5,minmax(120px,1fr)); gap:10px; margin-bottom:14px; }
        .case-assessment-stat { background:#020617; border:1px solid #1e293b; border-radius:10px; padding:12px; min-width:0; }
        .case-assessment-stat-label { color:#94a3b8; font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:800; }
        .case-assessment-stat-value { margin-top:5px; color:#f8fafc; font-size:20px; font-weight:900; }
        .case-assessment-readiness { margin-bottom:14px; padding:12px 14px; border:1px solid #334155; border-radius:10px; background:#0f172a; }
        .case-assessment-readiness strong { color:#f8fafc; }
        .case-assessment-readiness-note { margin-top:4px; color:#cbd5e1; font-size:13px; line-height:1.45; }
        .case-assessment-section { margin-top:18px; }
        .case-assessment-section-title { margin:0 0 9px; font-size:16px; color:#e2e8f0; }
        .case-assessment-table-wrap { overflow:auto; border:1px solid #1e293b; border-radius:10px; }
        .case-assessment-table { width:100%; border-collapse:collapse; min-width:820px; background:#020617; }
        .case-assessment-table th { text-align:left; padding:9px 10px; color:#94a3b8; font-size:10px; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid #1e293b; background:#0f172a; position:sticky; top:0; z-index:1; }
        .case-assessment-table td { padding:10px; border-bottom:1px solid rgba(30,41,59,.75); color:#e2e8f0; font-size:12px; vertical-align:top; line-height:1.45; }
        .case-assessment-table tr:last-child td { border-bottom:0; }
        .case-assessment-key { margin-top:3px; color:#64748b; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:10px; }
        .case-assessment-source-detail { margin-top:3px; color:#64748b; font-size:10px; }
        .case-assessment-edit { border:1px solid #334155; background:#111827; color:#93c5fd; border-radius:7px; padding:5px 8px; font-size:11px; font-weight:700; cursor:pointer; white-space:nowrap; }
        .case-assessment-edit:hover { border-color:#60a5fa; }
        .case-assessment-boolean { display:inline-flex; gap:4px; padding:2px; border:1px solid #334155; border-radius:8px; background:#0f172a; }
        .case-assessment-boolean-btn { min-width:42px; border:0; border-radius:6px; padding:5px 8px; background:transparent; color:#94a3b8; font-size:11px; font-weight:900; cursor:pointer; }
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
        .case-assessment-loading, .case-assessment-error { border:1px solid #334155; background:#020617; border-radius:10px; padding:16px; color:#94a3b8; }
        .case-assessment-error { border-color:#7f1d1d; color:#fecaca; background:#3f1d1d; }
        .case-assessment-latest { margin-top:10px; color:#cbd5e1; font-size:12px; }
        @media (max-width: 1200px) {
            .case-assessment-summary { grid-template-columns:repeat(2,minmax(120px,1fr)); }
            .case-assessment-routes { grid-template-columns:repeat(2,minmax(180px,1fr)); }
        }
        @media (max-width: 720px) {
            .case-assessment-summary, .case-assessment-routes { grid-template-columns:1fr; }
        }
    </style>

    <div class="case-assessment-card">
        <div class="case-assessment-header">
            <div>
                <h2 class="case-assessment-title">Case Assessment</h2>
                <div class="case-assessment-subtitle">Every active reasoning data point, where it came from, whether it is currently applicable, and how Jinx is using it in this case.</div>
            </div>
            <button type="button" id="caseAssessmentRefresh" class="case-assessment-refresh">Refresh assessment</button>
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
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        let loading = false;

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

        function factTable(rows, scope, debtId = null) {
            if (!Array.isArray(rows) || !rows.length) {
                return '<div class="case-assessment-loading">No active data points in this section.</div>';
            }

            const body = rows.map(row => {
                const detailBits = [];
                if (row.source_detail) detailBits.push(esc(row.source_detail));
                if (row.recorded_at) detailBits.push('Recorded ' + esc(row.recorded_at));

                let edit = '';
                if (row.editable) {
                    const encoded = encodeURIComponent(JSON.stringify(row.value));
                    const common = ' data-scope="' + esc(scope) + '"'
                        + ' data-fact-key="' + esc(row.fact_key) + '"'
                        + ' data-data-type="' + esc(row.data_type) + '"'
                        + ' data-current="' + esc(encoded) + '"'
                        + (debtId ? ' data-debt-id="' + esc(debtId) + '"' : '');

                    if (row.data_type === 'boolean') {
                        edit = '<div class="case-assessment-boolean" role="group" aria-label="' + esc(row.label) + '">'
                            + '<button type="button" class="case-assessment-boolean-btn' + (row.value === true ? ' is-selected' : '') + '" data-assessment-boolean="1" data-value="true"' + common + '>Yes</button>'
                            + '<button type="button" class="case-assessment-boolean-btn' + (row.value === false ? ' is-selected' : '') + '" data-assessment-boolean="1" data-value="false"' + common + '>No</button>'
                            + '</div>';
                    } else {
                        edit = '<button type="button" class="case-assessment-edit" data-assessment-edit="1"' + common + '>Edit</button>';
                    }
                }

                return '<tr>'
                    + '<td><strong>' + esc(row.label) + '</strong><div class="case-assessment-key">' + esc(row.fact_key) + '</div></td>'
                    + '<td>' + esc(row.display_value) + '</td>'
                    + '<td>' + esc(row.source || '—') + (detailBits.length ? '<div class="case-assessment-source-detail">' + detailBits.join(' · ') + '</div>' : '') + '</td>'
                    + '<td>' + esc(row.assessment || '—') + '</td>'
                    + '<td>' + badge(row.status, row.status_label) + '</td>'
                    + '<td>' + edit + '</td>'
                    + '</tr>';
            }).join('');

            return '<div class="case-assessment-table-wrap"><table class="case-assessment-table">'
                + '<thead><tr><th>Data point</th><th>Current value</th><th>Source</th><th>How assessed</th><th>Status</th><th></th></tr></thead>'
                + '<tbody>' + body + '</tbody></table></div>';
        }

        function renderSummary(data) {
            const s = data.summary || {};
            const readinessLabel = s.ready ? 'Ready for reasoning' : (s.readiness_state || 'Unknown').replaceAll('_',' ');
            let note = s.ready
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
                + stat('N/A', s.not_applicable ?? 0)
                + stat('Not collected', s.not_recorded ?? 0)
                + '</div>'
                + '<div class="case-assessment-readiness"><strong>' + badge(s.ready ? 'ready_for_reasoning' : 'missing', readinessLabel) + '</strong>'
                + '<div class="case-assessment-readiness-note">' + note + '</div>' + latest + '</div>';
        }

        function stat(label, value) {
            return '<div class="case-assessment-stat"><div class="case-assessment-stat-label">' + esc(label) + '</div><div class="case-assessment-stat-value">' + esc(value) + '</div></div>';
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

        function renderGroups(groups) {
            return (groups || []).map(group =>
                '<section class="case-assessment-section"><h3 class="case-assessment-section-title">' + esc(group.label) + '</h3>'
                + factTable(group.facts || [], 'case') + '</section>'
            ).join('');
        }

        function renderDebts(debts) {
            if (!Array.isArray(debts) || !debts.length) {
                return '<section class="case-assessment-section"><h3 class="case-assessment-section-title">Debt reasoning</h3><div class="case-assessment-loading">No debts are recorded.</div></section>';
            }

            const rows = debts.map(debt => {
                const conflict = debt.voting_conflict
                    ? '<div class="case-assessment-source-detail" style="color:#fca5a5;">' + esc(debt.voting_conflict_reason || 'Voting mapping conflict') + '</div>'
                    : '';
                return '<details class="case-assessment-debt">'
                    + '<summary><div><div class="case-assessment-debt-title">' + esc(debt.creditor) + ' · ' + esc(debt.balance_display) + '</div>'
                    + '<div class="case-assessment-debt-meta">Voting: ' + esc(debt.voting_house) + ' · ' + esc(debt.voting_percent) + '% of known debt</div>' + conflict + '</div>'
                    + '<span style="color:#64748b;font-size:12px;">View data points</span></summary>'
                    + '<div class="case-assessment-debt-body">' + factTable(debt.facts || [], 'debt', debt.debt_id) + '</div>'
                    + '</details>';
            }).join('');

            return '<section class="case-assessment-section"><h3 class="case-assessment-section-title">Debt reasoning</h3>' + rows + '</section>';
        }

        function render(data) {
            content.innerHTML = renderSummary(data)
                + renderRoutes(data.routes || [])
                + renderGroups(data.groups || [])
                + renderDebts(data.debts || []);
            bindEdits();
        }

        async function load(force = false) {
            if (loading) return;
            if (!force && app.dataset.loaded === '1') return;
            loading = true;
            refreshButton.disabled = true;
            refreshButton.style.opacity = '.6';
            if (app.dataset.loaded !== '1') content.innerHTML = '<div class="case-assessment-loading">Loading the current reasoning state…</div>';
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

        function promptValue(button) {
            const type = button.dataset.dataType || 'text';
            let current = null;
            try { current = JSON.parse(decodeURIComponent(button.dataset.current || encodeURIComponent('null'))); } catch (_) {}

            let help = 'Enter the new value';
            let defaultValue = current === null ? '' : String(current);
            if (type === 'boolean') {
                help = 'Enter yes or no';
                defaultValue = current === true ? 'yes' : (current === false ? 'no' : '');
            } else if (type === 'money' || type === 'money_or_none') {
                help = type === 'money_or_none' ? 'Enter the amount, or "none"' : 'Enter the amount';
            } else if (type === 'percentage') {
                help = 'Enter the percentage';
            } else if (type === 'date') {
                help = 'Enter the date';
            }
            return window.prompt(help + ' for ' + button.dataset.factKey + ':', defaultValue);
        }

        async function saveFact(button, value) {
            const group = button.closest('.case-assessment-boolean');
            const peers = group ? Array.from(group.querySelectorAll('button')) : [button];
            peers.forEach(peer => peer.disabled = true);

            const original = button.textContent;
            if (!group) button.textContent = 'Saving…';

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
                        scope:button.dataset.scope,
                        fact_key:button.dataset.factKey,
                        debt_id:button.dataset.debtId ? Number(button.dataset.debtId) : null,
                        value:value
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    const validation = data.errors ? Object.values(data.errors).flat().join(' ') : null;
                    throw new Error(validation || data.message || 'Unable to update fact');
                }
                render(data.assessment);
                window.dispatchEvent(new CustomEvent('jinx:case-assessment-updated', { detail:{ fact_key:button.dataset.factKey } }));
            } catch (error) {
                alert(error.message);
                peers.forEach(peer => peer.disabled = false);
                if (!group) button.textContent = original;
            }
        }

        function bindEdits() {
            content.querySelectorAll('[data-assessment-boolean="1"]').forEach(button => {
                button.addEventListener('click', async () => {
                    const value = button.dataset.value === 'true';
                    await saveFact(button, value);
                });
            });

            content.querySelectorAll('[data-assessment-edit="1"]').forEach(button => {
                button.addEventListener('click', async () => {
                    const value = promptValue(button);
                    if (value === null) return;
                    await saveFact(button, value);
                });
            });
        }

        refreshButton.addEventListener('click', () => load(true));
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
