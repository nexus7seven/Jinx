<?php

namespace App\Mail;

use App\Models\LeadPortalSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeadPortalSummaryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly LeadPortalSnapshot $snapshot)
    {
    }

    public function envelope(): Envelope
    {
        $payload = (array) $this->snapshot->snapshot_json;

        return new Envelope(
            subject: 'Your financial summary',
            tags: ['portal_summary'],
            metadata: [
                'lead_id' => (string) ($payload['lead_id'] ?? $this->snapshot->lead_id),
                'portal_snapshot_id' => (string) $this->snapshot->id,
            ],
        );
    }

    public function content(): Content
    {
        $payload = (array) $this->snapshot->snapshot_json;
        $details = (array) ($payload['details'] ?? []);
        $debts = (array) ($payload['debts'] ?? []);
        $income = (array) ($payload['income'] ?? []);
        $costs = (array) ($payload['costs'] ?? []);

        return new Content(
            view: 'emails.portal-summary',
            with: [
                'firstName' => $this->nullableString($details['first_name'] ?? null),
                'maskedPostcode' => $this->maskPostcode($details['postcode'] ?? null),
                'estimatedTotalDebt' => $this->formatMoney($debts['estimated_total_debt'] ?? null),
                'portalDebts' => (array) ($debts['portal_debts'] ?? []),
                'employmentStatus' => $this->nullableString($income['employment_status'] ?? null) ?? 'Not provided',
                'monthlyIncome' => $this->formatMoney($income['monthly_income'] ?? null),
                'monthlyCostsTotal' => $this->formatMoney($this->calculateMonthlyCostsTotal($costs)),
                'monthlyCosts' => [
                    'housing' => $this->formatMoney($costs['monthly_housing_cost'] ?? null),
                    'council_tax' => $this->formatMoney($costs['monthly_council_tax'] ?? null),
                    'utilities' => $this->formatMoney($costs['monthly_utilities_cost'] ?? null),
                    'food_travel' => $this->formatMoney($costs['monthly_food_travel_cost'] ?? null),
                ],
                'whatsAppUrl' => config('services.portal.whatsapp_url'),
            ],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function maskPostcode(mixed $postcode): ?string
    {
        $postcode = $this->nullableString($postcode);
        if ($postcode === null) {
            return null;
        }

        $normalized = strtoupper(str_replace(' ', '', $postcode));
        if (strlen($normalized) <= 3) {
            return str_repeat('*', strlen($normalized));
        }

        return substr($normalized, 0, 3).str_repeat('*', max(strlen($normalized) - 3, 1));
    }

    private function calculateMonthlyCostsTotal(array $costs): ?float
    {
        $keys = [
            'monthly_housing_cost',
            'monthly_council_tax',
            'monthly_utilities_cost',
            'monthly_food_travel_cost',
        ];

        $sum = 0.0;
        $hasAny = false;

        foreach ($keys as $key) {
            $value = $costs[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $sum += (float) $value;
            $hasAny = true;
        }

        return $hasAny ? $sum : null;
    }

    private function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not provided';
        }

        return '£'.number_format((float) $value, 2);
    }
}
