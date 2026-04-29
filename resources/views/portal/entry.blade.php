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

            <form method="POST" action="{{ route('portal.credit-check.start', ['token' => $rawToken]) }}">
                @csrf
                <button type="submit"
                        style="display:inline-block; padding:14px 20px; border:none; border-radius:14px; background:#1d4ed8; color:#ffffff; font-size:16px; font-weight:700; cursor:pointer;">
                    Start check
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
                <div style="font-size:14px; color:#334155;">Name: {{ $maskedName }}</div>
                <div style="font-size:14px; color:#334155;">Date of birth: {{ $maskedDob }}</div>
                <div style="font-size:14px; color:#334155;">Postcode: {{ $maskedPostcode }}</div>
                <div style="font-size:14px; color:#334155;">Address: {{ $maskedAddress }}</div>
            </div>

            <div style="border:1px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:12px;">
                <div style="font-weight:700; margin-bottom:8px;">What you owe</div>
                <div style="font-size:14px; color:#334155; margin-bottom:6px;">
                    Estimated total debt:
                    {{ $reviewMoney['estimated_total_debt'] ?? 'Not provided' }}
                </div>
                @if (($portalDebts ?? collect())->count() > 0)
                    @foreach (($portalDebts ?? collect()) as $row)
                        <div style="font-size:14px; color:#334155;">
                            {{ $row->creditor_name ?: 'Unnamed creditor' }} -
                            {{ $row->balance !== null ? '£'.number_format((float) $row->balance, 2) : 'No balance provided' }}
                        </div>
                    @endforeach
                @else
                    <div style="font-size:14px; color:#64748b;">No lender rows provided.</div>
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
