<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Check v2 - Lead {{ $lead->id }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        @keyframes ccv2-spin {
            to { transform: rotate(360deg); }
        }
        .ccv2-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(134, 239, 172, 0.25);
            border-top-color: #86efac;
            border-radius: 50%;
            animation: ccv2-spin 0.7s linear infinite;
            vertical-align: middle;
        }
    </style>
</head>
<body style="margin:0; font-family:Arial,sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">

@php
    $creditCheckUrl = 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou';
    $runUrl = route('leads.credit-check-v2.run', $lead);
@endphp

<div style="max-width:920px; margin:0 auto; padding:16px; box-sizing:border-box;">

    <div id="ccv2ResultBanner" role="status" aria-live="polite" style="display:none; margin-bottom:16px; border-radius:12px; padding:14px 16px; font-size:14px; line-height:1.5; border:1px solid transparent;"></div>

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:18px; box-sizing:border-box; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
                <h1 style="margin:0; font-size:24px; line-height:1.2;">Credit Check v2</h1>
                <div style="margin-top:6px; font-size:13px; color:#9ca3af;">
                    Lead {{ $lead->id }} — Playwright (stealth Chromium), temp-mail.io verification link, optional security Q&amp;A handoff, PDF save
                </div>
            </div>

            <div id="runnerStatus" style="font-size:12px; color:#9ca3af;">
                Ready
            </div>
        </div>

        <div style="margin-top:14px; background:#020617; border:1px solid #374151; border-radius:10px; padding:12px 14px;">
            <div style="font-size:12px; color:#9ca3af; margin-bottom:8px;">Target URL</div>
            <div style="font-size:13px; line-height:1.5; word-break:break-word; color:#e5e7eb;">
                {{ $creditCheckUrl }}
            </div>
        </div>

        <div style="margin-top:14px; font-size:12px; color:#9ca3af; line-height:1.6;">
            Requires Node.js and <code style="color:#e5e7eb;">npx playwright install chromium</code>.
            Env: <code style="color:#e5e7eb;">CREDIT_CHECK_V2_PROCESS_TIMEOUT</code> (seconds, default 7200),
            <code style="color:#e5e7eb;">CREDIT_CHECK_V2_ANSWER_TIMEOUT_MS</code> (wait for <code style="color:#e5e7eb;">answers.json</code>),
            <code style="color:#e5e7eb;">PLAYWRIGHT_HEADLESS=0</code> to debug,
            <code style="color:#e5e7eb;">CREDIT_CHECK_V2_ALLOW_PRINT_PDF=1</code> for print-PDF fallback if download never fires.
            Stdout lines: <code style="color:#e5e7eb;">CREDIT_CHECK_V2_JSON:{...}</code>.
            If the run pauses on security questions, the HTTP stream blocks until <code style="color:#e5e7eb;">answers.json</code> is supplied (POST to answers URL or write the file). Use another tab or curl to submit answers while this page waits; the modal shows the questions parsed from the stream.
        </div>

        <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <button
                type="button"
                id="runCreditCheckV2Btn"
                data-url="{{ $runUrl }}"
                data-stream-url="{{ $runUrl }}?stream=1"
                style="background:#5b21b6; color:#ffffff; border:0; border-radius:8px; padding:12px 18px; font-size:14px; cursor:pointer;"
            >
                Request statutory credit report
            </button>
            <button
                type="button"
                id="cancelCreditCheckV2Btn"
                disabled
                style="background:#374151; color:#9ca3af; border:1px solid #4b5563; border-radius:8px; padding:12px 18px; font-size:14px; cursor:not-allowed;"
            >
                Cancel
            </button>
        </div>
    </div>

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:16px; margin-bottom:16px;">
        <div style="font-size:14px; font-weight:700; margin-bottom:8px;">Session / answers</div>
        <div id="sessionMeta" style="font-size:12px; color:#9ca3af; line-height:1.6;">
            Run once to obtain <code style="color:#e5e7eb;">sessionId</code> and answers endpoint (headers arrive as soon as the stream starts).
        </div>
    </div>

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:16px; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <div style="font-size:14px; font-weight:700;">Live log</div>
                <div id="streamSpinnerWrap" style="display:none; align-items:center; gap:8px;" aria-hidden="true">
                    <span class="ccv2-spinner"></span>
                    <span style="font-size:13px; color:#86efac; font-weight:600;">Running…</span>
                </div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" id="clearLiveLogBtn" style="background:#1f2937; color:#e5e7eb; border:1px solid #374151; border-radius:8px; padding:8px 12px; font-size:12px; cursor:pointer;">
                    Clear log
                </button>
            </div>
        </div>
        <div style="font-size:11px; color:#6b7280; margin-bottom:8px; line-height:1.4;">
            Streamed <code style="color:#9ca3af;">stdout</code>/<code style="color:#9ca3af;">stderr</code> from the Playwright script (terminal style). Lines prefixed with <code style="color:#86efac;">[credit-check-v2]</code> include progress and machine-readable JSON events.
        </div>
        <pre id="creditCheckLiveLog" style="margin:0; padding:12px 14px; height:280px; min-height:280px; max-height:280px; overflow-x:hidden; overflow-y:auto; box-sizing:border-box; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; font-size:12px; line-height:1.45; white-space:pre-wrap; word-break:break-word; background:#030303; color:#86efac; border:1px solid #14532d; border-radius:10px; box-shadow:inset 0 0 0 1px #022c22;">—</pre>
    </div>

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:16px;">
        <div style="font-size:14px; font-weight:700; margin-bottom:8px;">Parsed result</div>
        <pre id="runnerOutput" style="margin:0; font-size:12px; line-height:1.5; white-space:pre-wrap; word-break:break-word; color:#d1d5db; max-height:280px; overflow:auto;">—</pre>
    </div>

</div>

{{-- Security questions modal (shown when stream includes security_questions JSON lines) --}}
<div id="securityQuestionsModal" style="display:none; position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.65); align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
    <div style="background:#111827; border:1px solid #4b5563; border-radius:14px; max-width:520px; width:100%; max-height:85vh; overflow:auto; padding:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);">
        <h2 style="margin:0 0 12px; font-size:18px; color:#f9fafb;">Security questions</h2>
        <p style="margin:0 0 14px; font-size:13px; color:#9ca3af; line-height:1.5;">
            KBA radios: POST <code style="color:#e5e7eb;">answers</code> with each <code style="color:#e5e7eb;">value</code> matching the visible option label text. Use <code style="color:#e5e7eb;">id</code> (hidden Questions Id from the page) or <code style="color:#e5e7eb;">index</code> (0-based question order). Wrong answers get a second round — see <code style="color:#e5e7eb;">security_questions_events</code> in the parsed result if both emitted.
        </p>
        <div id="securityQuestionsList" style="font-size:13px; color:#e5e7eb; line-height:1.6;"></div>
        <textarea id="answersJsonDraft" readonly style="display:none; width:100%; min-height:120px; margin-top:12px; padding:10px; font-size:11px; font-family:ui-monospace,monospace; background:#020617; border:1px solid #374151; border-radius:8px; color:#d1d5db; box-sizing:border-box;" spellcheck="false"></textarea>
        <button type="button" id="copyAnswersDraftBtn" style="display:none; margin-top:8px; background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:8px; padding:8px 12px; font-size:12px; cursor:pointer;">
            Copy example answers JSON
        </button>
        <button type="button" id="closeSecurityModal" style="margin-top:16px; background:#374151; color:#f9fafb; border:0; border-radius:8px; padding:10px 16px; font-size:13px; cursor:pointer;">
            Close
        </button>
    </div>
</div>

<script>
    const IDENTITY_FAILED_HINT = 'TransUnion was unable to verify your identity automatically. You can try again later or request by post.';

    const runnerStatus = document.getElementById('runnerStatus');
    const runnerOutput = document.getElementById('runnerOutput');
    const creditCheckLiveLog = document.getElementById('creditCheckLiveLog');
    const clearLiveLogBtn = document.getElementById('clearLiveLogBtn');
    const sessionMeta = document.getElementById('sessionMeta');
    const runBtn = document.getElementById('runCreditCheckV2Btn');
    const cancelBtn = document.getElementById('cancelCreditCheckV2Btn');
    const streamSpinnerWrap = document.getElementById('streamSpinnerWrap');
    const resultBanner = document.getElementById('ccv2ResultBanner');
    const securityModal = document.getElementById('securityQuestionsModal');
    const securityQuestionsList = document.getElementById('securityQuestionsList');
    const closeSecurityModal = document.getElementById('closeSecurityModal');
    const answersJsonDraft = document.getElementById('answersJsonDraft');
    const copyAnswersDraftBtn = document.getElementById('copyAnswersDraftBtn');

    let streamAbortController = null;

    function csrfToken() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function setStatus(message, color = '#9ca3af') {
        runnerStatus.textContent = message;
        runnerStatus.style.color = color;
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function setStreamingUi(active) {
        streamSpinnerWrap.style.display = active ? 'flex' : 'none';
        cancelBtn.disabled = !active;
        cancelBtn.style.cursor = active ? 'pointer' : 'not-allowed';
        cancelBtn.style.color = active ? '#f9fafb' : '#9ca3af';
        cancelBtn.style.background = active ? '#b91c1c' : '#374151';
        cancelBtn.style.borderColor = active ? '#ef4444' : '#4b5563';
    }

    /**
     * @param {'success'|'failed'|'security'|'cancelled'|'error'} kind
     */
    function showValidationErrorBanner(message, errors) {
        const errObj = errors && typeof errors === 'object' && ! Array.isArray(errors) ? errors : {};
        const lines = Object.keys(errObj).map(function (k) {
            return errObj[k];
        });
        resultBanner.style.display = 'block';
        resultBanner.style.background = '#422006';
        resultBanner.style.border = '1px solid #a16207';
        resultBanner.style.color = '#fde68a';
        const listHtml = lines.length
            ? '<ul style="margin:10px 0 0 20px;padding:0;line-height:1.55;">' + lines.map(function (line) {
                return '<li style="margin-bottom:4px;">' + escapeHtml(String(line)) + '</li>';
            }).join('') + '</ul>'
            : '';
        resultBanner.innerHTML = '<strong>' + escapeHtml(message || 'Validation failed') + '</strong>' + listHtml;
    }

    function showResultBanner(kind, title, detail) {
        const styles = {
            success: { bg: '#052e16', border: '#166534', color: '#bbf7d0' },
            failed: { bg: '#450a0a', border: '#991b1b', color: '#fecaca' },
            security: { bg: '#422006', border: '#a16207', color: '#fde68a' },
            cancelled: { bg: '#1e293b', border: '#475569', color: '#e2e8f0' },
            error: { bg: '#450a0a', border: '#991b1b', color: '#fecaca' },
        };
        const s = styles[kind] || styles.error;
        resultBanner.style.display = 'block';
        resultBanner.style.background = s.bg;
        resultBanner.style.borderColor = s.border;
        resultBanner.style.color = s.color;
        resultBanner.innerHTML =
            '<strong>' + escapeHtml(title) + '</strong>' +
            (detail ? '<div style="margin-top:6px;font-size:13px;opacity:0.95;">' + escapeHtml(detail) + '</div>' : '');
    }

    function hideResultBanner() {
        resultBanner.style.display = 'none';
        resultBanner.innerHTML = '';
    }

    function appendLiveLog(chunk) {
        if (creditCheckLiveLog.textContent === '—' && chunk.length) {
            creditCheckLiveLog.textContent = '';
        }
        creditCheckLiveLog.textContent += chunk;
        creditCheckLiveLog.scrollTop = creditCheckLiveLog.scrollHeight;
    }

    clearLiveLogBtn.addEventListener('click', function () {
        creditCheckLiveLog.textContent = '';
    });

    cancelBtn.addEventListener('click', function () {
        if (streamAbortController) {
            streamAbortController.abort();
        }
    });

    function parseCreditCheckJsonLines(stdout) {
        const out = [];
        const re = /^CREDIT_CHECK_V2_JSON:(.+)$/gm;
        let m;
        while ((m = re.exec(stdout)) !== null) {
            try {
                out.push(JSON.parse(m[1]));
            } catch (e) {
                out.push({ raw: m[1], parseError: String(e) });
            }
        }
        return out;
    }

    function lastFailedEvent(events) {
        let last = null;
        for (let i = 0; i < events.length; i++) {
            if (events[i].status === 'failed') {
                last = events[i];
            }
        }
        return last;
    }

    function lastSecurityQuestionsEvent(events) {
        let last = null;
        for (let i = 0; i < events.length; i++) {
            if (events[i].status === 'security_questions') {
                last = events[i];
            }
        }
        return last;
    }

    function showSecurityQuestionsModal(sq) {
        if (!sq || !Array.isArray(sq.questions) || sq.questions.length === 0) {
            return;
        }
        const parts = sq.questions.map(function (q, i) {
            const id = escapeHtml(q.id || '—');
            const text = escapeHtml(q.text || '—');
            const idx0 = i;
            return '<div style="margin-bottom:12px; padding:10px; background:#020617; border:1px solid #374151; border-radius:8px;">' +
                '<div style="font-size:11px; color:#9ca3af; margin-bottom:4px;">Index <strong>' + idx0 + '</strong> (0-based) · id: <code style="color:#e5e7eb;">' + id + '</code></div>' +
                '<div>' + text + '</div></div>';
        });
        securityQuestionsList.innerHTML = parts.join('');

        const exampleAnswers = sq.questions.map(function (q, i) {
            return {
                index: i,
                id: q.id || '',
                value: 'REPLACE_WITH_EXACT_RADIO_LABEL_TEXT'
            };
        });
        answersJsonDraft.value = JSON.stringify({ answers: exampleAnswers }, null, 2);
        answersJsonDraft.style.display = 'block';
        copyAnswersDraftBtn.style.display = 'inline-block';

        securityModal.style.display = 'flex';
    }

    copyAnswersDraftBtn.addEventListener('click', function () {
        const t = answersJsonDraft.value || '';
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(t).catch(function () {});
        }
    });

    closeSecurityModal.addEventListener('click', function () {
        securityModal.style.display = 'none';
    });

    securityModal.addEventListener('click', function (e) {
        if (e.target === securityModal) {
            securityModal.style.display = 'none';
        }
    });

    function applyResultFromBuffer(stdout, headerMeta) {
        const events = parseCreditCheckJsonLines(stdout);
        const failed = lastFailedEvent(events);
        const success = events.some(function (e) { return e.success === true; });
        const sq = lastSecurityQuestionsEvent(events);
        const ok = !failed && success;

        if (failed && failed.reason === 'identity_verification_failed') {
            const detail = (failed.message && String(failed.message).trim()) ? String(failed.message) : IDENTITY_FAILED_HINT;
            setStatus('Identity verification failed', '#ef4444');
            showResultBanner('failed', 'Identity verification failed', detail);
        } else if (success) {
            setStatus('Finished', '#10b981');
            showResultBanner('success', 'Credit check completed', 'The statutory report PDF was saved successfully.');
        } else if (!failed && sq && sq.status === 'security_questions') {
            setStatus('Waiting for security answers…', '#fbbf24');
            showResultBanner(
                'security',
                'Security questions required',
                'Submit answers using the modal below or POST JSON to the answers URL. This page will continue automatically once answers are received.'
            );
        } else {
            setStatus('Failed or incomplete', '#ef4444');
            showResultBanner('error', 'Run did not complete', 'Check the live log and parsed result for details.');
        }

        const successEv = events.find(function (e) { return e.success === true; });

        const summary = {
            ok: ok,
            email: headerMeta.email || null,
            sessionId: headerMeta.sessionId || null,
            answersUrl: headerMeta.answersUrl || null,
            reportPath: successEv && successEv.reportPath ? successEv.reportPath : null,
            events: events,
            security_questions: sq,
            security_questions_events: events.filter(function (e) { return e.status === 'security_questions'; }),
            failed: failed,
            message: failed && failed.message ? failed.message : null,
        };
        runnerOutput.textContent = JSON.stringify(summary, null, 2);

        if (!failed && sq && sq.status === 'security_questions' && !success) {
            showSecurityQuestionsModal(sq);
        }
    }

    runBtn.addEventListener('click', async function () {
        runBtn.disabled = true;
        hideResultBanner();
        setStreamingUi(true);
        setStatus('Running Playwright…', '#fbbf24');
        creditCheckLiveLog.textContent = '';
        appendLiveLog('Starting streamed run…\n');
        sessionMeta.textContent = 'Connecting…';
        runnerOutput.textContent = '…';

        streamAbortController = new AbortController();
        const streamUrl = runBtn.getAttribute('data-stream-url') || (runBtn.dataset.url + '?stream=1');

        try {
            const response = await fetch(streamUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'text/plain, */*',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({}),
                signal: streamAbortController.signal,
            });

            if (response.status === 422) {
                let j = {};
                try {
                    j = await response.json();
                } catch (e) {
                    j = { message: 'Validation failed', errors: {} };
                }
                appendLiveLog('\n--- validation ---\n' + (j.message || 'Please fix the lead record.') + '\n');
                showValidationErrorBanner(j.message, j.errors);
                setStatus('Lead data incomplete', '#f59e0b');
                sessionMeta.textContent = 'Update the lead on the main lead page, then try again.';
                runnerOutput.textContent = JSON.stringify(j, null, 2);
                return;
            }

            const sessionId = response.headers.get('X-Credit-Check-Session-Id') || '';
            const email = response.headers.get('X-Credit-Check-Email') || '';
            const answersUrl = response.headers.get('X-Credit-Check-Answers-Url') || '';

            if (sessionId) {
                sessionMeta.innerHTML =
                    '<div><strong>sessionId:</strong> ' + escapeHtml(sessionId) + '</div>' +
                    (email ? '<div style="margin-top:6px;"><strong>Temp email:</strong> ' + escapeHtml(email) + '</div>' : '') +
                    (answersUrl
                        ? '<div style="margin-top:8px; word-break:break-all;"><strong>POST answers JSON:</strong> ' + escapeHtml(answersUrl) + '</div>' +
                          '<div style="margin-top:8px; font-size:11px;">Body: <code>{"answers":[{"id":"…","value":"…"},{"index":0,"value":"…"}]}</code></div>'
                        : '');
            }

            if (!response.ok) {
                const ct = response.headers.get('Content-Type') || '';
                let errText = 'HTTP ' + response.status;
                let apiMessage = '';
                if (ct.indexOf('application/json') !== -1) {
                    try {
                        const j = await response.json();
                        apiMessage = j.message ? String(j.message) : '';
                        errText = apiMessage || JSON.stringify(j);
                    } catch (e) {
                        errText = await response.text();
                    }
                } else {
                    errText = await response.text();
                }
                appendLiveLog('\n--- error ---\n' + errText + '\n');
                runnerOutput.textContent = errText;
                setStatus('Request failed', '#ef4444');
                showResultBanner('error', 'Request failed', apiMessage || errText);
                return;
            }

            const reader = response.body && response.body.getReader ? response.body.getReader() : null;
            if (!reader) {
                const t = await response.text();
                appendLiveLog(t);
                applyResultFromBuffer(t, { email: email, sessionId: sessionId, answersUrl: answersUrl });
                return;
            }

            const dec = new TextDecoder();
            let buffer = '';

            while (true) {
                const step = await reader.read();
                if (step.done) {
                    break;
                }
                const chunk = dec.decode(step.value, { stream: true });
                buffer += chunk;
                appendLiveLog(chunk);
            }

            applyResultFromBuffer(buffer, { email: email, sessionId: sessionId, answersUrl: answersUrl });
        } catch (e) {
            const name = e && e.name ? e.name : '';
            if (name === 'AbortError') {
                appendLiveLog('\n--- cancelled ---\nRequest aborted by user.\n');
                setStatus('Cancelled', '#94a3b8');
                showResultBanner('cancelled', 'Run cancelled', 'The browser stopped waiting for the server stream. If the Playwright process was still running on the server, it may continue until it finishes or times out.');
                runnerOutput.textContent = '{"cancelled":true}';
            } else {
                const msg = String(e);
                appendLiveLog('\n--- exception ---\n' + msg + '\n');
                runnerOutput.textContent = msg;
                setStatus('Request failed', '#ef4444');
                showResultBanner('error', 'Request error', msg);
            }
        } finally {
            setStreamingUi(false);
            streamAbortController = null;
            runBtn.disabled = false;
        }
    });
</script>

</body>
</html>
