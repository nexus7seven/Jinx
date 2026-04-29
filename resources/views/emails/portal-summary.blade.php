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
            @if($maskedPostcode)
                We&rsquo;ve matched this to postcode {{ $maskedPostcode }}.
            @endif
        </p>

        <h2 style="margin:0 0 10px 0; font-size:20px;">Debt overview</h2>
        <p style="margin:0 0 8px 0; color:#334155; font-size:15px; line-height:1.6;">
            Estimated total debt: <strong>{{ $estimatedTotalDebt }}</strong>
        </p>
        @if(count($portalDebts) > 0)
            <ul style="margin:0 0 18px 20px; padding:0; color:#334155; font-size:14px; line-height:1.6;">
                @foreach($portalDebts as $debt)
                    <li>
                        {{ $debt['creditor_name'] ?? 'Creditor' }}:
                        £{{ number_format((float) ($debt['balance'] ?? 0), 2) }}
                    </li>
                @endforeach
            </ul>
        @else
            <p style="margin:0 0 18px 0; color:#64748b; font-size:14px; line-height:1.6;">
                No individual lenders were listed.
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

        @if(!empty($whatsAppUrl))
            <p style="margin:0;">
                <a href="{{ $whatsAppUrl }}"
                   style="display:inline-block; background:#16a34a; color:#ffffff; text-decoration:none; font-weight:700; border-radius:12px; padding:12px 16px;">
                    Message us on WhatsApp
                </a>
            </p>
        @endif
    </div>
</div>
</body>
</html>
