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
        $incomeCodes = array_keys($payload['income'] ?? []);
        $expenditureCodes = array_keys($payload['expenditure'] ?? []);

        $income = (float) ($lead->monthly_income ?? 0);
        if ($income > 0) {
            $incomeKey = $this->incomeKeyForEmploymentStatus((string) ($lead->employment_status ?? ''), $incomeCodes);
            if ($incomeKey !== null) {
                $payload['income'][$incomeKey] = round($income, 2);
            }
        }

        $this->setExpenditure($payload, $expenditureCodes, 'rent_mortgage', $lead->monthly_housing_cost);
        $this->setExpenditure($payload, $expenditureCodes, 'council_tax', $lead->monthly_council_tax);
        $this->mapUtilities($payload, $expenditureCodes, $lead->monthly_utilities_cost);
        $this->setExpenditure($payload, $expenditureCodes, 'food', $lead->monthly_food_travel_cost);

        return $this->financialStatementService->persistForLead($lead, $payload);
    }

    /**
     * @param array<int, string> $incomeCodes
     */
    private function incomeKeyForEmploymentStatus(string $employmentStatus, array $incomeCodes): ?string
    {
        return match ($employmentStatus) {
            'Employed full-time', 'Employed part-time' => $this->firstExisting($incomeCodes, ['salary']),
            'Self-employed' => $this->firstExisting($incomeCodes, ['self_employed', 'other_income']),
            'Benefits', 'Unemployed' => $this->firstExisting($incomeCodes, ['universal_credit', 'other_income']),
            'Pension' => $this->firstExisting($incomeCodes, ['pensions']),
            'Student', 'Homemaker / caring responsibilities', 'Other' => $this->firstExisting($incomeCodes, ['other_income']),
            default => $this->firstExisting($incomeCodes, ['other_income']),
        };
    }

    /**
     * @param array<int, string> $codes
     * @param array<int, string> $preferred
     */
    private function firstExisting(array $codes, array $preferred): ?string
    {
        foreach ($preferred as $code) {
            if (in_array($code, $codes, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $expenditureCodes
     */
    private function setExpenditure(array &$payload, array $expenditureCodes, string $code, mixed $value): void
    {
        if (! in_array($code, $expenditureCodes, true)) {
            return;
        }

        $amount = (float) ($value ?? 0);
        if ($amount > 0) {
            $payload['expenditure'][$code] = round($amount, 2);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $expenditureCodes
     */
    private function mapUtilities(array &$payload, array $expenditureCodes, mixed $value): void
    {
        $amount = (float) ($value ?? 0);
        if ($amount <= 0) {
            return;
        }

        $utilityCodes = array_values(array_intersect(['electric', 'gas', 'water'], $expenditureCodes));
        if ($utilityCodes === []) {
            return;
        }

        $split = round($amount / count($utilityCodes), 2);
        $remaining = round($amount - ($split * (count($utilityCodes) - 1)), 2);

        foreach ($utilityCodes as $index => $code) {
            $payload['expenditure'][$code] = $index === count($utilityCodes) - 1 ? $remaining : $split;
        }
    }
}
