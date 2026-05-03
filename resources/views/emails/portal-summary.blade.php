<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your financial summary</title>
</head>
<body style="margin:0; padding:0; background:#f8fafc; color:#0f172a; font-family:Arial, sans-serif;">
<div style="max-width:640px; margin:0 auto; padding:24px;">
    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:20px; padding:28px;">
        <h1 style="margin:0 0 16px 0; font-size:28px; line-height:1.2;">Your financial summary</h1>

        <p style="margin:0 0 12px 0; color:#334155; font-size:15px; line-height:1.6;">
            @if($firstName)
                Hi {{ $firstName }},
            @else
                Hello,
            @endif
        </p>
        <p style="margin:0 0 18px 0; color:#334155; font-size:15px; line-height:1.6;">
            Here&rsquo;s the summary we put together from the details you provided.
        </p>
        <p style="margin:0 0 18px 0; color:#334155; font-size:15px; line-height:1.6;">
            If you&rsquo;d like help understanding what this means, message us and we&rsquo;ll talk you through your options.
        </p>
        @if(!empty($whatsAppTrackingUrl))
            <p style="margin:0 0 24px 0;">
                <a href="{{ $whatsAppTrackingUrl }}" target="_blank" rel="noopener noreferrer"
                   style="display:inline-block; background:#16a34a; color:#ffffff; text-decoration:none; font-weight:700; border-radius:12px; padding:12px 18px;">
                    Message us on WhatsApp
                </a>
            </p>
        @endif

        <h2 style="margin:0 0 10px 0; font-size:20px;">Customer details</h2>
        <p style="margin:0 0 6px 0; color:#334155; font-size:14px; line-height:1.6;">Name: {{ $fullName !== '' ? $fullName : 'Not provided' }}</p>
        @if($postcode)
            <p style="margin:0 0 6px 0; color:#334155; font-size:14px; line-height:1.6;">Postcode: {{ $postcode }}</p>
        @endif
        @if($address !== '')
            <p style="margin:0 0 6px 0; color:#334155; font-size:14px; line-height:1.6;">Address: {{ $address }}</p>
        @endif
        @if($phoneNumber)
            <p style="margin:0 0 6px 0; color:#334155; font-size:14px; line-height:1.6;">Phone: {{ $phoneNumber }}</p>
        @endif
        @if($email)
            <p style="margin:0 0 18px 0; color:#334155; font-size:14px; line-height:1.6;">Email: {{ $email }}</p>
        @endif

        <h2 style="margin:0 0 10px 0; font-size:20px;">Debt overview</h2>
        @if(count($debts) > 0)
            <ul style="margin:0 0 18px 20px; padding:0; color:#334155; font-size:14px; line-height:1.6;">
                @foreach($debts as $debt)
                    <li>
                        {{ $debt['creditor_name_customer'] ?? 'Creditor' }}
                        £{{ number_format((float) ($debt['balance'] ?? 0), 2) }}
                        <span style="color:#64748b;">({{ $debt['source_label'] ?? 'Debt' }})</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p style="margin:0 0 18px 0; color:#64748b; font-size:14px; line-height:1.6;">
                No individual lenders were listed.
            </p>
        @endif
        <p style="margin:0 0 18px 0; color:#334155; font-size:15px; line-height:1.6;">
            Total debt: <strong>{{ $totalDebt }}</strong>
        </p>

        <h2 style="margin:0 0 10px 0; font-size:20px;">What this could mean</h2>
        <p style="margin:0 0 10px 0; color:#334155; font-size:14px; line-height:1.6;">
            Based on what we&rsquo;ve seen so far, there may be options available to reduce the pressure from your debts and make things more manageable.
        </p>
        <ul style="margin:0 0 12px 20px; padding:0; color:#334155; font-size:14px; line-height:1.7;">
            <li>Reduce what you pay each month</li>
            <li>Stop creditor pressure and enforcement, depending on circumstances</li>
            <li>Have everything handled in one place</li>
            <li>Deal with creditors directly on your behalf</li>
        </ul>
        @if(($ivaEstimate['has_court_judgment_debt'] ?? false) === true)
            <p style="margin:0 0 18px 0; color:#92400e; font-size:14px; line-height:1.6;">
                We&rsquo;ve also seen signs of a County Court Judgment, so it may be worth getting clarity on this sooner rather than later.
            </p>
        @endif

        <h2 style="margin:0 0 10px 0; font-size:20px;">Monthly picture</h2>
        <p style="margin:0 0 6px 0; color:#334155; font-size:14px; line-height:1.6;">Employment status: {{ $employmentStatus }}</p>
        <p style="margin:0 0 6px 0; color:#334155; font-size:14px; line-height:1.6;">Monthly income: {{ $monthlyIncome }}</p>
        <p style="margin:0 0 6px 0; color:#334155; font-size:14px; line-height:1.6;">Monthly costs total: {{ $monthlyCostsTotal }}</p>
        <p style="margin:0 0 18px 0; color:#64748b; font-size:13px; line-height:1.6;">
            Housing {{ $monthlyCosts['housing'] }}, council tax {{ $monthlyCosts['council_tax'] }},
            utilities {{ $monthlyCosts['utilities'] }}, food and travel {{ $monthlyCosts['food_travel'] }}.
        </p>

        <div style="margin-top:18px; padding-top:16px; border-top:1px solid #e2e8f0;">
            <div style="margin:0 0 10px 0; color:#334155; font-size:14px; line-height:1.6; font-weight:700;">Need help now?</div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @if(!empty($whatsAppTrackingUrl))
                    <a href="{{ $whatsAppTrackingUrl }}" target="_blank" rel="noopener noreferrer"
                       style="display:inline-block; background:#16a34a; color:#ffffff; text-decoration:none; font-weight:700; border-radius:12px; padding:12px 18px;">
                        Message us on WhatsApp
                    </a>
                @endif
                @if(!empty($callUrl))
                    <a href="{{ $callUrl }}" target="_blank" rel="noopener noreferrer"
                       style="display:inline-block; background:#1d4ed8; color:#ffffff; text-decoration:none; font-weight:700; border-radius:12px; padding:12px 18px;">
                        Call us
                    </a>
                @elseif(!empty($companyPhoneNumber))
                    <span style="display:inline-block; background:#1d4ed8; color:#ffffff; font-weight:700; border-radius:12px; padding:12px 18px;">
                        Call us: {{ $companyPhoneNumber }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
</body>
</html>
