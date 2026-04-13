@php
    $compact = $compact ?? false;
    $pad = $compact ? '10px 12px' : '12px 14px';
    $phoneDisplay = $lead->phone_number ?: '—';
    $canCall = (bool) trim((string) ($lead->phone_number ?? ''));
@endphp

@once
<div id="jinx-ctc-toast" role="status" aria-live="polite"
     style="display:none; position:fixed; bottom:24px; left:50%; transform:translateX(-50%); z-index:10001; max-width:min(420px, calc(100vw - 32px)); padding:12px 16px; border-radius:12px; font-size:14px; box-shadow:0 8px 32px rgba(0,0,0,0.45);"></div>
@endonce

<div style="background:#0f172a; border:1px solid #334155; border-radius:12px; padding:{{ $pad }}; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px;">
    <div style="min-width:0; flex:1;">
        <div style="font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px;">Click to call</div>
        <div style="font-size:15px; font-weight:700; color:#f9fafb; word-break:break-all;">{{ $phoneDisplay }}</div>
    </div>
    <div style="flex-shrink:0;">
        @if($canCall)
            <button type="button"
                    class="jinx-ctc-btn"
                    data-lead-id="{{ $lead->id }}"
                    style="display:inline-flex; align-items:center; gap:6px; background:#059669; color:#fff; border:none; font-weight:700; font-size:13px; padding:10px 14px; border-radius:10px; border:1px solid #10b981; cursor:pointer;">
                <span aria-hidden="true">📞</span>
                Call
            </button>
        @else
            <span style="font-size:12px; color:#64748b;">No phone number</span>
        @endif
    </div>
</div>

@once
<script>
(function () {
    if (window.__jinxCtcInit) return;
    window.__jinxCtcInit = true;

    var postUrl = @json(url('/api/click-to-call'));
    var csrf = document.querySelector('meta[name="csrf-token"]');
    csrf = csrf ? csrf.getAttribute('content') : '';

    function showCtcToast(message, style) {
        var el = document.getElementById('jinx-ctc-toast');
        if (!el) {
            window.alert(message);
            return;
        }
        el.style.display = 'block';
        if (style === 'error') {
            el.style.background = '#7f1d1d';
            el.style.border = '1px solid #b91c1c';
        } else if (style === 'warn') {
            el.style.background = '#78350f';
            el.style.border = '1px solid #d97706';
        } else {
            el.style.background = '#065f46';
            el.style.border = '1px solid #10b981';
        }
        el.style.color = '#fff';
        el.textContent = message;
        setTimeout(function () {
            el.style.display = 'none';
        }, 7000);
    }

    function formatDeckardError(data) {
        if (!data || typeof data !== 'object') return 'Call failed';
        return data.message || data.error || 'Call failed';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.jinx-ctc-btn');
        if (!btn || btn.disabled) return;

        e.preventDefault();
        var leadId = btn.getAttribute('data-lead-id');
        if (!leadId) return;

        btn.disabled = true;

        fetch(postUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ lead_id: parseInt(leadId, 10) })
        })
            .then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (err) {
                        data = { ok: false, message: text || 'Invalid response' };
                    }
                    return { res: res, data: data };
                });
            })
            .then(function (pair) {
                var res = pair.res;
                var data = pair.data;

                if (!res.ok) {
                    showCtcToast(formatDeckardError(data), 'error');
                    return;
                }

                if (data.ok) {
                    if (data.popup_confirmed === true) {
                        showCtcToast('Dial started and popup confirmed.', 'ok');
                    } else {
                        showCtcToast('Dial started, but popup not confirmed yet.', 'warn');
                    }
                    return;
                }

                showCtcToast(formatDeckardError(data), 'error');
            })
            .catch(function () {
                showCtcToast('Network error', 'error');
            })
            .finally(function () {
                btn.disabled = false;
            });
    });
})();
</script>
@endonce
