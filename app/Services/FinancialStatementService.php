<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Validator;

class FinancialStatementService
{
    public function emptyState(): array
    {
        $income = [];
        foreach (config('financial_statement.income') as $row) {
            $income[$row['code']] = 0.0;
        }

        $expenditure = [];
        foreach (config('financial_statement.expenditure_sections') as $section) {
            foreach ($section['lines'] as $line) {
                $expenditure[$line['code']] = 0.0;
            }
        }

        return [
            'schema_version' => 1,
            'guidelines_version' => config('sfs_spending_guidelines.version'),
            'household' => [
                'adults' => 1,
                'children_under_16' => 0,
                'children_16_18' => 0,
            ],
            'income' => $income,
            'expenditure' => $expenditure,
        ];
    }

    public function mergeForLead(Lead $lead): array
    {
        $defaults = $this->emptyState();
        $stored = $lead->financial_statement;

        if (!is_array($stored)) {
            return $defaults;
        }

        $merged = array_replace_recursive($defaults, $stored);

        if (isset($stored['income']) && is_array($stored['income'])) {
            $merged['income'] = array_merge($defaults['income'], $stored['income']);
        }

        if (isset($stored['expenditure']) && is_array($stored['expenditure'])) {
            $merged['expenditure'] = array_merge($defaults['expenditure'], $stored['expenditure']);
        }

        if (isset($stored['household']) && is_array($stored['household'])) {
            $merged['household'] = array_merge($defaults['household'], $stored['household']);
        }

        return $merged;
    }

    /**
     * Client-side config for the Income & Expenditure UI (must stay aligned with merge/empty state).
     *
     * @return array{income: mixed, expenditure_sections: mixed, guidelineBands: mixed, guidelinesVersion: mixed, household_limits: mixed}
     */
    public function clientViewPayload(): array
    {
        return [
            'income' => config('financial_statement.income'),
            'expenditure_sections' => config('financial_statement.expenditure_sections'),
            'guidelineBands' => config('sfs_spending_guidelines.bands'),
            'guidelinesVersion' => config('sfs_spending_guidelines.version'),
            'household_limits' => config('financial_statement.household_limits'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function persistForLead(Lead $lead, array $input): array
    {
        $payload = $this->normalizeFromRequest($input);
        $lead->financial_statement = $payload;
        $lead->save();

        return $payload;
    }

    /**
     * @return array{household: array, income: array<string, float>, expenditure: array<string, float>, guidelines_version: string}
     */
    public function normalizeFromRequest(array $input): array
    {
        $limits = config('financial_statement.household_limits');

        $incomeCodes = array_column(config('financial_statement.income'), 'code');
        $expenditureCodes = [];
        foreach (config('financial_statement.expenditure_sections') as $section) {
            foreach ($section['lines'] as $line) {
                $expenditureCodes[] = $line['code'];
            }
        }

        $rules = [
            'household.adults' => ['required', 'integer', 'min:'.$limits['adults_min'], 'max:'.$limits['adults_max']],
            'household.children_under_16' => ['required', 'integer', 'min:'.$limits['children_min'], 'max:'.$limits['children_max']],
            'household.children_16_18' => ['required', 'integer', 'min:'.$limits['children_min'], 'max:'.$limits['children_max']],
        ];

        foreach ($incomeCodes as $code) {
            $rules["income.$code"] = ['nullable', 'numeric', 'min:0'];
        }

        foreach ($expenditureCodes as $code) {
            $rules["expenditure.$code"] = ['nullable', 'numeric', 'min:0'];
        }

        $validated = Validator::make($input, $rules)->validate();

        $income = [];
        foreach ($incomeCodes as $code) {
            $income[$code] = round((float) ($validated['income'][$code] ?? 0), 2);
        }

        $expenditure = [];
        foreach ($expenditureCodes as $code) {
            $expenditure[$code] = round((float) ($validated['expenditure'][$code] ?? 0), 2);
        }

        return [
            'schema_version' => 1,
            'guidelines_version' => config('sfs_spending_guidelines.version'),
            'household' => [
                'adults' => (int) $validated['household']['adults'],
                'children_under_16' => (int) $validated['household']['children_under_16'],
                'children_16_18' => (int) $validated['household']['children_16_18'],
            ],
            'income' => $income,
            'expenditure' => $expenditure,
        ];
    }
}
