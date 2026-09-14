@php
    $assistantName = $lead->formattedName();
@endphp
<aside
    id="caseAssistant"
    class="case-assistant"
    aria-label="Jinx Assistant"
    data-lead-id="{{ $lead->id }}"
    data-lead-name="{{ $assistantName !== '' ? $assistantName : 'Unknown' }}"
    data-wip-status="{{ $lead->wip_status ?: '—' }}"
>
    <div class="case-assistant-header">
        <div>
            <h2 class="case-assistant-title">Jinx Assistant</h2>
            <div class="case-assistant-subtitle" id="caseAssistantSubtitle">Loading case memory…</div>
        </div>
    </div>

    <div class="case-assistant-body">
        <section class="case-assistant-panel" aria-labelledby="caseAssistantContextHeading">
            <h3 id="caseAssistantContextHeading" class="case-assistant-panel-title">Current case context</h3>
            <dl class="case-assistant-context">
                <div>
                    <dt>Customer</dt>
                    <dd id="caseAssistantCustomer">{{ $assistantName !== '' ? $assistantName : 'Unknown' }}</dd>
                </div>
                <div>
                    <dt>Lead ID</dt>
                    <dd>{{ $lead->id }}</dd>
                </div>
                <div>
                    <dt>WIP status</dt>
                    <dd id="caseAssistantWipStatus">{{ $lead->wip_status ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Working in</dt>
                    <dd id="caseAssistantActiveTab">Client Details</dd>
                </div>
            </dl>
        </section>

        <section class="case-assistant-history-wrap" aria-labelledby="caseAssistantHistoryHeading">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:8px;">
                <h3 id="caseAssistantHistoryHeading" class="case-assistant-panel-title" style="margin:0;">Conversation</h3>
                <button type="button" id="caseAssistantReset" style="border:1px solid #334155; background:#111827; color:#94a3b8; border-radius:7px; padding:5px 8px; font-size:11px; cursor:pointer;">New chat</button>
            </div>
            <div id="caseAssistantHistory" class="case-assistant-history">
                <div class="case-assistant-muted">Loading previous conversation…</div>
            </div>
        </section>
    </div>

    <form class="case-assistant-composer" id="caseAssistantComposer">
        <label for="caseAssistantInput" class="visually-hidden">Message Jinx Assistant</label>
        <textarea
            id="caseAssistantInput"
            class="case-assistant-input"
            rows="3"
            placeholder="Ask about this case, start an I&E, or teach Jinx a new rule…"
        ></textarea>
        <div class="case-assistant-composer-row">
            <span class="case-assistant-muted" id="caseAssistantStatus">Connecting…</span>
            <button type="submit" id="caseAssistantSend" class="case-assistant-send" style="cursor:pointer; background:#2563eb; color:#fff;">Send</button>
        </div>
    </form>
</aside>

<style>
    .jinx-assistant-message { margin-bottom: 9px; padding: 9px 10px; border-radius: 10px; line-height: 1.45; font-size: 13px; white-space: pre-wrap; }
    .jinx-assistant-message-user { margin-left: 20px; background: #1d4ed8; color: #fff; }
    .jinx-assistant-message-assistant { margin-right: 12px; background: #111827; border: 1px solid #334155; color: #e2e8f0; }
    .jinx-assistant-message-label { display:block; margin-bottom:3px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; opacity:.65; }
    .jinx-assistant-memory-note { margin:8px 0; padding:8px 10px; border:1px solid #166534; background:#052e16; border-radius:8px; color:#bbf7d0; font-size:12px; }
</style>

<script>
(() => {
    const shell = document.getElementById('caseAssistant');
    if (!shell || shell.dataset.initialised === '1') return;
    shell.dataset.initialised = '1';

    const leadId = shell.dataset.leadId;
    const history = document.getElementById('caseAssistantHistory');
    const composer = document.getElementById('caseAssistantComposer');
    const input = document.getElementById('caseAssistantInput');
    const sendButton = document.getElementById('caseAssistantSend');
    const status = document.getElementById('caseAssistantStatus');
    const subtitle = document.getElementById('caseAssistantSubtitle');
    const resetButton = document.getElementById('caseAssistantReset');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    function renderMessage(message) {
        const role = message.role === 'user' ? 'user' : 'assistant';
        const label = role === 'user' ? 'You' : 'Jinx';
        return `<div class="jinx-assistant-message jinx-assistant-message-${role}"><span class="jinx-assistant-message-label">${label}</span>${escapeHtml(message.content)}</div>`;
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

    async function bootstrap() {
        try {
            const response = await fetch(`/assistant/lead/${leadId}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to load assistant');

            if (Array.isArray(data.messages) && data.messages.length) {
                history.innerHTML = data.messages.map(renderMessage).join('');
                history.scrollTop = history.scrollHeight;
            } else {
                history.innerHTML = '<div class="case-assistant-muted" data-empty="1">No conversation yet. Ask me anything about this case, start an I&amp;E, or tell me about a changed rule.</div>';
            }

            subtitle.textContent = `Case-aware assistant · ${data.knowledge_count || 0} shared rules remembered`;
            status.textContent = data.pending_knowledge ? 'Waiting for confirmation of a new rule' : 'Ready';
        } catch (error) {
            history.innerHTML = `<div class="case-assistant-muted">${escapeHtml(error.message)}</div>`;
            status.textContent = 'Unavailable';
        }
    }

    composer.addEventListener('submit', async (event) => {
        event.preventDefault();
        const message = input.value.trim();
        if (!message || sendButton.disabled) return;

        appendMessage({ role: 'user', content: message });
        input.value = '';
        setBusy(true, 'Jinx is thinking…');

        try {
            const response = await fetch(`/assistant/lead/${leadId}/message`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ message }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Assistant error');

            appendMessage(data.message);

            if (data.knowledge_saved) {
                history.insertAdjacentHTML('beforeend', `<div class="jinx-assistant-memory-note">✓ Remembered for future cases: ${escapeHtml(data.knowledge_saved.title)}</div>`);
                history.scrollTop = history.scrollHeight;
            }

            if (data.knowledge_proposed) {
                status.textContent = 'New rule proposed — confirm or correct it in chat';
            } else {
                status.textContent = data.knowledge_saved ? 'Shared knowledge updated' : 'Ready';
            }
        } catch (error) {
            appendMessage({ role: 'assistant', content: `I couldn't respond: ${error.message}` });
            status.textContent = 'Error';
        } finally {
            setBusy(false);
            input.focus();
        }
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            composer.requestSubmit();
        }
    });

    resetButton.addEventListener('click', async () => {
        if (!confirm('Start a new Jinx Assistant conversation for this case? Shared learned rules will not be deleted.')) return;

        setBusy(true, 'Starting new chat…');
        try {
            const response = await fetch(`/assistant/lead/${leadId}/reset`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to reset chat');
            history.innerHTML = '<div class="case-assistant-muted" data-empty="1">New conversation started. Shared Jinx knowledge is still available.</div>';
            status.textContent = 'Ready';
        } catch (error) {
            status.textContent = error.message;
        } finally {
            setBusy(false);
            input.focus();
        }
    });

    bootstrap();
})();
</script>
