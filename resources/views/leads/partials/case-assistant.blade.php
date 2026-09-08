<aside class="lead-case-assistant" aria-label="Case Assistant">
    <header class="ca-header">
        <div>
            <div class="ca-kicker">Workspace</div>
            <h2 class="ca-title">Case Assistant</h2>
        </div>
        <span class="ca-status-pill">Shell only</span>
    </header>

    <div class="ca-context">
        <section class="ca-block">
            <h3 class="ca-block-title">Case context</h3>
            <p class="ca-block-body">
                {{ $leadDisplayName !== '' ? $leadDisplayName : 'Unknown customer' }}
                · Lead {{ $lead->id }}
                · {{ $lead->wip_status ?: 'No WIP status' }}
            </p>
        </section>
        <section class="ca-block">
            <h3 class="ca-block-title">Missing information</h3>
            <p class="ca-block-body ca-muted">Not connected yet. Later this will list gaps in the case pack.</p>
        </section>
        <section class="ca-block">
            <h3 class="ca-block-title">Next question / action</h3>
            <p class="ca-block-body ca-muted">Not connected yet. Later this will drive the next case action.</p>
        </section>
    </div>

    <div class="ca-history" id="caseAssistantHistory">
        <div class="ca-msg ca-msg-system">
            Case Assistant is a visual workspace only. Conversation and case control are not connected yet.
        </div>
    </div>

    <form class="ca-composer" id="caseAssistantComposer" onsubmit="return false;">
        <label class="ca-composer-label" for="caseAssistantInput">Message</label>
        <div class="ca-composer-row">
            <textarea
                id="caseAssistantInput"
                class="ca-input"
                rows="3"
                placeholder="Ask the Case Assistant… (not connected yet)"
                disabled
            ></textarea>
            <button type="button" class="ca-send" disabled>Send</button>
        </div>
        <div class="ca-composer-hint">AI control of the case will land in a later phase.</div>
    </form>
</aside>
