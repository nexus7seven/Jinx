<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cred Check v3 - Lead {{ $lead->id }}</title>
</head>
<body style="margin:0; font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:#0b1220; color:#f9fafb; min-height:100vh;">
@php
    $runUrl = route('leads.credit-check-v3.run', $lead);
@endphp
<div style="max-width:920px; margin:0 auto; padding:16px;">
    <div style="background:#111827; border:1px solid #374151; border-radius:14px; padding:18px; margin-bottom:16px;">
        <h1 style="margin:0 0 8px;">Cred Check v3</h1>
        <div style="font-size:13px; color:#9ca3af; margin-bottom:12px;">Starts local listener job, tracks queue/state, and answers KBA in a modal (same pattern as lead show credit-check worker).</div>
        <button id="runBtn" data-run-url="{{ $runUrl }}" style="background:#6d28d9; color:#fff; border:0; border-radius:8px; padding:12px 16px; cursor:pointer;">Start Cred Check v3</button>
        <div id="statusLine" style="margin-top:10px; font-size:12px; color:#9ca3af;">Idle</div>
        <pre id="jobDump" style="margin-top:10px; background:#020617; border:1px solid #374151; border-radius:8px; padding:10px; color:#d1d5db; max-height:260px; overflow:auto;">—</pre>
    </div>
</div>

{{-- KBA modal: radios per question, POST answers to listener via Jinx (matches show.blade ccv2QuestionsModal pattern) --}}
<div id="ccV3KbaModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.72); z-index:10000; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
    <div style="background:#111827; border:1px solid #4b5563; border-radius:14px; max-width:560px; width:100%; max-height:88vh; overflow:auto; padding:22px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.55);">
        <h2 style="margin:0 0 10px; font-size:18px; color:#f9fafb;">Security questions</h2>
        <p style="margin:0 0 16px; font-size:13px; color:#9ca3af; line-height:1.55;">
            Select one answer per question. Submitted values use the option label text so the browser extension can match TransUnion radios.
        </p>
        <form id="ccV3KbaForm" style="margin:0;"></form>
        <div style="margin-top:18px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <button type="button" id="ccV3KbaSubmit" style="background:#2563eb; color:#fff; border:0; border-radius:8px; padding:10px 18px; font-size:14px; cursor:pointer;">Submit answers</button>
            <button type="button" id="ccV3KbaClose" style="background:#374151; color:#f9fafb; border:0; border-radius:8px; padding:10px 16px; font-size:13px; cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<script>
const runBtn = document.getElementById('runBtn');
const statusLine = document.getElementById('statusLine');
const jobDump = document.getElementById('jobDump');
const ccV3KbaModal = document.getElementById('ccV3KbaModal');
const ccV3KbaForm = document.getElementById('ccV3KbaForm');
const ccV3KbaSubmit = document.getElementById('ccV3KbaSubmit');
const ccV3KbaClose = document.getElementById('ccV3KbaClose');

let currentJobId = null;
let pollTimer = null;
let lastQuestionsFingerprint = null;
/** Fingerprint for which question set we already POSTed answers for (stops modal reopening until a new set arrives). */
let kbaSubmittedFingerprint = null;

function csrf() {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m ? m.getAttribute('content') : '';
}

function fingerprintQuestions(questions) {
  if (!Array.isArray(questions)) return '';
  return questions.map(function (q) {
    return String(q && q.id != null ? q.id : '') + '|' + String(q && q.question ? q.question : '');
  }).join('||');
}

/**
 * Same structure as leads/show.blade.php renderQuestions for credit-check-worker.
 * value on each radio = visible label so credcheckext applyKbaAnswers can match label text.
 */
function renderKbaQuestionsForm(questions) {
  ccV3KbaForm.innerHTML = '';
  questions.forEach(function (q, index) {
    const wrapper = document.createElement('div');
    wrapper.style.marginBottom = '16px';
    wrapper.style.padding = '12px 14px';
    wrapper.style.background = '#020617';
    wrapper.style.border = '1px solid #374151';
    wrapper.style.borderRadius = '10px';
    wrapper.dataset.questionBlock = '1';
    wrapper.dataset.questionId = q && q.id != null ? String(q.id) : '';

    const title = document.createElement('div');
    title.textContent = q && q.question ? String(q.question) : ('Question ' + (index + 1));
    title.style.marginBottom = '10px';
    title.style.fontWeight = '600';
    title.style.fontSize = '14px';
    title.style.lineHeight = '1.45';
    wrapper.appendChild(title);

    const answers = Array.isArray(q && q.answers) ? q.answers : [];
    answers.forEach(function (ans) {
      const optLabel = document.createElement('label');
      optLabel.style.display = 'block';
      optLabel.style.marginBottom = '6px';
      optLabel.style.cursor = 'pointer';
      optLabel.style.fontSize = '13px';
      optLabel.style.color = '#e5e7eb';

      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'ccv3_q_' + index;
      const raw = ans && typeof ans === 'object' ? ans : {};
      const labelText = String(raw.label != null ? raw.label : '').trim();
      const fallback = String(raw.value != null ? raw.value : '').trim();
      const display = labelText || fallback;
      radio.value = labelText || fallback;
      optLabel.appendChild(radio);
      optLabel.appendChild(document.createTextNode(' ' + display));
      wrapper.appendChild(optLabel);
    });

    ccV3KbaForm.appendChild(wrapper);
  });
}

function openKbaModal(questions) {
  const fp = fingerprintQuestions(questions);
  if (!fp || !Array.isArray(questions) || questions.length === 0) {
    return;
  }
  if (fp === kbaSubmittedFingerprint) {
    return;
  }
  if (fp === lastQuestionsFingerprint && ccV3KbaModal.style.display === 'flex') {
    return;
  }
  lastQuestionsFingerprint = fp;
  renderKbaQuestionsForm(questions);
  ccV3KbaModal.style.display = 'flex';
}

function closeKbaModal() {
  ccV3KbaModal.style.display = 'none';
}

function collectKbaAnswersFromForm() {
  const answers = [];
  const blocks = ccV3KbaForm.querySelectorAll('[data-question-block="1"]');
  let idx = 0;
  blocks.forEach(function (block) {
    const id = block.dataset.questionId || '';
    const selected = block.querySelector('input[type="radio"]:checked');
    const value = selected ? String(selected.value).trim() : '';
    answers.push({
      id: id,
      index: idx,
      value: value || null,
    });
    idx += 1;
  });
  return answers;
}

async function pollStatus() {
  if (!currentJobId) return;
  const res = await fetch('/leads/credit-check-v3/jobs/' + encodeURIComponent(currentJobId) + '/status');
  const json = await res.json();
  jobDump.textContent = JSON.stringify(json, null, 2);
  const state = json && json.state ? json.state : {};
  const latest = state.latestStatus && state.latestStatus.data ? state.latestStatus.data : {};
  statusLine.textContent = 'Job ' + currentJobId + ' | active=' + Boolean(state.active) + ' queued=' + Boolean(state.queued) + ' step=' + (latest.step || 'n/a');

  const qRoot = json && json.questions ? json.questions : {};
  const qPayload = qRoot && qRoot.payload ? qRoot.payload : null;
  const list = qPayload && Array.isArray(qPayload.questions)
    ? qPayload.questions
    : (Array.isArray(qRoot.questions) ? qRoot.questions : []);

  if (list.length > 0) {
    openKbaModal(list);
  } else if (ccV3KbaModal.style.display === 'flex') {
    closeKbaModal();
  }
}

runBtn.addEventListener('click', async function () {
  statusLine.textContent = 'Starting job...';
  lastQuestionsFingerprint = null;
  kbaSubmittedFingerprint = null;
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
  statusLine.textContent = 'Started ' + currentJobId;
  if (pollTimer) clearInterval(pollTimer);
  await pollStatus();
  pollTimer = setInterval(pollStatus, 4000);
});

ccV3KbaSubmit.addEventListener('click', async function () {
  if (!currentJobId) return;
  const answers = collectKbaAnswersFromForm();
  const missing = answers.some(function (a) { return !a.value; });
  if (missing) {
    alert('Please select an answer for every question.');
    return;
  }
  try {
    const res = await fetch('/leads/credit-check-v3/jobs/' + encodeURIComponent(currentJobId) + '/answers', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrf(),
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ answers: answers }),
    });
    const json = await res.json();
    jobDump.textContent = JSON.stringify(json, null, 2);
    if (!res.ok) {
      statusLine.textContent = 'Submit failed: ' + (json.message || res.status);
      alert('Failed to submit answers.');
      return;
    }
    kbaSubmittedFingerprint = lastQuestionsFingerprint;
    statusLine.textContent = 'Submitted ' + answers.length + ' KBA answer(s)';
    closeKbaModal();
  } catch (e) {
    alert('Failed to submit answers.');
  }
});

ccV3KbaClose.addEventListener('click', closeKbaModal);

ccV3KbaModal.addEventListener('click', function (e) {
  if (e.target === ccV3KbaModal) {
    closeKbaModal();
  }
});
</script>
</body>
</html>
