<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Portal</title>
</head>
<body style="margin:0; background:#f8fafc; color:#0f172a; font-family:Arial, sans-serif; min-height:100vh;">
<div style="max-width:760px; margin:0 auto; padding:40px 20px;">
    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:24px; padding:32px; box-shadow:0 20px 50px rgba(15,23,42,.08);">
        @if ($errors->has('credit_check'))
            <div style="margin-bottom:16px; padding:12px 14px; border-radius:12px; border:1px solid #fecaca; background:#fef2f2; color:#991b1b; font-size:14px;">
                {{ $errors->first('credit_check') }}
            </div>
        @endif
        @if (($progress->current_step ?? 'welcome') === 'welcome')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Secure customer portal
            </div>

            <h1 style="margin:0 0 12px 0; font-size:34px; line-height:1.15;">
                Let&rsquo;s build a clear picture of your situation
            </h1>

            <p style="margin:0 0 24px 0; color:#475569; font-size:17px; line-height:1.6;">
                We&rsquo;ll guide you through a few simple steps so you can see where things stand.
            </p>

            <div style="display:grid; grid-template-columns:1fr; gap:12px; margin-bottom:24px;">
                <div style="padding:14px 16px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc;">
                    You can use rough estimates
                </div>
                <div style="padding:14px 16px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc;">
                    You can come back to this link for 30 days
                </div>
                <div style="padding:14px 16px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc;">
                    We&rsquo;ll close this session once you&rsquo;re finished
                </div>
            </div>

            <form method="POST" action="{{ route('portal.welcome.complete', ['token' => $rawToken]) }}">
                @csrf
                <button
                    type="submit"
                    style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;"
                >
                    Start
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'details')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Details step
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                A few details to get started
            </h1>

            <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">
                We use this to match the right information to you.
            </p>
            <p style="margin:0 0 22px 0; color:#64748b; font-size:14px; line-height:1.6;">
                You can update this later.
            </p>

            <form method="POST" action="{{ route('portal.details.save', ['token' => $rawToken]) }}">
                @csrf

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div>
                        <label for="title" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Title</label>
                        <select id="title" name="title"
                                style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                            <option value="">Select a title</option>
                            @foreach (($titleOptions ?? []) as $option)
                                <option value="{{ $option }}" @selected(old('title', $lead->title) === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                        @error('title')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="first_name" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">First name</label>
                        <input id="first_name" name="first_name" type="text" value="{{ old('first_name', $lead->first_name) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('first_name')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="last_name" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Last name</label>
                        <input id="last_name" name="last_name" type="text" value="{{ old('last_name', $lead->last_name) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('last_name')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div>
                        <label for="dob" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Date of birth</label>
                        <input id="dob" name="dob" type="date" value="{{ old('dob', $lead->dob) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('dob')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="email" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $lead->email) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('email')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div>
                        <label for="phone" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Phone</label>
                        <input id="phone" name="phone" type="text" value="{{ old('phone', $lead->phone_number) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('phone')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="postcode" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Postcode</label>
                        <input id="postcode" name="postcode" type="text" value="{{ old('postcode', $lead->postcode) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('postcode')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div style="margin-bottom:20px;">
                    <label for="house_number" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">House number</label>
                    <input id="house_number" name="house_number" type="text" value="{{ old('house_number', $lead->house_number) }}"
                           style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                    @error('house_number')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                </div>

                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Save and continue
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'debts')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Debts step
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Let&rsquo;s look at what you owe
            </h1>

            <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">
                A rough estimate is absolutely fine.
            </p>
            <p style="margin:0 0 22px 0; color:#64748b; font-size:14px; line-height:1.6;">
                We&rsquo;ll use the credit check to help fill in anything you may have missed.
            </p>

            <form method="POST" action="{{ route('portal.debts.save', ['token' => $rawToken]) }}">
                @csrf

                <div style="margin-bottom:18px;">
                    <label for="estimated_total_debt" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Estimated total debt</label>
                    <input id="estimated_total_debt" name="estimated_total_debt" type="text" inputmode="decimal" step="0.01" min="0"
                           value="{{ old('estimated_total_debt', $lead->estimated_total_debt) }}"
                           style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                    @error('estimated_total_debt')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                </div>

                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Save and continue
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'income')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Income step
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                What&rsquo;s coming in each month?
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                Estimate is fine.
            </p>

            <form method="POST" action="{{ route('portal.income.save', ['token' => $rawToken]) }}">
                @csrf

                <div style="margin-bottom:14px;">
                    <label for="employment_status" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Employment status</label>
                    <select id="employment_status" name="employment_status"
                            style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        <option value="">Select an option</option>
                        @foreach ($employmentStatusOptions as $option)
                            <option value="{{ $option }}" @selected(old('employment_status', $lead->employment_status) === $option)>
                                {{ $option }}
                            </option>
                        @endforeach
                    </select>
                    @error('employment_status')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                </div>

                <div style="margin-bottom:20px;">
                    <label for="monthly_income" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Monthly income after tax</label>
                    <input id="monthly_income" name="monthly_income" type="text" inputmode="decimal" step="0.01" min="0"
                           value="{{ old('monthly_income', $lead->monthly_income) }}"
                           style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                    @error('monthly_income')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                </div>

                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Save and continue
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'costs')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Costs step
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                What are your main monthly costs?
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                Estimates are fine &mdash; this just helps build a clearer picture.
            </p>

            <form method="POST" action="{{ route('portal.costs.save', ['token' => $rawToken]) }}">
                @csrf

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div>
                        <label for="monthly_housing_cost" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Rent or mortgage</label>
                        <input id="monthly_housing_cost" name="monthly_housing_cost" type="text" inputmode="decimal" step="0.01" min="0"
                               value="{{ old('monthly_housing_cost', $lead->monthly_housing_cost) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('monthly_housing_cost')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="monthly_council_tax" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Council tax</label>
                        <input id="monthly_council_tax" name="monthly_council_tax" type="text" inputmode="decimal" step="0.01" min="0"
                               value="{{ old('monthly_council_tax', $lead->monthly_council_tax) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('monthly_council_tax')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:20px;">
                    <div>
                        <label for="monthly_utilities_cost" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Utilities</label>
                        <input id="monthly_utilities_cost" name="monthly_utilities_cost" type="text" inputmode="decimal" step="0.01" min="0"
                               value="{{ old('monthly_utilities_cost', $lead->monthly_utilities_cost) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('monthly_utilities_cost')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label for="monthly_food_travel_cost" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Food and travel</label>
                        <input id="monthly_food_travel_cost" name="monthly_food_travel_cost" type="text" inputmode="decimal" step="0.01" min="0"
                               value="{{ old('monthly_food_travel_cost', $lead->monthly_food_travel_cost) }}"
                               style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                        @error('monthly_food_travel_cost')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                    </div>
                </div>

                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Save and continue
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'credit_check')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Credit check
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Let&rsquo;s fill in the gaps
            </h1>

            <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">
                We can securely check your credit file to help find anything you may have missed.
            </p>
            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                This won&rsquo;t affect your credit score.
            </p>

            <form method="POST" action="{{ route('portal.credit-check.v3.start', ['token' => $rawToken]) }}">
                @csrf
                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Start check
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'credit_check_running')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Credit check
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                We&rsquo;re checking your information
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                This can take a few moments.
            </p>

            <div id="portal-credit-check-status"
                 style="padding:14px 16px; border-radius:12px; border:1px solid #e2e8f0; background:#f8fafc; color:#334155;">
                We&rsquo;ll refresh this page as soon as your check is ready.
            </div>

            <script>
                (function () {
                    const statusEl = document.getElementById('portal-credit-check-status');
                    const pollUrl = @json(route('portal.credit-check.poll', ['token' => $rawToken]));
                    let pollFailureCount = 0;

                    async function pollOnce() {
                        try {
                            const response = await fetch(pollUrl, {
                                method: 'GET',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                                credentials: 'same-origin'
                            });
                            if (!response.ok) {
                                throw new Error('poll_failed');
                            }
                            const data = await response.json();
                            pollFailureCount = 0;
                            if (data.status === 'complete' || data.status === 'questions') {
                                window.location.href = @json(route('portal.entry', ['token' => $rawToken]));
                                return;
                            }
                            if (data.status === 'failed') {
                                if (statusEl) {
                                    statusEl.textContent = data.message || 'Something went wrong while checking your information. You can try again now.';
                                }
                                window.location.href = @json(route('portal.entry', ['token' => $rawToken]));
                                return;
                            }
                        } catch (error) {
                            pollFailureCount += 1;
                            if (statusEl) {
                                statusEl.textContent = pollFailureCount >= 3
                                    ? 'Something went wrong while checking your information. Redirecting now...'
                                    : 'Still checking... We will keep trying automatically.';
                            }
                            if (pollFailureCount >= 3) {
                                window.location.href = @json(route('portal.entry', ['token' => $rawToken]));
                                return;
                            }
                        }

                        setTimeout(pollOnce, 4000);
                    }

                    setTimeout(pollOnce, 1500);
                })();
            </script>
        @elseif (($progress->current_step ?? 'welcome') === 'credit_check_questions')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Security check
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                We need to confirm a few details
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                These questions help match your credit file securely.
            </p>

            @if (empty($creditCheckQuestions ?? []))
                <div style="padding:14px 16px; border-radius:12px; border:1px solid #e2e8f0; background:#f8fafc; color:#475569; margin-bottom:18px;">
                    We are preparing your verification questions. Please refresh in a moment.
                </div>
            @else
                <form method="POST" action="{{ route('portal.credit-check.answers', ['token' => $rawToken]) }}">
                    @csrf
                    @foreach (($creditCheckQuestions ?? []) as $index => $question)
                        @php
                            $questionText = $question['question'] ?? $question['prompt'] ?? ('Question '.($index + 1));
                            $rawOptions = is_array($question['answers'] ?? null) ? $question['answers'] : [];
                            $options = [];
                            foreach ($rawOptions as $rawOption) {
                                if (is_string($rawOption)) {
                                    $text = trim($rawOption);
                                    if ($text === '') {
                                        continue;
                                    }
                                    $options[] = ['value' => $text, 'label' => $text];
                                    continue;
                                }
                                if (is_array($rawOption)) {
                                    $value = trim((string) ($rawOption['value'] ?? ''));
                                    $label = trim((string) ($rawOption['label'] ?? ''));
                                    if ($value === '' && $label === '') {
                                        continue;
                                    }
                                    $options[] = [
                                        'value' => $value !== '' ? $value : $label,
                                        'label' => $label !== '' ? $label : $value,
                                    ];
                                }
                            }
                            if ($options === []) {
                                $options = [
                                    ['value' => 'Yes', 'label' => 'Yes'],
                                    ['value' => 'No', 'label' => 'No'],
                                ];
                            }
                        @endphp
                        <div style="margin-bottom:14px;">
                            <label style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">
                                {{ $questionText }}
                            </label>
                            <input type="hidden" name="answers[{{ $index }}][id]" value="{{ $question['id'] ?? '' }}">
                            <input type="hidden" name="answers[{{ $index }}][index]" value="{{ $index }}">
                            <input type="hidden" name="answers[{{ $index }}][label]" value="{{ old('answers.'.$index.'.label') }}">
                            @foreach ($options as $option)
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; color:#334155; font-size:14px;">
                                    <input type="radio"
                                           name="answers[{{ $index }}][value]"
                                           value="{{ $option['value'] }}"
                                           data-answer-label="{{ $option['label'] }}"
                                           @checked(old('answers.'.$index.'.value') === $option['value'])>
                                    <span>{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                            @error('answers.'.$index.'.value')<div style="margin-top:6px; color:#b91c1c; font-size:13px;">{{ $message }}</div>@enderror
                        </div>
                    @endforeach

                    <button type="submit"
                            style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                        Continue securely
                    </button>
                </form>
                <script>
                    (function () {
                        const form = document.querySelector('form[action="{{ route('portal.credit-check.answers', ['token' => $rawToken]) }}"]');
                        if (!form) return;
                        form.addEventListener('change', function (event) {
                            const target = event.target;
                            if (!(target instanceof HTMLInputElement) || target.type !== 'radio') return;
                            const match = target.name.match(/^answers\[(\d+)\]\[value\]$/);
                            if (!match) return;
                            const idx = match[1];
                            const hidden = form.querySelector('input[name="answers[' + idx + '][label]"]');
                            if (!(hidden instanceof HTMLInputElement)) return;
                            hidden.value = target.dataset.answerLabel || target.value;
                        });
                    })();
                </script>
            @endif
        @elseif (($progress->current_step ?? 'welcome') === 'credit_report_debts')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Credit file balances
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Here&rsquo;s what appeared on your credit file
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                We&rsquo;ve found the following balances. Some debts, like council tax, water bills, parking fines, or recent accounts, may not always show here.
            </p>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:20px;">
                @if (($creditCheckDebts ?? collect())->count() > 0)
                    @foreach (($creditCheckDebts ?? collect()) as $debt)
                        <div style="display:flex; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px solid #e2e8f0; font-size:14px; color:#334155;">
                            <span>{{ $leadPortalDebtPresenter->customerFacingCreditorName($debt) }}</span>
                            <strong>{{ $debt->balance !== null ? '£'.number_format((float) $debt->balance, 2) : 'No balance provided' }}</strong>
                        </div>
                    @endforeach
                    <div style="display:flex; justify-content:space-between; gap:12px; padding-top:12px; font-size:15px; color:#0f172a;">
                        <strong>Total found</strong>
                        <strong>{{ '£'.number_format((float) ($creditCheckTotal ?? 0), 2) }}</strong>
                    </div>
                @else
                    <div style="font-size:14px; color:#64748b; line-height:1.6;">
                        We couldn&rsquo;t see any balances from the credit check, but you can still add anything you know about next.
                    </div>
                @endif
            </div>

            <form method="POST" action="{{ route('portal.credit-report-debts.continue', ['token' => $rawToken]) }}">
                @csrf
                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Continue
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'add_missing_debts')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Missing debts
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Is anything missing?
            </h1>

            <p style="margin:0 0 8px 0; color:#475569; font-size:16px; line-height:1.6;">
                Some debts may not show on your credit file, such as council tax, water bills, parking fines, rent arrears, or recent accounts.
            </p>
            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                If you can&rsquo;t find the creditor, choose Other.
            </p>

            @if ($errors->has('missing_debts'))
                <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:14px; font-weight:700;">
                    {{ $errors->first('missing_debts') }}
                </div>
            @endif

            <form method="POST" action="{{ route('portal.missing-debts.save', ['token' => $rawToken]) }}">
                @csrf

                @for ($index = 0; $index < 3; $index++)
                    <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:12px;">
                        <div style="display:grid; grid-template-columns:1.4fr 1.4fr .8fr; gap:12px;">
                            <div>
                                <label for="missing_debts_{{ $index }}_creditor_id" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Creditor</label>
                                <select id="missing_debts_{{ $index }}_creditor_id" name="debts[{{ $index }}][creditor_id]"
                                        style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                                    <option value="">Select creditor</option>
                                    @foreach (($creditors ?? collect()) as $creditor)
                                        <option value="{{ $creditor->id }}" @selected(old('debts.'.$index.'.creditor_id') == $creditor->id)>{{ $creditor->name }}</option>
                                    @endforeach
                                    <option value="other" @selected(old('debts.'.$index.'.creditor_id') === 'other')>Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="missing_debts_{{ $index }}_creditor_name" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Other creditor / reference</label>
                                <input id="missing_debts_{{ $index }}_creditor_name" name="debts[{{ $index }}][creditor_name]" type="text"
                                       value="{{ old('debts.'.$index.'.creditor_name') }}"
                                       style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                            </div>
                            <div>
                                <label for="missing_debts_{{ $index }}_balance" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Balance</label>
                                <input id="missing_debts_{{ $index }}_balance" name="debts[{{ $index }}][balance]" type="text" inputmode="decimal"
                                       value="{{ old('debts.'.$index.'.balance') }}"
                                       style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                            </div>
                        </div>
                    </div>
                @endfor

                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Continue
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'iva_results')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Results
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Here&rsquo;s what this could mean
            </h1>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:18px;">
                <div style="font-weight:700; margin-bottom:6px;">Total debt found or entered</div>
                <div style="font-size:28px; font-weight:700; color:#0f172a;">
                    {{ '£'.number_format((float) ($ivaEstimate['total_debt'] ?? 0), 2) }}
                </div>
            </div>

            @if (($ivaEstimate['is_eligible'] ?? false) === true)
                <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                    Based on what we&rsquo;ve found so far, an IVA may be worth looking at.
                </p>
                <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                    For example, if payments were around £100 per month for 60 months, that would total £6,000.
                </p>
                <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                    Compared with your estimated debt total of {{ '£'.number_format((float) ($ivaEstimate['total_debt'] ?? 0), 2) }}, that could mean around {{ '£'.number_format((float) ($ivaEstimate['estimated_write_off'] ?? 0), 2) }} may not need to be repaid, depending on your final assessment.
                </p>
                <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                    An IVA can also help stop creditor pressure and enforcement once approved.
                </p>
            @else
                <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                    Based on the figures so far, an IVA may not be the best fit, but we can still help you understand your options.
                </p>
            @endif

            @if (($ivaEstimate['has_court_judgment_debt'] ?? false) === true)
                <div style="margin:14px 0 18px 0; padding:14px 16px; border-radius:14px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; font-size:14px; line-height:1.6;">
                    We&rsquo;ve also seen court judgment information, so it may be important to get advice before enforcement escalates.
                </div>
            @endif

            <form method="POST" action="{{ route('portal.iva-results.continue', ['token' => $rawToken]) }}">
                @csrf
                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Continue to summary
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'review')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Review
            </div>

            <h1 style="margin:0 0 14px 0; font-size:32px; line-height:1.2;">
                Review what we have so far
            </h1>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:12px;">
                <div style="font-weight:700; margin-bottom:8px;">Your details</div>
                <div style="font-size:14px; color:#334155;">Name: {{ trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) !== '' ? trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) : 'Not provided' }}</div>
                <div style="font-size:14px; color:#334155;">Postcode: {{ $lead->postcode ?: 'Not provided' }}</div>
                @if (filled($lead->phone_number))
                    <div style="font-size:14px; color:#334155;">Phone: {{ $lead->phone_number }}</div>
                @endif
                @if (filled($lead->email))
                    <div style="font-size:14px; color:#334155;">Email: {{ $lead->email }}</div>
                @endif
            </div>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:12px;">
                <div style="font-weight:700; margin-bottom:8px;">Your debts</div>
                @if (($reviewDebts ?? collect())->count() > 0)
                    @foreach (($reviewDebts ?? collect()) as $debt)
                        <div style="display:flex; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px solid #e2e8f0; font-size:14px; color:#334155;">
                            <span>
                                {{ $leadPortalDebtPresenter->customerFacingCreditorName($debt) }}
                                <span style="display:block; color:#64748b; font-size:12px;">
                                    {{ $debt->source_expected === 'customer_added' ? 'Added by you' : 'Credit check' }}
                                </span>
                            </span>
                            <strong>{{ $debt->balance !== null ? '£'.number_format((float) $debt->balance, 2) : 'No balance provided' }}</strong>
                        </div>
                    @endforeach
                    <div style="display:flex; justify-content:space-between; gap:12px; padding-top:12px; font-size:15px; color:#0f172a;">
                        <strong>Total debt</strong>
                        <strong>{{ '£'.number_format((float) ($reviewDebtTotal ?? 0), 2) }}</strong>
                    </div>
                @else
                    <div style="font-size:14px; color:#64748b;">We don&rsquo;t have any debt balances to show yet.</div>
                @endif
            </div>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:12px;">
                <div style="font-weight:700; margin-bottom:8px;">What this could mean</div>
                @if (($ivaEstimate['is_eligible'] ?? false) === true)
                    <div style="font-size:14px; color:#334155; margin-bottom:6px;">
                        Estimated total debt: {{ '£'.number_format((float) ($ivaEstimate['total_debt'] ?? 0), 2) }}
                    </div>
                    <div style="font-size:14px; color:#334155; margin-bottom:6px;">
                        Example repayment total: £6,000.00
                    </div>
                    <div style="font-size:14px; color:#334155; margin-bottom:6px;">
                        Potential write-off: {{ '£'.number_format((float) ($ivaEstimate['estimated_write_off'] ?? 0), 2) }}
                    </div>
                    <div style="font-size:14px; color:#475569;">
                        Based on the figures so far, an IVA could be worth exploring and may reduce what you repay, subject to assessment.
                    </div>
                @else
                    <div style="font-size:14px; color:#475569;">
                        Based on the figures so far, an IVA may not be the best fit, but we can still help discuss your options.
                    </div>
                @endif

                @if (($ivaEstimate['has_court_judgment_debt'] ?? false) === true)
                    <div style="margin-top:10px; padding:12px; border-radius:12px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; font-size:14px;">
                        We&rsquo;ve also seen court judgment information, so it may be important to get advice before enforcement escalates.
                    </div>
                @endif
            </div>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:20px;">
                <div style="font-weight:700; margin-bottom:8px;">Monthly picture</div>
                <div style="font-size:14px; color:#334155;">Employment status: {{ $lead->employment_status ?: 'Not provided' }}</div>
                <div style="font-size:14px; color:#334155;">Monthly income: {{ $reviewMoney['monthly_income'] ?? 'Not provided' }}</div>
                <div style="font-size:14px; color:#334155;">Rent or mortgage: {{ $reviewMoney['monthly_housing_cost'] ?? 'Not provided' }}</div>
                <div style="font-size:14px; color:#334155;">Council tax: {{ $reviewMoney['monthly_council_tax'] ?? 'Not provided' }}</div>
                <div style="font-size:14px; color:#334155;">Utilities: {{ $reviewMoney['monthly_utilities_cost'] ?? 'Not provided' }}</div>
                <div style="font-size:14px; color:#334155;">Food and travel: {{ $reviewMoney['monthly_food_travel_cost'] ?? 'Not provided' }}</div>
            </div>

            <form method="POST" action="{{ route('portal.review.finish', ['token' => $rawToken]) }}">
                @csrf
                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Continue
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'complete_pending')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Complete pending
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                You&rsquo;re all set
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                We&rsquo;ve saved your summary and securely closed this session.
            </p>

            <form method="POST" action="{{ route('portal.complete', ['token' => $rawToken]) }}">
                @csrf
                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Finish
                </button>
            </form>
        @elseif (($progress->current_step ?? 'welcome') === 'credit_check_failed')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Credit check
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                We couldn&rsquo;t complete the check
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                Something went wrong while checking your information. You can try again now.
            </p>

            <form method="POST" action="{{ route('portal.credit-check.v3.start', ['token' => $rawToken]) }}">
                @csrf
                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Try again
                </button>
            </form>
        @else
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#fef3c7; color:#92400e; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Next step
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Your next step is coming soon
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:17px; line-height:1.6;">
                You&rsquo;ve completed welcome. We&rsquo;re preparing the next step now.
            </p>

            <div style="padding:16px 18px; border-radius:14px; border:1px solid #e2e8f0; background:#f8fafc; color:#334155;">
                Current step: <strong>{{ $progress->current_step }}</strong>
            </div>
        @endif
    </div>
</div>
</body>
</html>
