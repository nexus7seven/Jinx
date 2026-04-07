<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Check Helper - Lead {{ $lead->id }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body style="margin:0; font-family:Arial,sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">

@php
    $dobValue = $lead->date_of_birth ?? $lead->dob ?? '—';
    $postcodeValue = $lead->post_code ?? $lead->postcode ?? '';
    $creditCheckUrl = 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou';
@endphp

<div style="max-width:760px; margin:0 auto; padding:16px; box-sizing:border-box;">

    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:18px; box-sizing:border-box; margin-bottom:16px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
                <h1 style="margin:0; font-size:24px; line-height:1.2;">Credit Check Helper</h1>
                <div style="margin-top:6px; font-size:13px; color:#9ca3af;">
                    Lead {{ $lead->id }}
                </div>
            </div>

            <div id="helperStatus" style="font-size:12px; color:#9ca3af;">
                Ready
            </div>
        </div>

        <div style="margin-top:14px; background:#020617; border:1px solid #374151; border-radius:10px; padding:12px 14px;">
            <div style="font-size:12px; color:#9ca3af; margin-bottom:8px;">Credit Check URL</div>

            <div
                style="font-size:13px; line-height:1.5; word-break:break-word; color:#e5e7eb; margin-bottom:10px;"
            >
                {{ $creditCheckUrl }}
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button
                    type="button"
                    class="helper-copy-btn"
                    data-copy-value="{{ $creditCheckUrl }}"
                    data-original-label="COPY CREDIT CHECK URL"
                    style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:8px; padding:10px 12px; font-size:13px; cursor:pointer;"
                >
                    COPY CREDIT CHECK URL
                </button>

                <button
                    type="button"
                    id="openCreditCheckBtn"
                    style="background:#2563eb; color:#ffffff; border:0; border-radius:8px; padding:10px 12px; font-size:13px; cursor:pointer;"
                >
                    OPEN CREDIT CHECK
                </button>
            </div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">

        <button
            type="button"
            class="helper-copy-btn"
            data-copy-value="{{ $lead->first_name ?? '' }}"
            data-original-label="COPY FORENAME"
            style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:10px; padding:14px; font-size:13px; cursor:pointer;"
        >
            COPY FORENAME
        </button>

        <button
            type="button"
            class="helper-copy-btn"
            data-copy-value="{{ $lead->last_name ?? '' }}"
            data-original-label="COPY SURNAME"
            style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:10px; padding:14px; font-size:13px; cursor:pointer;"
        >
            COPY SURNAME
        </button>

        <div style="background:#111827; border:1px solid #374151; border-radius:10px; padding:14px;">
            <div style="font-size:11px; color:#9ca3af; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.04em;">DOB</div>
            <div style="font-size:16px; font-weight:700;">{{ $dobValue }}</div>
        </div>

        <button
            type="button"
            class="helper-copy-btn"
            data-copy-value="{{ $lead->temp_mail ?? '' }}"
            data-original-label="COPY EMAIL"
            style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:10px; padding:14px; font-size:13px; cursor:pointer;"
        >
            COPY EMAIL
        </button>

        <button
            type="button"
            class="helper-copy-btn"
            data-copy-value="{{ $lead->phone_number ?? '' }}"
            data-original-label="COPY PHONE NUMBER"
            style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:10px; padding:14px; font-size:13px; cursor:pointer;"
        >
            COPY PHONE NUMBER
        </button>

        <div style="background:#111827; border:1px solid #374151; border-radius:10px; padding:14px;">
            <div style="font-size:11px; color:#9ca3af; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.04em;">House Number</div>
            <div style="font-size:16px; font-weight:700;">{{ $lead->house_number ?? '—' }}</div>
        </div>

        <button
            type="button"
            class="helper-copy-btn"
            data-copy-value="{{ $postcodeValue }}"
            data-original-label="COPY POSTCODE"
            style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:10px; padding:14px; font-size:13px; cursor:pointer;"
        >
            COPY POSTCODE
        </button>

        <div style="background:#111827; border:1px solid #374151; border-radius:10px; padding:14px;">
            <div style="font-size:11px; color:#9ca3af; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.04em;">Code</div>
            <div id="codeValue" style="font-size:18px; font-weight:700;">{{ $lead->temp_mail_last_code ?? '—' }}</div>
        </div>

        <button
            type="button"
            class="helper-copy-btn"
            id="copyCodeBtn"
            data-copy-value="{{ $lead->temp_mail_last_code ?? '' }}"
            data-original-label="COPY CODE"
            style="background:#1f2937; color:#f9fafb; border:1px solid #374151; border-radius:10px; padding:14px; font-size:13px; cursor:pointer;"
        >
            COPY CODE
        </button>

        <button
            type="button"
            id="refreshCodeBtn"
            data-url="/leads/{{ $lead->id }}/temp-mail/latest-code"
            style="background:#2563eb; color:#ffffff; border:0; border-radius:10px; padding:14px; font-size:13px; cursor:pointer;"
        >
            REFRESH
        </button>
    </div>

    <div style="margin-top:16px; background:#111827; border:1px solid #374151; border-radius:14px; padding:16px;">
        <div style="font-size:14px; font-weight:700; margin-bottom:8px;">Debug</div>
        <div style="font-size:12px; color:#9ca3af; line-height:1.7;">
            first_name: {{ $lead->first_name ?: '—' }}<br>
            last_name: {{ $lead->last_name ?: '—' }}<br>
            temp_mail: {{ $lead->temp_mail ?: '—' }}<br>
            phone_number: {{ $lead->phone_number ?: '—' }}<br>
            house_number: {{ $lead->house_number ?: '—' }}<br>
            postcode: {{ $postcodeValue ?: '—' }}<br>
            temp_mail_last_code: {{ $lead->temp_mail_last_code ?: '—' }}
        </div>
    </div>

</div>

<script>
    const helperStatus = document.getElementById('helperStatus');
    const copyCodeBtn = document.getElementById('copyCodeBtn');
    const codeValue = document.getElementById('codeValue');
    const refreshCodeBtn = document.getElementById('refreshCodeBtn');
    const openCreditCheckBtn = document.getElementById('openCreditCheckBtn');
    const CREDIT_CHECK_URL = @json($creditCheckUrl);

    let autoRefreshTimer = null;
    let autoRefreshInFlight = false;
    let lastSeenCode = (copyCodeBtn?.dataset.copyValue || '').trim();

    function setHelperStatus(message, color = '#9ca3af') {
        helperStatus.textContent = message;
        helperStatus.style.color = color;
    }

    function setButtonTemporaryLabel(button, label, resetDelay = 1200) {
        const original = button.dataset.originalLabel || button.textContent;
        button.textContent = label;

        window.setTimeout(() => {
            button.textContent = original;
        }, resetDelay);
    }

    async function copyText(text) {
        if (!text || String(text).trim() === '') {
            throw new Error('empty');
        }

        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.setAttribute('readonly', '');
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        textArea.style.left = '-9999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        const ok = document.execCommand('copy');
        document.body.removeChild(textArea);

        if (!ok) {
            throw new Error('fallback copy failed');
        }
    }

    function applyCode(code) {
        const safeCode = (code || '').trim();
        codeValue.textContent = safeCode || '—';
        copyCodeBtn.dataset.copyValue = safeCode;
        lastSeenCode = safeCode;
    }

    async function fetchLatestCode(showStatus = false) {
        if (autoRefreshInFlight) return;

        autoRefreshInFlight = true;

        try {
            const response = await fetch(refreshCodeBtn.dataset.url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Refresh failed');
            }

            const data = await response.json();
            const newCode = (data.code || '').trim();

            if (newCode !== lastSeenCode) {
                applyCode(newCode);
                setHelperStatus(newCode ? 'New code received.' : 'Code cleared.', '#10b981');
            } else if (showStatus) {
                setHelperStatus(newCode ? 'Code unchanged.' : 'No code yet.', '#9ca3af');
            }
        } catch (e) {
            if (showStatus) {
                setHelperStatus('Unable to refresh code.', '#ef4444');
            }
        } finally {
            autoRefreshInFlight = false;
        }
    }

    document.querySelectorAll('.helper-copy-btn').forEach(button => {
        button.addEventListener('click', async function () {
            const value = this.dataset.copyValue || '';

            if (!value || value.trim() === '') {
                setHelperStatus('Nothing to copy.', '#f59e0b');
                setButtonTemporaryLabel(this, 'EMPTY');
                return;
            }

            try {
                await copyText(value);
                setHelperStatus('Copied.', '#10b981');
                setButtonTemporaryLabel(this, 'COPIED');
            } catch (e) {
                setHelperStatus('Copy failed.', '#ef4444');
                setButtonTemporaryLabel(this, 'FAILED');
            }
        });
    });

    openCreditCheckBtn.addEventListener('click', function () {
        window.open(CREDIT_CHECK_URL, '_blank');
    });

    refreshCodeBtn.addEventListener('click', async function () {
        const original = this.textContent;
        this.disabled = true;
        this.textContent = 'REFRESHING...';

        await fetchLatestCode(true);

        this.disabled = false;
        this.textContent = original;
    });

    function startAutoRefresh() {
        if (autoRefreshTimer) return;

        setHelperStatus('Auto refresh running...', '#9ca3af');

        autoRefreshTimer = window.setInterval(() => {
            fetchLatestCode(false);
        }, 99999999999999);
    }

    function stopAutoRefresh() {
        if (!autoRefreshTimer) return;
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            fetchLatestCode(false);
            startAutoRefresh();
        }
    });

    fetchLatestCode(false);
    startAutoRefresh();
</script>

</body>
</html>