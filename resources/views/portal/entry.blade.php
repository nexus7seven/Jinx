@extends('portal.layout')

@section('title', 'Secure Portal')

@section('content')
@php
    $currentStep = (string) ($progress->current_step ?? 'welcome');
    $stepOrder = ['welcome','details','debts','income','costs','credit_check','credit_check_running','credit_check_questions','credit_report_debts','add_missing_debts','iva_results','review','completed'];
    $stepIndex = array_search($currentStep, $stepOrder, true);
    $stepIndex = $stepIndex === false ? 0 : $stepIndex;
    $progressPercent = (int) round((($stepIndex + 1) / count($stepOrder)) * 100);
@endphp

<div style="max-width:760px; margin:0 auto;">
    <div style="margin-bottom:14px; padding:12px 14px; border-radius:14px; border:1px solid #dbeafe; background:#eff6ff;">
        <div style="display:flex; justify-content:space-between; font-size:12px; font-weight:700; color:#1e3a8a; margin-bottom:8px;"><span>Your progress</span><span>{{ $progressPercent }}%</span></div>
        <div style="height:8px; border-radius:999px; background:#dbeafe; overflow:hidden;"><div style="height:8px; width: {{ $progressPercent }}%; background:#2563eb;"></div></div>
    </div>
    <div class="portal-card">
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
            @include('portal.partials.help-cta')
        @elseif (($progress->current_step ?? 'welcome') === 'add_missing_debts')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Missing debts
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Is anything missing?
            </h1>

            <p style="margin:0 0 18px 0; color:#475569; font-size:16px; line-height:1.6;">
                Here&rsquo;s what we found. If anything is missing, add it below so we can build the most accurate picture possible.
            </p>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:12px; margin-bottom:16px; background:#f8fafc;">
                <div style="font-weight:700; margin-bottom:8px;">What we found on your credit file</div>
                <div style="display:grid; grid-template-columns:repeat(3,minmax(110px,1fr)); gap:8px; margin-bottom:10px;">
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px;">
                        <div style="font-size:11px; color:#64748b;">Total found</div>
                        <div style="font-size:16px; font-weight:700;">{{ '£'.number_format((float) ($creditCheckTotal ?? 0), 2) }}</div>
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px;">
                        <div style="font-size:11px; color:#64748b;">Accounts</div>
                        <div style="font-size:16px; font-weight:700;">{{ (int) ($creditCheckCount ?? 0) }}</div>
                    </div>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px;">
                        <div style="font-size:11px; color:#64748b;">CCJs</div>
                        <div style="font-size:16px; font-weight:700;">{{ (int) ($creditCheckCcjCount ?? 0) }}</div>
                    </div>
                </div>
                <div style="max-height:190px; overflow:auto; border:1px solid #e2e8f0; border-radius:10px; background:#fff;">
                    @forelse (($creditCheckDebts ?? collect())->take(10) as $debt)
                        <div style="display:grid; grid-template-columns:1.3fr .8fr 1fr; gap:8px; padding:8px 10px; border-bottom:1px solid #f1f5f9; font-size:12px;">
                            <div>
                                <div style="font-weight:600; color:#0f172a;">{{ $leadPortalDebtPresenter->customerFacingCreditorName($debt) }}</div>
                                <div style="color:#64748b;">{{ $debt->creditor?->name }}</div>
                            </div>
                            <div style="font-weight:700; color:#0f172a;">{{ $debt->balance !== null ? '£'.number_format((float) $debt->balance, 2) : '—' }}</div>
                            <div style="color:#64748b;">{{ $debt->reference ?: '—' }}</div>
                        </div>
                    @empty
                        <div style="padding:10px; font-size:13px; color:#64748b;">No credit-file balances were found automatically.</div>
                    @endforelse
                </div>
            </div>

            @if (($creditCheckCcjCount ?? 0) > 0)
                @php
                    $portalWhatsAppUrl = config('services.portal.whatsapp_url');
                    $portalCallUrl = config('services.portal.call_url');
                @endphp
                <div style="margin:0 0 16px 0; padding:14px 16px; border-radius:14px; border:1px solid #fecaca; background:#fff1f2; color:#7f1d1d;">
                    <div style="font-weight:700; margin-bottom:6px;">Important: County Court Judgments detected</div>
                    <div style="font-size:14px; line-height:1.6;">
                        CCJs can lead to serious enforcement, including High Court writ enforcement and bailiff action. In some cases, bailiffs may be able to force entry to remove goods.
                    </div>
                    <div style="margin-top:8px; font-size:14px; font-weight:600;">
                        Not sure who your CCJ is with? We can help you find the exact creditor and balance.
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                        @if(!blank($portalWhatsAppUrl))
                            <a href="{{ $portalWhatsAppUrl }}" style="display:inline-block; text-decoration:none; padding:9px 12px; border-radius:10px; background:#16a34a; color:#fff; font-size:13px; font-weight:700;">Message us about my results</a>
                        @endif
                        @if(!blank($portalCallUrl))
                            <a href="{{ $portalCallUrl }}" style="display:inline-block; text-decoration:none; padding:9px 12px; border-radius:10px; background:#1d4ed8; color:#fff; font-size:13px; font-weight:700;">Talk through my options</a>
                        @endif
                    </div>
                </div>
            @endif

            @if ($errors->has('missing_debts'))
                <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:14px; font-weight:700;">
                    {{ $errors->first('missing_debts') }}
                </div>
            @endif

            <form method="POST" action="{{ route('portal.missing-debts.save', ['token' => $rawToken]) }}">
                @csrf
                @php
                    $oldRows = old('debts');
                    $initialRows = is_array($oldRows) ? array_values($oldRows) : [[], [], []];
                    if (count($initialRows) < 3) {
                        $initialRows = array_pad($initialRows, 3, []);
                    }
                @endphp

                <div id="missing-debts-rows"
                     data-creditors='@json((($creditors ?? collect())->map(fn ($c) => ["id" => (string) $c->id, "name" => $c->name])->values()->all()))'>
                    @foreach($initialRows as $index => $row)
                        @php
                            $selectedCreditorId = (string) ($row['creditor_id'] ?? '');
                            $selectedCreditor = ($creditors ?? collect())->firstWhere('id', (int) $selectedCreditorId);
                            $selectedCreditorLabel = $selectedCreditorId === 'other'
                                ? 'Other'
                                : ($selectedCreditor?->name ?? '');
                        @endphp
                        <div class="missing-debt-row" data-row-index="{{ $index }}" style="border:1px solid #e2e8f0; border-radius:12px; padding:10px; margin-bottom:10px;">
                            <div style="display:grid; grid-template-columns:1.5fr .8fr 1.2fr; gap:10px; align-items:start;">
                                <div style="position:relative;">
                                    <label style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Creditor</label>
                                    <input type="hidden" name="debts[{{ $index }}][creditor_id]" value="{{ $selectedCreditorId }}" class="missing-debt-creditor-id">
                                    <input type="text"
                                           value="{{ $selectedCreditorLabel }}"
                                           class="missing-debt-creditor-search"
                                           placeholder="Start typing creditor name..."
                                           autocomplete="off"
                                           style="width:100%; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                                    <div class="missing-debt-creditor-results" style="display:none; position:absolute; z-index:5; top:74px; left:0; right:0; border:1px solid #cbd5e1; border-radius:10px; background:#ffffff; max-height:180px; overflow:auto; box-shadow:0 8px 18px rgba(15,23,42,.08);"></div>
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Balance</label>
                                    <input name="debts[{{ $index }}][balance]" type="text" inputmode="decimal"
                                           value="{{ $row['balance'] ?? '' }}"
                                           style="width:100%; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Other creditor name (optional)</label>
                                    <input name="debts[{{ $index }}][creditor_name]" type="text"
                                           value="{{ $row['creditor_name'] ?? '' }}"
                                           style="width:100%; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                                    <div style="margin-top:6px; font-size:12px; color:#64748b;">Only use this if you can&rsquo;t find the creditor in the search.</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <button type="button" id="add-missing-debt-row"
                        style="display:inline-block; margin-bottom:14px; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; background:#ffffff; color:#0f172a; font-size:14px; font-weight:700; cursor:pointer;">
                    Add another debt
                </button>
                <template id="missing-debt-row-template">
                    <div class="missing-debt-row" data-row-index="__INDEX__" style="border:1px solid #e2e8f0; border-radius:12px; padding:10px; margin-bottom:10px;">
                        <div style="display:grid; grid-template-columns:1.5fr .8fr 1.2fr; gap:10px; align-items:start;">
                            <div style="position:relative;">
                                <label style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Creditor</label>
                                <input type="hidden" name="debts[__INDEX__][creditor_id]" value="" class="missing-debt-creditor-id">
                                <input type="text" value="" class="missing-debt-creditor-search" placeholder="Start typing creditor name..." autocomplete="off"
                                       style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                                <div class="missing-debt-creditor-results" style="display:none; position:absolute; z-index:5; top:74px; left:0; right:0; border:1px solid #cbd5e1; border-radius:10px; background:#ffffff; max-height:180px; overflow:auto; box-shadow:0 8px 18px rgba(15,23,42,.08);"></div>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Balance</label>
                                <input name="debts[__INDEX__][balance]" type="text" inputmode="decimal"
                                       style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Other creditor name (optional)</label>
                                <input name="debts[__INDEX__][creditor_name]" type="text"
                                       style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a;">
                                <div style="margin-top:6px; font-size:12px; color:#64748b;">Only use this if you can&rsquo;t find the creditor in the search.</div>
                            </div>
                        </div>
                    </div>
                </template>

                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Continue
                </button>
            </form>
            @include('portal.partials.help-cta')
            <script>
                (function () {
                    const container = document.getElementById('missing-debts-rows');
                    const addBtn = document.getElementById('add-missing-debt-row');
                    const template = document.getElementById('missing-debt-row-template');
                    if (!container || !addBtn || !template) return;
                    const creditors = JSON.parse(container.dataset.creditors || '[]');
                    const options = creditors.concat([{id: 'other', name: 'Other'}]);

                    function renderResults(row, query) {
                        const results = row.querySelector('.missing-debt-creditor-results');
                        if (!results) return;
                        const q = (query || '').toLowerCase().trim();
                        const matches = q === '' ? options.slice(0, 8) : options.filter((o) => o.name.toLowerCase().includes(q)).slice(0, 8);
                        if (matches.length === 0) {
                            results.style.display = 'none';
                            results.innerHTML = '';
                            return;
                        }
                        results.innerHTML = matches.map((o) => '<button type="button" data-id="' + o.id + '" data-name="' + o.name.replace(/"/g, '&quot;') + '" style="display:block;width:100%;text-align:left;border:none;background:#fff;padding:10px 12px;cursor:pointer;">' + o.name + '</button>').join('');
                        results.style.display = 'block';
                    }

                    function bindRow(row) {
                        const search = row.querySelector('.missing-debt-creditor-search');
                        const hidden = row.querySelector('.missing-debt-creditor-id');
                        const results = row.querySelector('.missing-debt-creditor-results');
                        if (!search || !hidden || !results) return;
                        search.addEventListener('input', function () {
                            hidden.value = '';
                            renderResults(row, search.value);
                        });
                        search.addEventListener('focus', function () {
                            renderResults(row, search.value);
                        });
                        results.addEventListener('click', function (event) {
                            const target = event.target;
                            if (!(target instanceof HTMLButtonElement)) return;
                            hidden.value = target.dataset.id || '';
                            search.value = target.dataset.name || '';
                            results.style.display = 'none';
                        });
                        document.addEventListener('click', function (event) {
                            if (!row.contains(event.target)) {
                                results.style.display = 'none';
                            }
                        });
                    }

                    Array.from(container.querySelectorAll('.missing-debt-row')).forEach(bindRow);
                    addBtn.addEventListener('click', function () {
                        const index = container.querySelectorAll('.missing-debt-row').length;
                        const html = template.innerHTML.replaceAll('__INDEX__', String(index));
                        const wrapper = document.createElement('div');
                        wrapper.innerHTML = html.trim();
                        const row = wrapper.firstElementChild;
                        if (!row) return;
                        container.appendChild(row);
                        bindRow(row);
                    });
                })();
            </script>
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
                @if(($ivaEstimate['has_meaningful_write_off'] ?? false) === true)
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
                        Seeing all your debts together can feel overwhelming, but this is often the moment things start to get fixed.
                    </p>
                    <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                        We may be able to help stop creditor pressure quickly and, depending on your circumstances, help stop or prevent bailiff and enforcement action.
                    </p>
                    <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                        We may also be able to help with Universal Credit deductions, including getting deductions reviewed, reduced, or removed where appropriate.
                    </p>
                    <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                        The benefit is not just possible debt write-off. It is getting control back, having one affordable payment, and reducing day-to-day pressure. Some solutions can be based around affordable monthly payments, sometimes from around £100 per month, subject to assessment.
                    </p>
                @endif
            @else
                <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                    Based on the figures so far, an IVA may not be the best fit, but we can still help you understand your options.
                </p>
            @endif

            @if (($ivaEstimate['has_court_judgment_debt'] ?? false) === true)
                <div style="margin:14px 0 18px 0; padding:14px 16px; border-radius:14px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; font-size:14px; line-height:1.6;">
                    If court action or bailiffs are a concern, it&rsquo;s important to speak to someone quickly. An approved solution may help stop further enforcement.
                </div>
            @endif

            <form method="POST" action="{{ route('portal.iva-results.continue', ['token' => $rawToken]) }}">
                @csrf
                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Continue to summary
                </button>
            </form>
            @include('portal.partials.help-cta')
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
                    @if(($ivaEstimate['has_meaningful_write_off'] ?? false) === true)
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
                            The benefit is not just possible debt write-off. It is getting control back, reducing creditor pressure, and moving to one affordable payment. Some solutions can be based around affordable monthly payments, sometimes from around £100 per month, subject to assessment.
                        </div>
                    @endif
                @else
                    <div style="font-size:14px; color:#475569;">
                        Based on the figures so far, an IVA may not be the best fit, but we can still help discuss your options.
                    </div>
                @endif

                @if (($ivaEstimate['has_court_judgment_debt'] ?? false) === true)
                    <div style="margin-top:10px; padding:12px; border-radius:12px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; font-size:14px;">
                        If court action or bailiffs are a concern, it&rsquo;s important to speak to someone quickly. An approved solution may help stop further enforcement.
                    </div>
                @endif
            </div>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:12px;">
                <div style="font-weight:700; margin-bottom:8px;">What happens next?</div>
                <div style="font-size:14px; color:#334155; line-height:1.6;">
                    We&rsquo;ve emailed your summary so you have a copy of your results.
                </div>
                <div style="font-size:14px; color:#334155; line-height:1.6;">
                    You can continue online by adding any missing debts and completing the remaining details.
                </div>
                <div style="font-size:14px; color:#334155; line-height:1.6;">
                    If you&rsquo;d like to discuss the results now, message us and we can talk through your options straight away.
                </div>
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
                    Continue online
                </button>
            </form>
            @php
                $portalWhatsAppUrl = config('services.portal.whatsapp_url');
                $portalCallUrl = config('services.portal.call_url');
            @endphp
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
                @if(!blank($portalWhatsAppUrl))
                    <a href="{{ $portalWhatsAppUrl }}" style="display:inline-block; text-decoration:none; padding:10px 14px; border-radius:10px; background:#16a34a; color:#fff; font-size:14px; font-weight:700;">Message us about my results</a>
                @endif
                @if(!blank($portalCallUrl))
                    <a href="{{ $portalCallUrl }}" style="display:inline-block; text-decoration:none; padding:10px 14px; border-radius:10px; background:#1d4ed8; color:#fff; font-size:14px; font-weight:700;">Talk through my options</a>
                @endif
            </div>
            @include('portal.partials.help-cta')
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
            @include('portal.partials.help-cta')
        @elseif (($progress->current_step ?? 'welcome') === 'credit_check_failed')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Credit check
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                We couldn&rsquo;t complete the check
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                {{ $portalCreditCheckFailureMessage }}
            </p>
            <div style="margin:0 0 12px 0; color:#475569; font-size:14px;">
                Attempts used: {{ $portalCreditCheckAttemptsUsed }} / {{ $portalCreditCheckAttemptsMax }}
            </div>

            @if($portalCreditCheckLatestLog && $portalCreditCheckLatestLog->isActive())
                <div style="padding:12px 14px; border-radius:12px; border:1px solid #dbeafe; background:#eff6ff; color:#1e3a8a; font-size:14px;">
                    We&rsquo;re still checking your details. Please stay on this page while we continue.
                </div>
            @else
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="{{ route('portal.entry', ['token' => $rawToken, 'edit_details' => 1]) }}"
                       style="display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">
                        Check details
                    </a>
                    @if($portalCreditCheckCanRetry)
                        <form method="POST" action="{{ route('portal.credit-check.v3.start', ['token' => $rawToken]) }}">
                            @csrf
                            <button type="submit"
                                    style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                                Try again
                            </button>
                        </form>
                    @else
                        <div style="padding:12px 14px; border-radius:12px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; font-size:14px;">
                            Sorry, our system couldn’t find your credit report automatically. You can still continue by listing any debts you know about below.
                        </div>
                    @endif
                </div>
            @endif
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
@endsection
