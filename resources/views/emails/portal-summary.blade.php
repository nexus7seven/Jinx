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
        @if(($ivaEstimate['is_eligible'] ?? false) === true)
            <p style="margin:0 0 8px 0; color:#334155; font-size:14px; line-height:1.6;">
                Based on what we&rsquo;ve found so far, an IVA may be worth looking at.
            </p>
            <p style="margin:0 0 8px 0; color:#334155; font-size:14px; line-height:1.6;">
                For example, if payments were around £100 per month for 60 months, that would total £6,000.
            </p>
            <p style="margin:0 0 12px 0; color:#334155; font-size:14px; line-height:1.6;">
                Compared with your estimated debt total of {{ $ivaTotalDebt }}, that could mean around {{ $ivaEstimatedWriteOff }} may not need to be repaid, depending on your final assessment.
            </p>
        @else
            <p style="margin:0 0 12px 0; color:#334155; font-size:14px; line-height:1.6;">
                Based on the figures so far, an IVA may not be the best fit, but you can still ask for help understanding your options.
            </p>
        @endif
        @if(($ivaEstimate['has_court_judgment_debt'] ?? false) === true)
            <p style="margin:0 0 18px 0; color:#92400e; font-size:14px; line-height:1.6;">
                We&rsquo;ve also seen court judgment information, so it may be important to get advice before enforcement escalates.
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

        @if(!empty($whatsAppTrackingUrl))
            <p style="margin:0 0 10px 0; color:#334155; font-size:14px; line-height:1.6;">
                If you&rsquo;d like us to talk you through what this could mean, message us now and we&rsquo;ll help you understand your options.
            </p>
            <p style="margin:0;">
                <a href="{{ $whatsAppTrackingUrl }}"
                   style="display:inline-block; background:#16a34a; color:#ffffff; text-decoration:none; font-weight:700; border-radius:12px; padding:12px 16px;">
                    Message us on WhatsApp
                </a>
            </p>
        @endif
        @if(!empty($callUrl))
            <p style="margin:14px 0 0 0; color:#334155; font-size:14px; line-height:1.6;">
                You can also call us here: <a href="{{ $callUrl }}">{{ $callUrl }}</a>
            </p>
        @elseif(!empty($companyPhoneNumber))
            <p style="margin:14px 0 0 0; color:#334155; font-size:14px; line-height:1.6;">
                You can also call us on {{ $companyPhoneNumber }}.
            </p>
        @endif
    </div>
</div>
</body>
</html>
