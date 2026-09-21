@php
    $assistantName = $lead->formattedName();
@endphp

<button
    type="button"
    id="caseAssistantLauncher"
    class="case-assistant-launcher"
    aria-label="Open Jinx Assistant"
    aria-controls="caseAssistant"
    aria-expanded="false"
>
    <span class="case-assistant-launcher-icon" aria-hidden="true">✦</span>
    <span>Jinx</span>
    <span id="caseAssistantLauncherDot" class="case-assistant-launcher-dot" aria-hidden="true"></span>
</button>

<aside
    id="caseAssistant"
    class="case-assistant case-assistant-drawer"
    aria-label="Jinx Assistant"
    aria-hidden="true"
    data-lead-id="{{ $lead->id }}"
    data-lead-name="{{ $assistantName !== '' ? $assistantName : 'Unknown' }}"
    data-wip-status="{{ $lead->wip_status ?: '—' }}"
    data-ip-voting-override-url="{{ route('lead.ip-voting.override', $lead) }}"
>
    <div class="case-assistant-header">
        <div style="min-width:0;">
            <h2 class="case-assistant-title">Jinx Assistant</h2>
            <div class="case-assistant-subtitle" id="caseAssistantSubtitle">Case-aware assistant</div>
        </div>
        <button type="button" id="caseAssistantClose" class="case-assistant-close" aria-label="Close Jinx Assistant">×</button>
    </div>

    <div class="case-assistant-body">
        <section id="caseAssistantCallbackBrief" class="case-assistant-callback-brief" style="display:none;">
            <div class="case-assistant-callback-kicker">Callback brief</div>
            <div id="caseAssistantCallbackWhen" class="case-assistant-callback-when"></div>
            <div id="caseAssistantCallbackNotes" class="case-assistant-callback-notes"></div>
        </section>

        <section id="caseAssistantIpVotingAlert" class="case-assistant-ip-voting-alert" style="display:none;">
            <div class="case-assistant-ip-voting-kicker">Creditor voting needs input</div>
            <div id="caseAssistantIpVotingTitle" class="case-assistant-ip-voting-title"></div>
            <div id="caseAssistantIpVotingReason" class="case-assistant-ip-voting-reason"></div>

            <form id="caseAssistantIpVotingForm" style="margin-top:10px;">
                <input type="hidden" id="caseAssistantIpVotingCreditorId">

                <label class="case-assistant-ip-voting-label" for="caseAssistantIpVotingStatus">Voting</label>
                <input
                    id="caseAssistantIpVotingStatus"
                    class="case-assistant-ip-voting-input"
                    list="caseAssistantIpVotingStatusOptions"
                    placeholder="e.g. Accept, Referral, Trial @ MOC, Reject"
                    autocomplete="off"
                >
                <datalist id="caseAssistantIpVotingStatusOptions">
                    <option value="Accept"></option>
                    <option value="Accept with conditions"></option>
                    <option value="Referral"></option>
                    <option value="Trial @ MOC"></option>
                    <option value="Reject"></option>
                    <option value="Non-voting"></option>
                    <option value="Represented"></option>
                </datalist>

                <label class="case-assistant-ip-voting-label" for="caseAssistantIpVotingHouse">Voting house</label>
                <input
                    id="caseAssistantIpVotingHouse"
                    class="case-assistant-ip-voting-input"
                    placeholder="e.g. TIX, WATCH, Evolve — leave blank if independent"
                    autocomplete="off"
                >

                <label class="case-assistant-ip-voting-label" for="caseAssistantIpVotingNotes">Conditions / notes</label>
                <textarea
                    id="caseAssistantIpVotingNotes"
                    class="case-assistant-ip-voting-input case-assistant-ip-voting-notes"
                    rows="3"
                    placeholder="Any conditions or notes to keep with this creditor + IP rule"
                ></textarea>

                <div id="caseAssistantIpVotingSource" class="case-assistant-ip-voting-source"></div>
                <div id="caseAssistantIpVotingError" class="case-assistant-ip-voting-error" style="display:none;"></div>

                <div class="case-assistant-ip-voting-actions">
                    <span id="caseAssistantIpVotingCount" class="case-assistant-muted"></span>
                    <button type="submit" id="caseAssistantIpVotingSave" class="case-assistant-ip-voting-save">Save rule</button>
                </div>
            </form>
        </section>

        <details class="case-assistant-panel case-assistant-context-panel">
            <summary class="case-assistant-context-summary">Current case context</summary>
            <dl class="case-assistant-context">
                <div><dt>Customer</dt><dd id="caseAssistantCustomer">{{ $assistantName !== '' ? $assistantName : 'Unknown' }}</dd></div>
                <div><dt>Lead ID</dt><dd>{{ $lead->id }}</dd></div>
                <div><dt>WIP status</dt><dd id="caseAssistantWipStatus">{{ $lead->wip_status ?: '—' }}</dd></div>
                <div><dt>Working in</dt><dd id="caseAssistantActiveTab">Client Details</dd></div>
            </dl>
        </details>

        <section class="case-assistant-history-wrap" aria-labelledby="caseAssistantHistoryHeading">
            <div class="case-assistant-history-head">
                <h3 id="caseAssistantHistoryHeading" class="case-assistant-panel-title" style="margin:0;">Conversation</h3>
                <button type="button" id="caseAssistantReset" class="case-assistant-new-chat">New chat</button>
            </div>
            <div id="caseAssistantHistory" class="case-assistant-history">
                <div class="case-assistant-muted">Open Jinx to load the conversation.</div>
            </div>
        </section>
    </div>

    <form class="case-assistant-composer" id="caseAssistantComposer">
        <label for="caseAssistantInput" class="visually-hidden">Message Jinx Assistant</label>
        <textarea id="caseAssistantInput" class="case-assistant-input" rows="3" placeholder="Tell Jinx about this case, update a fact, or ask for an assessment…"></textarea>
        <div class="case-assistant-composer-row">
            <span class="case-assistant-muted" id="caseAssistantStatus">Ready</span>
            <button type="submit" id="caseAssistantSend" class="case-assistant-send">Send</button>
        </div>
    </form>
</aside>

<style>
    .case-assistant-launcher {
        position:fixed;
        right:22px;
        bottom:22px;
        z-index:11950;
        display:inline-flex;
        align-items:center;
        gap:8px;
        min-height:48px;
        border:1px solid #2563eb;
        border-radius:999px;
        background:linear-gradient(180deg,#2563eb,#1d4ed8);
        color:#fff;
        padding:0 15px 0 12px;
        font:inherit;
        font-size:13px;
        font-weight:900;
        cursor:pointer;
        box-shadow:0 16px 36px rgba(2,6,23,.48);
    }
    .case-assistant-launcher:hover { border-color:#60a5fa; transform:translateY(-1px); }
    .case-assistant-launcher-icon { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,.14); font-size:14px; }
    .case-assistant-launcher-dot { width:8px; height:8px; border-radius:50%; background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,.14); }

    .case-assistant.case-assistant-drawer {
        position:fixed;
        top:0;
        right:0;
        bottom:0;
        z-index:12000;
        width:min(460px, calc(100vw - 28px));
        min-width:0;
        min-height:0;
        display:flex;
        flex-direction:column;
        background:#0f172a;
        border:0;
        border-left:1px solid #334155;
        border-radius:0;
        overflow:hidden;
        box-shadow:-18px 0 46px rgba(2,6,23,.56);
        transform:translateX(105%);
        transition:transform .18s ease;
        pointer-events:none;
    }
    .case-assistant.case-assistant-drawer.is-open {
        transform:translateX(0);
        pointer-events:auto;
    }
    .case-assistant-header {
        flex-shrink:0;
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:12px;
        padding:16px 16px 12px;
        border-bottom:1px solid #1e293b;
        background:linear-gradient(180deg,#172036 0%,#0f172a 100%);
    }
    .case-assistant-title { margin:0; font-size:20px; color:#f8fafc; }
    .case-assistant-subtitle { margin-top:4px; font-size:11px; color:#94a3b8; line-height:1.4; }
    .case-assistant-close { flex:0 0 auto; width:34px; height:34px; border:1px solid #334155; border-radius:8px; background:#111827; color:#cbd5e1; font-size:21px; line-height:1; cursor:pointer; }
    .case-assistant-close:hover { border-color:#60a5fa; color:#fff; }

    .case-assistant-body { flex:1; min-height:0; overflow:hidden; padding:12px 14px; display:flex; flex-direction:column; gap:10px; }
    .case-assistant-panel, .case-assistant-history-wrap { background:#020617; border:1px solid #1e293b; border-radius:10px; padding:10px 12px; }
    .case-assistant-context-panel { flex:0 0 auto; }
    .case-assistant-context-summary { cursor:pointer; color:#93c5fd; font-size:10px; font-weight:900; text-transform:uppercase; letter-spacing:.05em; list-style:none; }
    .case-assistant-context-summary::-webkit-details-marker { display:none; }
    .case-assistant-context { margin:10px 0 0; display:grid; grid-template-columns:1fr 1fr; gap:8px 12px; }
    .case-assistant-context dt { font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
    .case-assistant-context dd { margin:2px 0 0; font-size:12px; font-weight:700; color:#e2e8f0; overflow-wrap:anywhere; }

    .case-assistant-history-wrap { flex:1; min-height:180px; display:flex; flex-direction:column; overflow:hidden; }
    .case-assistant-history-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:8px; }
    .case-assistant-panel-title { margin:0 0 8px; font-size:10px; font-weight:800; color:#93c5fd; text-transform:uppercase; letter-spacing:.05em; }
    .case-assistant-new-chat { border:1px solid #334155; background:#111827; color:#94a3b8; border-radius:7px; padding:5px 8px; font-size:10px; cursor:pointer; }
    .case-assistant-history { flex:1; overflow:auto; min-height:120px; padding-right:2px; }
    .case-assistant-muted { font-size:11px; color:#94a3b8; line-height:1.45; }

    .case-assistant-composer { flex-shrink:0; padding:10px 12px 12px; border-top:1px solid #1e293b; background:#0b1220; }
    .case-assistant-input { width:100%; box-sizing:border-box; resize:none; min-height:82px; border-radius:10px; border:1px solid #334155; background:#020617; color:#f8fafc; padding:10px 12px; font-size:13px; font-family:inherit; }
    .case-assistant-input:focus { outline:none; border-color:#60a5fa; }
    .case-assistant-input:disabled { color:#64748b; }
    .case-assistant-composer-row { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:8px; }
    .case-assistant-send { min-height:36px; border:0; border-radius:8px; background:#2563eb; color:#fff; padding:8px 14px; font-weight:800; cursor:pointer; }

    .jinx-assistant-message { margin-bottom:9px; padding:9px 10px; border-radius:10px; line-height:1.45; font-size:12px; white-space:pre-wrap; }
    .jinx-assistant-message-user { margin-left:28px; background:#1d4ed8; color:#fff; }
    .jinx-assistant-message-assistant { margin-right:16px; background:#111827; border:1px solid #334155; color:#e2e8f0; }
    .jinx-assistant-message-label { display:block; margin-bottom:3px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; opacity:.65; }
    .jinx-assistant-memory-note { margin:8px 0; padding:8px 10px; border:1px solid #166534; background:#052e16; border-radius:8px; color:#bbf7d0; font-size:11px; }

    .case-assistant-callback-brief { margin-bottom:0; padding:11px; border:1px solid #1d4ed8; background:linear-gradient(180deg,rgba(30,64,175,.22),rgba(15,23,42,.8)); border-radius:10px; }
    .case-assistant-callback-kicker { text-transform:uppercase; color:#60a5fa; font-size:9px; font-weight:900; letter-spacing:.06em; }
    .case-assistant-callback-when { margin-top:5px; color:#dbeafe; font-size:13px; font-weight:800; }
    .case-assistant-callback-notes { margin-top:7px; color:#cbd5e1; font-size:11px; line-height:1.45; }

    .case-assistant-ip-voting-alert { flex:0 0 auto; padding:12px; border:1px solid #b45309; background:linear-gradient(180deg,rgba(120,53,15,.26),rgba(15,23,42,.92)); border-radius:10px; }
    .case-assistant-ip-voting-kicker { text-transform:uppercase; color:#fbbf24; font-size:9px; font-weight:900; letter-spacing:.06em; }
    .case-assistant-ip-voting-title { margin-top:5px; color:#fff7ed; font-size:14px; font-weight:900; }
    .case-assistant-ip-voting-reason { margin-top:5px; color:#fed7aa; font-size:11px; line-height:1.45; }
    .case-assistant-ip-voting-label { display:block; margin:8px 0 4px; color:#cbd5e1; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .case-assistant-ip-voting-input { width:100%; box-sizing:border-box; border:1px solid #475569; border-radius:8px; background:#020617; color:#f8fafc; padding:8px 9px; font:inherit; font-size:12px; }
    .case-assistant-ip-voting-input:focus { outline:none; border-color:#f59e0b; }
    .case-assistant-ip-voting-notes { min-height:64px; resize:vertical; }
    .case-assistant-ip-voting-source { margin-top:8px; white-space:pre-wrap; color:#94a3b8; font-size:10px; line-height:1.4; }
    .case-assistant-ip-voting-error { margin-top:8px; padding:7px 8px; border:1px solid #7f1d1d; border-radius:7px; background:#3f1d1d; color:#fecaca; font-size:10px; line-height:1.4; }
    .case-assistant-ip-voting-actions { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:10px; }
    .case-assistant-ip-voting-save { border:0; border-radius:8px; background:#d97706; color:#fff; padding:8px 11px; font-size:11px; font-weight:900; cursor:pointer; }
    .case-assistant-ip-voting-save:disabled { opacity:.55; cursor:not-allowed; }

    @media (max-width:720px) {
        .case-assistant-launcher { right:14px; bottom:14px; }
        .case-assistant.case-assistant-drawer { width:100vw; }
        .case-assistant-context { grid-template-columns:1fr; }
    }
</style>

<script>
(() => {
    const shell = document.getElementById('caseAssistant');
    const launcher = document.getElementById('caseAssistantLauncher');
    const launcherDot = document.getElementById('caseAssistantLauncherDot');
    const closeButton = document.getElementById('caseAssistantClose');
    if (!shell || !launcher || shell.dataset.initialised === '1') return;

    shell.dataset.initialised = '1';
    const leadId = shell.dataset.leadId;
    const history = document.getElementById('caseAssistantHistory');
    const composer = document.getElementById('caseAssistantComposer');
    const input = document.getElementById('caseAssistantInput');
    const sendButton = document.getElementById('caseAssistantSend');
    const status = document.getElementById('caseAssistantStatus');
    const subtitle = document.getElementById('caseAssistantSubtitle');
    const resetButton = document.getElementById('caseAssistantReset');
    const activeContext = document.getElementById('caseAssistantActiveTab');
    const ipVotingAlert = document.getElementById('caseAssistantIpVotingAlert');
    const ipVotingForm = document.getElementById('caseAssistantIpVotingForm');
    const ipVotingTitle = document.getElementById('caseAssistantIpVotingTitle');
    const ipVotingReason = document.getElementById('caseAssistantIpVotingReason');
    const ipVotingCreditorId = document.getElementById('caseAssistantIpVotingCreditorId');
    const ipVotingStatus = document.getElementById('caseAssistantIpVotingStatus');
    const ipVotingHouse = document.getElementById('caseAssistantIpVotingHouse');
    const ipVotingNotes = document.getElementById('caseAssistantIpVotingNotes');
    const ipVotingSource = document.getElementById('caseAssistantIpVotingSource');
    const ipVotingError = document.getElementById('caseAssistantIpVotingError');
    const ipVotingCount = document.getElementById('caseAssistantIpVotingCount');
    const ipVotingSave = document.getElementById('caseAssistantIpVotingSave');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const storageKey = 'jinxCaseAssistantOpen:' + leadId;
    let bootstrapped = false;
    let opening = false;
    let ipVotingQueue = [];
    let ipVotingMeta = { ip_key:null, ip_label:null };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'",'&#039;');

    function renderMessage(message) {
        const role = message.role === 'user' ? 'user' : 'assistant';
        const label = role === 'user' ? 'You' : 'Jinx';
        return '<div class="jinx-assistant-message jinx-assistant-message-' + role + '"><span class="jinx-assistant-message-label">'
            + label + '</span>' + escapeHtml(message.content) + '</div>';
    }

    function appendMessage(message) {
        if (history.querySelector('[data-empty="1"]')) history.innerHTML = '';
        history.insertAdjacentHTML('beforeend', renderMessage(message));
        history.scrollTop = history.scrollHeight;
    }

    function setBusy(busy, text = null) {
        input.disabled = busy;
        sendButton.disabled = busy;
        sendButton.style.opacity = busy ? '.6' : '1';
        if (text !== null) status.textContent = text;
    }

    function setAvailability(state) {
        if (!launcherDot) return;
        if (state === 'error') {
            launcherDot.style.background = '#ef4444';
            launcherDot.style.boxShadow = '0 0 0 3px rgba(239,68,68,.14)';
        } else if (state === 'busy') {
            launcherDot.style.background = '#f59e0b';
            launcherDot.style.boxShadow = '0 0 0 3px rgba(245,158,11,.14)';
        } else {
            launcherDot.style.background = '#22c55e';
            launcherDot.style.boxShadow = '0 0 0 3px rgba(34,197,94,.14)';
        }
    }

    async function bootstrap() {
        if (bootstrapped || opening) return;
        opening = true;
        status.textContent = 'Loading…';
        history.innerHTML = '<div class="case-assistant-muted">Loading previous conversation…</div>';
        try {
            const response = await fetch('/assistant/lead/' + leadId, {
                headers:{'Accept':'application/json'},
                credentials:'same-origin'
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load assistant');

            if (Array.isArray(data.messages) && data.messages.length) {
                history.innerHTML = data.messages.map(renderMessage).join('');
                history.scrollTop = history.scrollHeight;
            } else {
                history.innerHTML = '<div class="case-assistant-muted" data-empty="1">No conversation yet. Tell me about this case, update a fact, start the I&amp;E, or ask me to assess it.</div>';
            }

            subtitle.textContent = 'Case-aware assistant · ' + (data.knowledge_count || 0) + ' shared rules remembered';
            status.textContent = data.pending_knowledge ? 'Waiting for confirmation of a new rule' : 'Ready';
            const cb = data.active_callback;
            if (cb) {
                document.getElementById('caseAssistantCallbackBrief').style.display = 'block';
                document.getElementById('caseAssistantCallbackWhen').textContent = cb.callback_full_display + (cb.relative_due ? ' · ' + cb.relative_due : '');
                document.getElementById('caseAssistantCallbackNotes').textContent = cb.comments || 'No callback notes were recorded.';
            }
            bootstrapped = true;
            setAvailability('ready');
        } catch (error) {
            history.innerHTML = '<div class="case-assistant-muted">' + escapeHtml(error.message) + '</div>';
            status.textContent = 'Unavailable';
            setAvailability('error');
        } finally {
            opening = false;
        }
    }

    function setOpen(open) {
        shell.classList.toggle('is-open', open);
        shell.setAttribute('aria-hidden', open ? 'false' : 'true');
        launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
        sessionStorage.setItem(storageKey, open ? '1' : '0');

        if (open) {
            bootstrap();
            window.setTimeout(() => input.focus(), 180);
        }
    }

    function renderIpVotingAlert() {
        if (!ipVotingAlert || !ipVotingForm) return;

        if (!ipVotingQueue.length || !ipVotingMeta.ip_key) {
            ipVotingAlert.style.display = 'none';
            return;
        }

        const item = ipVotingQueue[0];
        const creditor = item.creditor_name || 'Unknown creditor';
        const ipLabel = ipVotingMeta.ip_label || ipVotingMeta.ip_key;

        ipVotingAlert.style.display = 'block';
        ipVotingTitle.textContent = creditor + ' · ' + ipLabel;
        ipVotingCreditorId.value = item.creditor_id || '';
        ipVotingStatus.value = item.status_raw || '';
        ipVotingHouse.value = item.voting_house || '';
        ipVotingNotes.value = item.notes || '';

        if (item.reason === 'not_in_ip_workbook' || item.reason === 'workbook_row_without_voting_data') {
            ipVotingReason.textContent = 'No usable ' + ipLabel + ' workbook voting entry was found. Tell Jinx what voting result and voting house should be used for this creditor.';
        } else if (item.reason === 'representative_route_conflict') {
            ipVotingReason.textContent = 'The workbook contains more than one possible voting-house route. Confirm the voting result and house for this creditor.';
        } else {
            ipVotingReason.textContent = 'The workbook entry cannot be interpreted safely without an operator decision. Confirm what Jinx should use.';
        }

        const sourceLines = [];
        if (item.status_raw) sourceLines.push('Workbook status: ' + item.status_raw);
        if (item.notes) sourceLines.push('Workbook notes: ' + item.notes);
        if (item.source_label) sourceLines.push('Source: ' + item.source_label);
        if (item.route_conflict_reason) sourceLines.push('House routing: ' + item.route_conflict_reason);
        ipVotingSource.textContent = sourceLines.join('\n');

        ipVotingCount.textContent = ipVotingQueue.length === 1
            ? '1 creditor needs input'
            : ipVotingQueue.length + ' creditors need input';

        const canSave = item.can_save_override !== false;
        ipVotingSave.disabled = !canSave;
        ipVotingStatus.disabled = !canSave;
        ipVotingHouse.disabled = !canSave;
        ipVotingNotes.disabled = !canSave;

        if (!canSave) {
            ipVotingError.style.display = 'block';
            ipVotingError.textContent = 'Match this debt to the real creditor first. Jinx will not save a reusable rule against “Could Not Match”.';
        } else {
            ipVotingError.style.display = 'none';
            ipVotingError.textContent = '';
        }
    }

    window.addEventListener('jinx:ip-voting-unresolved', event => {
        const detail = event.detail || {};
        ipVotingMeta = {
            ip_key: detail.ip_key || null,
            ip_label: detail.ip_label || null
        };
        ipVotingQueue = Array.isArray(detail.unresolved) ? detail.unresolved : [];
        renderIpVotingAlert();

        if (ipVotingQueue.length && ipVotingMeta.ip_key) {
            setAvailability('busy');
            setOpen(true);
        } else if (bootstrapped) {
            setAvailability('ready');
        }
    });

    if (ipVotingForm) {
        ipVotingForm.addEventListener('submit', async event => {
            event.preventDefault();
            if (!ipVotingQueue.length || ipVotingSave.disabled) return;

            const statusText = ipVotingStatus.value.trim();
            if (!statusText) {
                ipVotingError.style.display = 'block';
                ipVotingError.textContent = 'Enter the creditor voting result.';
                ipVotingStatus.focus();
                return;
            }

            ipVotingSave.disabled = true;
            ipVotingSave.textContent = 'Saving…';
            ipVotingError.style.display = 'none';

            try {
                const response = await fetch(shell.dataset.ipVotingOverrideUrl, {
                    method:'POST',
                    credentials:'same-origin',
                    headers:{
                        'Accept':'application/json',
                        'Content-Type':'application/json',
                        'X-CSRF-TOKEN':csrf
                    },
                    body:JSON.stringify({
                        creditor_id: ipVotingCreditorId.value,
                        status_text: statusText,
                        voting_house: ipVotingHouse.value.trim() || null,
                        condition_text: ipVotingNotes.value.trim() || null
                    })
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Could not save creditor voting rule');
                }

                ipVotingQueue = Array.isArray(data.unresolved) ? data.unresolved : [];
                ipVotingMeta = {
                    ip_key: data.ip_key || ipVotingMeta.ip_key,
                    ip_label: data.ip_label || ipVotingMeta.ip_label
                };
                renderIpVotingAlert();
                window.dispatchEvent(new CustomEvent('jinx:ip-voting-refresh'));

                status.textContent = ipVotingQueue.length
                    ? 'Voting rule saved · ' + ipVotingQueue.length + ' still need input'
                    : 'Voting rules complete';
                setAvailability(ipVotingQueue.length ? 'busy' : 'ready');
            } catch (error) {
                ipVotingError.style.display = 'block';
                ipVotingError.textContent = error.message;
            } finally {
                const nextItem = ipVotingQueue[0] || null;
                ipVotingSave.disabled = !nextItem || nextItem.can_save_override === false;
                ipVotingSave.textContent = 'Save rule';
            }
        });
    }

        launcher.addEventListener('click', () => setOpen(!shell.classList.contains('is-open')));
    closeButton.addEventListener('click', () => setOpen(false));

    composer.addEventListener('submit', async(event) => {
        event.preventDefault();
        const message = input.value.trim();
        if (!message || sendButton.disabled) return;

        appendMessage({role:'user',content:message});
        input.value = '';
        setBusy(true, 'Jinx is thinking…');
        setAvailability('busy');

        try {
            const response = await fetch('/assistant/lead/' + leadId + '/message', {
                method:'POST',
                credentials:'same-origin',
                headers:{
                    'Accept':'application/json',
                    'Content-Type':'application/json',
                    'X-CSRF-TOKEN':csrf
                },
                body:JSON.stringify({message})
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Assistant error');

            appendMessage(data.message);
            if (data.knowledge_saved) {
                history.insertAdjacentHTML('beforeend',
                    '<div class="jinx-assistant-memory-note">✓ Remembered for future cases: '
                    + escapeHtml(data.knowledge_saved.title) + '</div>');
                history.scrollTop = history.scrollHeight;
            }

            status.textContent = data.knowledge_proposed
                ? 'New rule proposed — confirm or correct it in chat'
                : (data.knowledge_saved ? 'Shared knowledge updated' : 'Ready');

            window.dispatchEvent(new CustomEvent('jinx:case-updated', { detail:data }));

            if (data.debt_import_complete) {
                status.textContent = 'Refreshing debts…';
                sessionStorage.setItem(storageKey, '1');
                window.location.hash = 'debts';
                window.setTimeout(() => window.location.reload(), 250);
                return;
            }

            if (data.financial_statement_changed || (Array.isArray(data.synced_fields) && data.synced_fields.length)) {
                status.textContent = 'Updating case…';
                sessionStorage.setItem(storageKey, '1');
                window.setTimeout(() => window.location.reload(), 250);
                return;
            }

            setAvailability('ready');
        } catch(error) {
            appendMessage({role:'assistant',content:"I couldn't respond: " + error.message});
            status.textContent = 'Error';
            setAvailability('error');
        } finally {
            setBusy(false);
            input.focus();
        }
    });

    input.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            composer.requestSubmit();
        }
    });

    resetButton.addEventListener('click', async() => {
        if (!confirm('Start a new Jinx Assistant conversation for this case? Shared learned rules will not be deleted.')) return;
        setBusy(true, 'Starting new chat…');
        try {
            const response = await fetch('/assistant/lead/' + leadId + '/reset', {
                method:'POST',
                credentials:'same-origin',
                headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to reset chat');
            history.innerHTML = '<div class="case-assistant-muted" data-empty="1">New conversation started. Shared Jinx knowledge is still available.</div>';
            status.textContent = 'Ready';
        } catch(error) {
            status.textContent = error.message;
        } finally {
            setBusy(false);
            input.focus();
        }
    });

    window.addEventListener('jinx:assessment-section', event => {
        const label = event.detail?.label || 'Case Assessment';
        if (activeContext) activeContext.textContent = 'Case Assessment · ' + label;
        input.placeholder = 'Ask Jinx about ' + label + ', or tell it what you know…';
    });

    document.addEventListener('keydown', event => {
        if (!event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (String(event.key || '').toLowerCase() !== 'j') return;
        event.preventDefault();
        setOpen(!shell.classList.contains('is-open'));
    });

    if (sessionStorage.getItem(storageKey) === '1') {
        window.setTimeout(() => setOpen(true), 0);
    }
})();
</script>
