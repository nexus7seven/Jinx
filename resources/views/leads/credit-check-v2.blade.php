<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Check v2 - Lead {{ $lead->id }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body style="margin:0; font-family:Arial,sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">

@php
    $creditCheckUrl = 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou';
@endphp

<div style="max-width:820px; margin:0 auto; padding:16px; box-sizing:border-box;">

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
            <code style="color:#e5e7eb;">PLAYWRIGHT_HEADLESS=0</code> to debug.
            Machine-readable lines in stdout: <code style="color:#e5e7eb;">CREDIT_CHECK_V2_JSON:{...}</code>.
            If security questions pause the run, POST JSON to the <strong>answers URL</strong> shown in the response (or write <code style="color:#e5e7eb;">answers.json</code> in the session folder).
        </div>

        <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
            <button
                type="button"
                id="runCreditCheckV2Btn"
                data-url="{{ route('leads.credit-check-v2.run', $lead) }}"
                style="background:#5b21b6; color:#ffffff; border:0; border-radius:8px; padding:12px 18px; font-size:14px; cursor:pointer;"
            >
                Run automated credit check
            </button>
        </div>
    </div>

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:16px; margin-bottom:16px;">
        <div style="font-size:14px; font-weight:700; margin-bottom:8px;">Session / answers</div>
        <div id="sessionMeta" style="font-size:12px; color:#9ca3af; line-height:1.6;">
            Run once to obtain <code style="color:#e5e7eb;">sessionId</code> and answers endpoint.
        </div>
    </div>

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:16px;">
        <div style="font-size:14px; font-weight:700; margin-bottom:8px;">Output</div>
        <pre id="runnerOutput" style="margin:0; font-size:12px; line-height:1.5; white-space:pre-wrap; word-break:break-word; color:#d1d5db; max-height:420px; overflow:auto;">—</pre>
    </div>

</div>

<script>
    const runnerStatus = document.getElementById('runnerStatus');
    const runnerOutput = document.getElementById('runnerOutput');
    const sessionMeta = document.getElementById('sessionMeta');
    const runBtn = document.getElementById('runCreditCheckV2Btn');

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

    runBtn.addEventListener('click', async function () {
        runBtn.disabled = true;
        setStatus('Running Playwright…', '#fbbf24');
        runnerOutput.textContent = 'Starting…';
        sessionMeta.textContent = 'Run in progress…';

        try {
            const response = await fetch(runBtn.dataset.url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({}),
            });

            const data = await response.json().catch(() => ({}));
            const lines = [];

            if (data.message) {
                lines.push('Error: ' + data.message);
            }
            if (data.email) {
                lines.push('Temp email: ' + data.email);
            }
            if (data.sessionId) {
                lines.push('sessionId: ' + data.sessionId);
            }
            if (data.reportPath) {
                lines.push('reportPath: ' + data.reportPath);
            }
            if (data.answersUrl) {
                lines.push('answersUrl: ' + data.answersUrl);
                sessionMeta.innerHTML = '<div><strong>sessionId:</strong> ' + escapeHtml(data.sessionId || '—') + '</div>' +
                    '<div style="margin-top:8px; word-break:break-all;"><strong>POST answers JSON:</strong> ' + escapeHtml(data.answersUrl || '') + '</div>' +
                    '<div style="margin-top:8px; font-size:11px;">Body: <code>{"answers":[{"id":"field-id","value":"..."}]}</code></div>';
            }
            if (data.exit_code !== undefined) {
                lines.push('Exit code: ' + data.exit_code);
            }
            if (data.stdout) {
                lines.push('--- stdout ---');
                lines.push(data.stdout);
                const parsed = parseCreditCheckJsonLines(data.stdout);
                if (parsed.length) {
                    lines.push('--- CREDIT_CHECK_V2_JSON (parsed) ---');
                    lines.push(JSON.stringify(parsed, null, 2));
                }
            }
            if (data.stderr) {
                lines.push('--- stderr ---');
                lines.push(data.stderr);
            }

            runnerOutput.textContent = lines.length ? lines.join('\n') : JSON.stringify(data, null, 2);

            if (response.ok && data.ok) {
                setStatus('Finished', '#10b981');
            } else {
                setStatus('Failed', '#ef4444');
            }
        } catch (e) {
            runnerOutput.textContent = String(e);
            setStatus('Request failed', '#ef4444');
        } finally {
            runBtn.disabled = false;
        }
    });
</script>

</body>
</html>
