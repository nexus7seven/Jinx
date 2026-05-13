@extends('portal.layout')

@section('title', 'Secure Portal')

@section('content')
@php
    $currentStep = trim((string) ($progress->current_step ?? 'welcome'));
    $stepOrder = ['welcome','details','debts','income','costs','credit_check','credit_check_running','credit_check_questions','credit_report_debts','add_missing_debts','iva_results','review','completed'];
    $stepIndex = array_search($currentStep, $stepOrder, true);
    $stepIndex = $stepIndex === false ? 0 : $stepIndex;
    $progressPercent = (int) round((($stepIndex + 1) / count($stepOrder)) * 100);
    $backStepMap = [
        'details' => 'welcome',
        'debts' => 'details',
        'income' => 'debts',
        'costs' => 'income',
        'credit_check' => 'costs',
        'credit_check_running' => 'costs',
        'credit_check_questions' => 'costs',
        'credit_report_debts' => 'costs',
        'add_missing_debts' => 'credit_report_debts',
        'iva_results' => 'add_missing_debts',
        'review' => 'iva_results',
        'complete_pending' => 'review',
    ];
    $backTargetStep = $backStepMap[$currentStep] ?? null;
@endphp

<div style="max-width:760px; margin:0 auto;">
    @if (!empty($isDemoMode) && auth()->check())
        <div style="display:inline-block; margin-bottom:10px; padding:4px 10px; border-radius:999px; background:#ede9fe; color:#5b21b6; font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
            Demo mode (internal test data)
        </div>
    @endif
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
        @if ($currentStep === 'welcome')
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
        @elseif ($currentStep === 'details')
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
                @if ($backTargetStep)
                    <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="margin-left:8px; display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
                @endif
            </form>
        @elseif ($currentStep === 'debts')
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
                <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="margin-left:8px; display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
            </form>
        @elseif ($currentStep === 'income')
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
                <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="margin-left:8px; display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
            </form>
        @elseif ($currentStep === 'costs')
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
                <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="margin-left:8px; display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
            </form>
        @elseif ($currentStep === 'credit_check')
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

            <form method="POST" action="{{ route('portal.credit-check.v3.start', ['token' => $rawToken]) }}" data-credit-check-form>
                @csrf
                <button type="submit" data-credit-check-submit data-running-label="Check running…"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Start check
                </button>
                <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="margin-left:8px; display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
                @include('portal.partials.credit-check-running')
            </form>
        @elseif ($currentStep === 'credit_check_running')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Credit check
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                We&rsquo;re checking your information
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                This can take a few moments. You may be asked a few security questions to confirm your identity. Please stay on this page.
            </p>

            <div id="portal-credit-check-status"
                 style="padding:14px 16px; border-radius:12px; border:1px solid #e2e8f0; background:#f8fafc; color:#334155;">
                We&rsquo;ll refresh this page as soon as your check is ready or if we need confirmation from you.
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
        @elseif ($currentStep === 'credit_check_questions')
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
        @elseif ($currentStep === 'credit_report_debts')
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
                <div style="display:flex; gap:10px; margin-top:18px;">
                    <button type="submit"
                            style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                        Continue
                    </button>
                    <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
                </div>
            </form>
            @include('portal.partials.help-cta')
        @elseif ($currentStep === 'add_missing_debts')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Missing debts
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                Here&rsquo;s what we found on your report
            </h1>

            @if (($creditCheckCcjCount ?? 0) > 0)
                @php
                    $portalWhatsAppUrl = config('services.portal.whatsapp_url');
                    $portalCallUrl = config('services.portal.call_url');
                    $ccjMessage = rawurlencode('I have CCJs on my credit report and I need help finding who they are with');
                    $ccjWhatsAppUrl = blank($portalWhatsAppUrl) ? null : ($portalWhatsAppUrl.(str_contains($portalWhatsAppUrl, '?') ? '&' : '?').'text='.$ccjMessage);
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
                        @if(!blank($ccjWhatsAppUrl))
                            <a href="{{ $ccjWhatsAppUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; text-decoration:none; padding:9px 12px; border-radius:10px; background:#16a34a; color:#fff; font-size:13px; font-weight:700;">Message us about my results</a>
                        @endif
                        @if(!blank($portalCallUrl))
                            <a href="{{ $portalCallUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block; text-decoration:none; padding:9px 12px; border-radius:10px; background:#1d4ed8; color:#fff; font-size:13px; font-weight:700;">Talk through my options</a>
                        @endif
                    </div>
                </div>
            @endif

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

            @if ($errors->has('missing_debts'))
                <div style="margin-bottom:18px; padding:14px 16px; border-radius:16px; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:14px; font-weight:700;">
                    {{ $errors->first('missing_debts') }}
                </div>
            @endif

            <form method="POST" action="{{ route('portal.missing-debts.save', ['token' => $rawToken]) }}">
                @csrf
                @php
                    $oldRows = old('missing_debts', old('debts'));
                    $initialRows = is_array($oldRows) ? array_values($oldRows) : [];
                @endphp

                <h2 style="margin:8px 0 8px 0; font-size:28px; line-height:1.2; color:#0f172a;">Is anything missing?</h2>
                <p style="margin:0 0 16px 0; color:#475569; font-size:16px; line-height:1.6;">
                    If anything is missing, add it below so we can complete the picture.
                </p>

                <button type="button" id="add-missing-debt-row"
                        style="display:inline-block; margin:0 0 18px 0; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; background:#ffffff; color:#0f172a; font-size:14px; font-weight:700; cursor:pointer;">
                    Add creditor
                </button>
                <div style="border:1px solid #e2e8f0; border-radius:14px; padding:12px; margin-bottom:16px; background:#f8fafc;">
                    <div style="font-weight:700; margin-bottom:8px;">Debts you&rsquo;ve added</div>
                    <div id="missing-debts-added-list" style="display:grid; gap:8px;"></div>
                    <div id="missing-debts-empty" style="font-size:13px; color:#64748b;">No extra debts added yet.</div>
                    <div id="missing-debts-hidden-inputs"></div>
                </div>

                <div id="missing-debt-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="missing-debt-modal-title"
                     style="display:none; position:fixed; inset:0; z-index:60; background:rgba(15,23,42,.55); padding:16px;">
                    <div style="max-width:560px; width:100%; margin:6vh auto 0; background:#fff; border-radius:14px; border:1px solid #dbeafe; padding:16px;">
                        <h3 id="missing-debt-modal-title" style="margin:0 0 8px 0; font-size:22px;">Add missing creditor</h3>
                        <p style="margin:0 0 10px 0; color:#475569; font-size:14px;">Start typing to search for your creditor.</p>
                        <p style="margin:0 0 14px 0; color:#64748b; font-size:13px;">Can&rsquo;t find it? Choose Other.</p>
                        <div style="position:relative; margin-bottom:12px;">
                            <label for="missing-debt-creditor-search" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Creditor</label>
                            <input id="missing-debt-creditor-search" type="text" autocomplete="off" placeholder="Start typing creditor name..."
                                   style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#fff;">
                            <input id="missing-debt-creditor-id" type="hidden" value="">
                            <div id="missing-debt-creditor-results" style="display:none; position:absolute; z-index:5; top:74px; left:0; right:0; border:1px solid #cbd5e1; border-radius:10px; background:#ffffff; max-height:180px; overflow:auto; box-shadow:0 8px 18px rgba(15,23,42,.08);"></div>
                        </div>
                        <div style="margin-bottom:12px;">
                            <label for="missing-debt-balance" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Balance</label>
                            <input id="missing-debt-balance" type="text" inputmode="decimal" style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#fff;">
                        </div>
                        <div id="missing-debt-other-wrap" style="display:none; margin-bottom:12px;">
                            <label for="missing-debt-other-name" style="display:block; margin-bottom:6px; font-size:14px; color:#334155;">Other creditor name</label>
                            <input id="missing-debt-other-name" type="text" style="width:100%; box-sizing:border-box; padding:12px 14px; border-radius:12px; border:1px solid #cbd5e1; background:#fff;">
                        </div>
                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                            <button type="button" id="missing-debt-cancel-btn" style="padding:12px 16px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; font-weight:700;">Cancel</button>
                            <button type="button" id="missing-debt-save-btn" style="padding:12px 16px; border:none; border-radius:10px; background:#1d4ed8; color:#fff; font-weight:700;">Add debt</button>
                        </div>
                    </div>
                </div>

                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Continue
                </button>
                <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="margin-left:8px; display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
            </form>
            @include('portal.partials.help-cta')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const addBtn = document.getElementById('add-missing-debt-row');
                    const modal = document.getElementById('missing-debt-modal');
                    const saveBtn = document.getElementById('missing-debt-save-btn');
                    const cancelBtn = document.getElementById('missing-debt-cancel-btn');
                    const search = document.getElementById('missing-debt-creditor-search');
                    const creditorIdInput = document.getElementById('missing-debt-creditor-id');
                    const results = document.getElementById('missing-debt-creditor-results');
                    const balance = document.getElementById('missing-debt-balance');
                    const otherWrap = document.getElementById('missing-debt-other-wrap');
                    const otherName = document.getElementById('missing-debt-other-name');
                    const list = document.getElementById('missing-debts-added-list');
                    const empty = document.getElementById('missing-debts-empty');
                    const hiddenInputs = document.getElementById('missing-debts-hidden-inputs');
                    if (!addBtn || !modal || !saveBtn || !cancelBtn || !search || !creditorIdInput || !results || !balance || !otherWrap || !otherName || !list || !empty || !hiddenInputs) return;

                    const creditors = @json((($creditors ?? collect())->map(fn ($c) => ["id" => (string) $c->id, "name" => $c->name])->values()->all());
                    const options = creditors.concat([{id: 'other', name: 'Other'}]);
                    const debts = @json(array_map(function ($row) use ($creditors) {
                        $creditorId = (string) ($row['creditor_id'] ?? '');
                        $selectedCreditor = ($creditors ?? collect())->firstWhere('id', (int) $creditorId);
                        return [
                            'creditor_id' => $creditorId,
                            'creditor_name' => $creditorId === 'other' ? 'Other' : ($selectedCreditor?->name ?? ''),
                            'balance' => (string) ($row['balance'] ?? ''),
                            'other_name' => (string) ($row['other_name'] ?? ($row['creditor_name'] ?? '')),
                        ];
                    }, $initialRows));
                    let editIndex = null;

                    function closeModal() {
                        modal.style.display = 'none';
                        modal.setAttribute('aria-hidden', 'true');
                    }
                    function openModal() {
                        modal.style.display = 'block';
                        modal.setAttribute('aria-hidden', 'false');
                        search.focus();
                    }
                    function resetModal() {
                        editIndex = null;
                        creditorIdInput.value = '';
                        search.value = '';
                        balance.value = '';
                        otherName.value = '';
                        otherWrap.style.display = 'none';
                        saveBtn.textContent = 'Add debt';
                        results.style.display = 'none';
                        results.innerHTML = '';
                    }
                    function renderResults(query) {
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

                    function renderDebts() {
                        list.innerHTML = '';
                        hiddenInputs.innerHTML = '';
                        debts.forEach(function (debt, index) {
                            const name = debt.creditor_id === 'other' ? (debt.other_name || 'Other') : debt.creditor_name;
                            const card = document.createElement('div');
                            card.style.cssText = 'border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#fff; display:flex; justify-content:space-between; gap:10px; align-items:center;';
                            card.innerHTML = '<div><div style="font-weight:700; color:#0f172a;">' + name + '</div><div style="font-size:13px; color:#475569;">£' + debt.balance + '</div></div><div style="display:flex; gap:8px;"><button type="button" data-edit="' + index + '" style="padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff;">Edit</button><button type="button" data-delete="' + index + '" style="padding:8px 10px; border:1px solid #fecaca; color:#b91c1c; border-radius:8px; background:#fff;">Delete</button></div>';
                            list.appendChild(card);
                            [
                                { name: 'missing_debts[' + index + '][creditor_id]', value: debt.creditor_id },
                                { name: 'missing_debts[' + index + '][balance]', value: debt.balance },
                                { name: 'missing_debts[' + index + '][other_name]', value: debt.other_name || '' }
                            ].forEach(function (field) {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = field.name;
                                input.value = field.value;
                                hiddenInputs.appendChild(input);
                            });
                        });
                        empty.style.display = debts.length === 0 ? '' : 'none';
                    }

                    addBtn.addEventListener('click', function () { resetModal(); openModal(); });
                    cancelBtn.addEventListener('click', function () { closeModal(); });
                    modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(); });
                    search.addEventListener('focus', function () { renderResults(search.value); });
                    search.addEventListener('input', function () { creditorIdInput.value = ''; otherWrap.style.display = 'none'; renderResults(search.value); });
                    results.addEventListener('click', function (event) {
                        const target = event.target;
                        if (!(target instanceof HTMLButtonElement)) return;
                        creditorIdInput.value = target.dataset.id || '';
                        search.value = target.dataset.name || '';
                        results.style.display = 'none';
                        otherWrap.style.display = creditorIdInput.value === 'other' ? '' : 'none';
                    });
                    saveBtn.addEventListener('click', function () {
                        const creditorId = creditorIdInput.value.trim();
                        const creditorName = search.value.trim();
                        const amount = balance.value.trim();
                        const manualName = otherName.value.trim();
                        if (!creditorId || !amount || (creditorId === 'other' && manualName === '')) return;
                        const row = { creditor_id: creditorId, creditor_name: creditorName, balance: amount, other_name: manualName };
                        if (editIndex === null) debts.push(row); else debts[editIndex] = row;
                        renderDebts();
                        closeModal();
                    });
                    list.addEventListener('click', function (event) {
                        const target = event.target;
                        if (!(target instanceof HTMLButtonElement)) return;
                        if (target.dataset.delete !== undefined) {
                            debts.splice(parseInt(target.dataset.delete, 10), 1);
                            renderDebts();
                        }
                        if (target.dataset.edit !== undefined) {
                            const idx = parseInt(target.dataset.edit, 10);
                            const debt = debts[idx];
                            if (!debt) return;
                            editIndex = idx;
                            creditorIdInput.value = debt.creditor_id;
                            search.value = debt.creditor_name;
                            balance.value = debt.balance;
                            otherName.value = debt.other_name || '';
                            otherWrap.style.display = debt.creditor_id === 'other' ? '' : 'none';
                            saveBtn.textContent = 'Save changes';
                            openModal();
                        }
                    });
                    document.addEventListener('click', function (event) {
                        if (!modal.contains(event.target) && event.target !== addBtn) results.style.display = 'none';
                    });
                    renderDebts();
                });
            </script>
        @elseif ($currentStep === 'iva_results')
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

            <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                Based on what you&rsquo;ve shared, we can now help you move this forward with tailored support.
            </p>
            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin:0 0 14px 0; background:#f8fafc;">
                <div style="font-weight:700; margin-bottom:8px;">How we may be able to help</div>
                <ul style="margin:0; padding-left:18px; color:#475569; font-size:15px; line-height:1.7;">
                    <li>Help stop creditor pressure by dealing with creditors directly.</li>
                    <li>Help stop bailiff or enforcement action, depending on your circumstances.</li>
                    <li>Review Universal Credit deductions and help reduce or remove them where appropriate.</li>
                    <li>Reduce multiple payments into one affordable monthly payment where suitable.</li>
                </ul>
            </div>
            @if (($ivaEstimate['has_meaningful_write_off'] ?? false) === true)
                <p style="margin:0 0 10px 0; color:#475569; font-size:16px; line-height:1.6;">
                    For example, if payments were around £100 per month for 60 months, that would total £6,000. Compared with {{ '£'.number_format((float) ($ivaEstimate['total_debt'] ?? 0), 2) }}, around {{ '£'.number_format((float) ($ivaEstimate['estimated_write_off'] ?? 0), 2) }} may be written off, depending on your final assessment.
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
                <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="margin-left:8px; display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
            </form>
            @include('portal.partials.help-cta')
        @elseif ($currentStep === 'review')
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

            @if ((float) ($ivaEstimate['total_debt'] ?? 0) >= 3000)
                <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:18px;">
                    <div style="font-weight:700; margin-bottom:8px;">What this could mean</div>
                    <div style="font-size:14px; color:#475569; margin-bottom:8px;">We may be able to help you:</div>
                    <ul style="margin:0; padding-left:18px; color:#475569; font-size:14px; line-height:1.7;">
                        <li>Stop creditor pressure</li>
                        <li>Prevent enforcement or bailiff action (depending on circumstances)</li>
                        <li>Reduce multiple payments into one affordable monthly amount</li>
                        <li>Deal with creditors on your behalf</li>
                    </ul>
                </div>
            @endif

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:18px;">
                <div style="font-weight:700; margin-bottom:8px;">What happens next?</div>
                <div style="font-size:14px; color:#334155; line-height:1.6;">
                    You can have your summary emailed to you or speak to us now to go through your options.
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
                @php
                    $portalWhatsAppUrl = config('services.portal.whatsapp_url');
                    $reviewHasCcj = ($reviewDebts ?? collect())->contains(function ($debt): bool {
                        $creditorName = (string) ($debt->creditor?->name ?? '');
                        $reference = (string) ($debt->reference ?? '');
                        return stripos($creditorName, 'County Court Judgment') !== false
                            || stripos($reference, 'County Court Judgment') !== false
                            || stripos($reference, 'CCJ') !== false;
                    });
                    if ($reviewHasCcj) {
                        $reviewWhatsAppMessage = rawurlencode('I’ve seen I have a CCJ and I need help understanding what to do next');
                    } elseif ((float) ($reviewDebtTotal ?? 0) >= 5000) {
                        $reviewWhatsAppMessage = rawurlencode('I’ve just completed my debt summary and want to understand my options');
                    } else {
                        $reviewWhatsAppMessage = rawurlencode('I’d like some advice on my debts and what I can do next');
                    }
                    $reviewWhatsAppUrl = blank($portalWhatsAppUrl) ? null : ($portalWhatsAppUrl.(str_contains($portalWhatsAppUrl, '?') ? '&' : '?').'text='.$reviewWhatsAppMessage);
                @endphp
                <div style="border:1px solid #dbeafe; border-radius:14px; padding:14px; margin:0 0 14px 0; background:#eff6ff;">
                    <div style="font-size:15px; color:#0f172a; font-weight:700; margin-bottom:6px;">You&rsquo;ve now got a clear picture of your situation &mdash; the next step is deciding what to do about it.</div>
                    <div style="font-size:14px; color:#334155;">This won&rsquo;t affect your credit score and there&rsquo;s no obligation.</div>
                    @if ($reviewHasCcj)
                        <div style="font-size:14px; color:#92400e; margin-top:6px; font-weight:700;">Because a CCJ is showing, it&rsquo;s worth speaking to us sooner rather than later so you understand your options.</div>
                    @endif
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:stretch;">
                    <button type="submit"
                            style="display:inline-flex; align-items:center; justify-content:center; padding:14px 20px; min-height:50px; min-width:210px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                        Email my full summary
                    </button>
                    @if(!blank($reviewWhatsAppUrl))
                        <a href="{{ $reviewWhatsAppUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-flex; align-items:center; justify-content:center; padding:14px 20px; min-height:50px; min-width:210px; border:1px solid #16a34a; border-radius:14px; background:#16a34a; color:#fff; font-size:16px; font-weight:700; text-decoration:none;">Talk to us about my debts</a>
                    @endif
                    <a href="{{ route('portal.entry', ['token' => $rawToken, 'go_back' => 1]) }}" style="display:inline-flex; align-items:center; justify-content:center; padding:14px 20px; min-height:50px; min-width:210px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">Back</a>
                </div>
            </form>
            @include('portal.partials.help-cta')
        @elseif ($currentStep === 'complete_pending')
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
        @elseif ($currentStep === 'credit_check_failed')
            <div style="display:inline-block; margin-bottom:18px; padding:8px 12px; border-radius:999px; background:#fee2e2; color:#b91c1c; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;">
                Credit check
            </div>

            <h1 style="margin:0 0 12px 0; font-size:32px; line-height:1.2;">
                We couldn&rsquo;t complete the check
            </h1>

            <p style="margin:0 0 20px 0; color:#475569; font-size:16px; line-height:1.6;">
                {{ $portalCreditCheckFailureMessage }} You can check your details and try again.
            </p>
            <div style="margin:0 0 12px 0; color:#475569; font-size:14px;">
                Attempts used: {{ $portalCreditCheckAttemptsUsed }} / {{ $portalCreditCheckAttemptsMax }}
            </div>

            @if($portalCreditCheckLatestLog && $portalCreditCheckLatestLog->isActive())
                <div style="padding:12px 14px; border-radius:12px; border:1px solid #dbeafe; background:#eff6ff; color:#1e3a8a; font-size:14px;">
                    We&rsquo;re still checking your details. Please stay on this page while we continue.
                </div>
            @else
                @if($portalCreditCheckCanRetry)
                    <form method="POST" action="{{ route('portal.credit-check.v3.start', ['token' => $rawToken]) }}" data-credit-check-form>
                        @csrf
                        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                            <a href="{{ route('portal.entry', ['token' => $rawToken, 'edit_details' => 1]) }}"
                               style="display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">
                                Back to details
                            </a>
                            <button type="submit" data-credit-check-submit data-running-label="Check running…"
                                    style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                                Try credit check again
                            </button>
                        </div>
                        <div style="width:100%;">
                            @include('portal.partials.credit-check-running')
                        </div>
                    </form>
                @else
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <a href="{{ route('portal.entry', ['token' => $rawToken, 'edit_details' => 1]) }}"
                           style="display:inline-block; padding:14px 20px; border:1px solid #cbd5e1; border-radius:14px; background:#ffffff; color:#0f172a; font-size:16px; font-weight:700; text-decoration:none;">
                            Back to details
                        </a>
                        <div style="padding:12px 14px; border-radius:12px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; font-size:14px;">
                            Sorry, our system couldn’t find your credit report automatically. You can still continue by listing any debts you know about below.
                        </div>
                    </div>
                @endif
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
@push('scripts')
<script>
(function () {
    const messages = [
        'Starting your secure check…',
        'Reviewing your information…',
        'Preparing your credit report…',
        'This can take a little while. Please stay on this page…',
        'Still working securely in the background…',
        'Almost there, thanks for waiting…'
    ];

    function startCreditCheckLoading(form) {
        if (form.dataset.creditCheckRunningStarted === '1') {
            return;
        }
        form.dataset.creditCheckRunningStarted = '1';

        const panel = form.querySelector('[data-credit-check-running-panel]');
        if (panel) {
            panel.hidden = false;
            panel.style.display = 'block';
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        form.querySelectorAll('[data-credit-check-submit]').forEach((button) => {
            if (!button.dataset.originalLabel) {
                button.dataset.originalLabel = button.textContent.trim();
            }
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
            button.style.opacity = '0.7';
            button.style.cursor = 'not-allowed';
            button.textContent = button.dataset.runningLabel || 'Check running…';
        });

        const status = panel ? panel.querySelector('[data-credit-check-status]') : null;
        if (!status) {
            return;
        }

        let index = 0;
        window.setInterval(() => {
            index = (index + 1) % messages.length;
            status.textContent = messages[index];
        }, 3000);
    }

    document.querySelectorAll('[data-credit-check-form]').forEach((form) => {
        form.addEventListener('submit', () => startCreditCheckLoading(form), { once: true });
    });
})();
</script>
@endpush

@endsection
