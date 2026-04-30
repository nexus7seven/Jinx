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
        $totals = (array) ($payload['totals'] ?? []);
        $ivaEstimate = (array) ($payload['iva_estimate'] ?? []);
        $financialSummary = (array) ($payload['financial_summary'] ?? []);

        return new Content(
            view: 'emails.portal-summary',
            with: [
                'firstName' => $this->nullableString($details['first_name'] ?? null),
                'fullName' => trim((string) (($details['first_name'] ?? '').' '.($details['last_name'] ?? ''))),
                'email' => $this->nullableString($details['email'] ?? null),
                'phoneNumber' => $this->nullableString($details['phone_number'] ?? null),
                'postcode' => $this->nullableString($details['postcode'] ?? null),
                'address' => trim((string) (($details['house_number'] ?? '').' '.($details['address_line_1'] ?? ''))),
                'debts' => $debts,
                'totalDebt' => $this->formatMoney($totals['total_debt'] ?? null),
                'ivaEstimate' => $ivaEstimate,
                'ivaTotalDebt' => $this->formatMoney($ivaEstimate['total_debt'] ?? null),
                'ivaEstimatedWriteOff' => $this->formatMoney($ivaEstimate['estimated_write_off'] ?? null),
                'employmentStatus' => $this->nullableString($financialSummary['employment_status'] ?? null) ?? 'Not provided',
                'monthlyIncome' => $this->formatMoney($financialSummary['monthly_income'] ?? null),
                'monthlyCostsTotal' => $this->formatMoney($this->calculateMonthlyCostsTotal($financialSummary)),
                'monthlyCosts' => [
                    'housing' => $this->formatMoney($financialSummary['monthly_housing_cost'] ?? null),
                    'council_tax' => $this->formatMoney($financialSummary['monthly_council_tax'] ?? null),
                    'utilities' => $this->formatMoney($financialSummary['monthly_utilities_cost'] ?? null),
                    'food_travel' => $this->formatMoney($financialSummary['monthly_food_travel_cost'] ?? null),
                ],
                'whatsAppTrackingUrl' => ! blank(config('services.portal.whatsapp_url'))
                    ? route('portal.summary.click', ['snapshot' => $this->snapshot->id, 'type' => 'whatsapp'])
                    : null,
                'callUrl' => config('services.portal.call_url') ?: null,
                'companyPhoneNumber' => config('services.company.phone_number') ?: null,
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
