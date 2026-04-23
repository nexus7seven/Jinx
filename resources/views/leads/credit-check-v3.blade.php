<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cred Check v3 - Lead {{ $lead->id }}</title>
</head>
<body style="margin:0; font-family:Arial,sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">
@php
    $runUrl = route('leads.credit-check-v3.run', $lead);
@endphp
<div style="max-width:920px; margin:0 auto; padding:16px;">
    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:18px; margin-bottom:16px;">
        <h1 style="margin:0 0 8px;">Cred Check v3</h1>
        <div style="font-size:13px; color:#9ca3af; margin-bottom:12px;">Starts local listener job, tracks queue/state, and handles KBA answers.</div>
        <button id="runBtn" data-run-url="{{ $runUrl }}" style="background:#6d28d9; color:#fff; border:0; border-radius:8px; padding:12px 16px; cursor:pointer;">Start Cred Check v3</button>
        <div id="statusLine" style="margin-top:10px; font-size:12px; color:#9ca3af;">Idle</div>
        <pre id="jobDump" style="margin-top:10px; background:#020617; border:1px solid #374151; border-radius:8px; padding:10px; color:#d1d5db; max-height:260px; overflow:auto;">—</pre>
    </div>

    <div id="kbaWrap" style="display:none; background:#111827; border:1px solid #92400e; border-radius:14px; padding:18px;">
        <h2 style="margin:0 0 8px;">Security Questions (KBA)</h2>
        <div id="kbaQuestions" style="font-size:13px; line-height:1.5; margin-bottom:10px;"></div>
        <div style="font-size:12px; color:#9ca3af; margin-bottom:8px;">Paste answers JSON array, e.g. <code>[{"id":"...","value":"Yes"}]</code></div>
        <textarea id="kbaAnswers" style="width:100%; min-height:120px; box-sizing:border-box; background:#020617; border:1px solid #374151; border-radius:8px; color:#e5e7eb; padding:10px;"></textarea>
        <button id="submitKbaBtn" style="margin-top:10px; background:#2563eb; color:#fff; border:0; border-radius:8px; padding:10px 14px; cursor:pointer;">Submit KBA answers</button>
    </div>
</div>

<script>
const runBtn = document.getElementById('runBtn');
const statusLine = document.getElementById('statusLine');
const jobDump = document.getElementById('jobDump');
const kbaWrap = document.getElementById('kbaWrap');
const kbaQuestions = document.getElementById('kbaQuestions');
const kbaAnswers = document.getElementById('kbaAnswers');
const submitKbaBtn = document.getElementById('submitKbaBtn');
let currentJobId = null;
let pollTimer = null;

function csrf() {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m ? m.getAttribute('content') : '';
}

async function pollStatus() {
  if (!currentJobId) return;
  const res = await fetch(`/leads/credit-check-v3/jobs/${encodeURIComponent(currentJobId)}/status`);
  const json = await res.json();
  jobDump.textContent = JSON.stringify(json, null, 2);
  const state = json && json.state ? json.state : {};
  const latest = state.latestStatus && state.latestStatus.data ? state.latestStatus.data : {};
  statusLine.textContent = `Job ${currentJobId} | active=${Boolean(state.active)} queued=${Boolean(state.queued)} step=${latest.step || 'n/a'}`;

  const qPayload = json && json.questions && json.questions.payload ? json.questions.payload : null;
  if (qPayload && Array.isArray(qPayload.questions) && qPayload.questions.length > 0) {
    kbaWrap.style.display = 'block';
    kbaQuestions.innerHTML = qPayload.questions.map((q, i) => {
      const answers = Array.isArray(q.answers) ? q.answers.map(a => a.label).join(' | ') : '';
      return `<div style="margin-bottom:10px;"><strong>Q${i+1}:</strong> ${q.question}<br><span style="color:#9ca3af;">${answers}</span></div>`;
    }).join('');
  }
}

runBtn.addEventListener('click', async () => {
  statusLine.textContent = 'Starting job...';
  const res = await fetch(runBtn.dataset.runUrl, {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': csrf(),
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({}),
  });
  const json = await res.json();
  currentJobId = json.jobId || null;
  jobDump.textContent = JSON.stringify(json, null, 2);
  if (!currentJobId) {
    statusLine.textContent = 'Failed to start job';
    return;
  }
  statusLine.textContent = `Started ${currentJobId}`;
  if (pollTimer) clearInterval(pollTimer);
  await pollStatus();
  pollTimer = setInterval(pollStatus, 4000);
});

submitKbaBtn.addEventListener('click', async () => {
  if (!currentJobId) return;
  let answers = [];
  try {
    const parsed = JSON.parse(kbaAnswers.value || '[]');
    answers = Array.isArray(parsed) ? parsed : [];
  } catch (_e) {
    alert('Invalid JSON in KBA answers');
    return;
  }
  const res = await fetch(`/leads/credit-check-v3/jobs/${encodeURIComponent(currentJobId)}/answers`, {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': csrf(),
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ answers }),
  });
  const json = await res.json();
  jobDump.textContent = JSON.stringify(json, null, 2);
  statusLine.textContent = `Submitted ${answers.length} KBA answer(s)`;
});
</script>
</body>
</html>

