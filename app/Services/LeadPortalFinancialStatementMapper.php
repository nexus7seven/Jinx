<?php

namespace App\Services;

use App\Models\Lead;

class LeadPortalFinancialStatementMapper
{
    public function __construct(
        private readonly FinancialStatementService $financialStatementService
    ) {
    }

    public function persistFromPortalFields(Lead $lead): array
    {
        $payload = $this->financialStatementService->mergeForLead($lead);

        $income = (float) ($lead->monthly_income ?? 0);
        if ($income > 0) {
            $incomeKey = $this->incomeKeyForEmploymentStatus((string) ($lead->employment_status ?? ''));
            if ($incomeKey !== null) {
                $payload['income'][$incomeKey] = round($income, 2);
            }
        }

        $this->setHousing($payload, 'rent_mortgage', $lead->monthly_housing_cost);
        $this->setHousing($payload, 'council_tax', $lead->monthly_council_tax);
        if ($lead->monthly_council_tax !== null && (float) $lead->monthly_council_tax > 0) {
            $payload['facts']['council_tax_source'] = 'manual_fallback';
        }

        return $this->financialStatementService->persistForLead($lead, $payload);
    }

    private function incomeKeyForEmploymentStatus(string $employmentStatus): ?string
    {
        return match ($employmentStatus) {
            'Employed full-time', 'Employed part-time', 'Self-employed' => 'client_salary',
            'Pension' => 'pension',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function setHousing(array &$payload, string $code, mixed $value): void
    {
        $amount = (float) ($value ?? 0);
        if ($amount > 0) {
            $payload['expenditure']['housing'][$code] = round($amount, 2);
        }
    }
}
