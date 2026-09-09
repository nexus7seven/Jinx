@php
    $assistantName = $lead->formattedName();
@endphp
<aside
    id="caseAssistant"
    class="case-assistant"
    aria-label="Case Assistant"
    data-lead-id="{{ $lead->id }}"
    data-lead-name="{{ $assistantName !== '' ? $assistantName : 'Unknown' }}"
    data-wip-status="{{ $lead->wip_status ?: '—' }}"
>
    <div class="case-assistant-header">
        <div>
            <h2 class="case-assistant-title">Case Assistant</h2>
            <div class="case-assistant-subtitle">Case-wide workspace — visual shell only</div>
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

        <section class="case-assistant-panel" aria-labelledby="caseAssistantMissingHeading">
            <h3 id="caseAssistantMissingHeading" class="case-assistant-panel-title">Missing information</h3>
            <div id="caseAssistantMissing" class="case-assistant-muted">No missing-information items are available yet.</div>
        </section>

        <section class="case-assistant-panel" aria-labelledby="caseAssistantNextHeading">
            <h3 id="caseAssistantNextHeading" class="case-assistant-panel-title">Next question / action</h3>
            <div id="caseAssistantNext" class="case-assistant-muted">No next action is available yet.</div>
        </section>

        <section class="case-assistant-history-wrap" aria-labelledby="caseAssistantHistoryHeading">
            <h3 id="caseAssistantHistoryHeading" class="case-assistant-panel-title">Conversation / history</h3>
            <div id="caseAssistantHistory" class="case-assistant-history">
                <div class="case-assistant-muted">No conversation yet. The Case Assistant will later control and assist with the whole case.</div>
            </div>
        </section>
    </div>

    <form class="case-assistant-composer" id="caseAssistantComposer" onsubmit="return false;">
        <label for="caseAssistantInput" class="visually-hidden">Message Case Assistant</label>
        <textarea
            id="caseAssistantInput"
            class="case-assistant-input"
            rows="3"
            placeholder="Ask or instruct the Case Assistant…"
            disabled
        ></textarea>
        <div class="case-assistant-composer-row">
            <span class="case-assistant-muted">AI backend not connected yet.</span>
            <button type="button" class="case-assistant-send" disabled>Send</button>
        </div>
    </form>
</aside>
