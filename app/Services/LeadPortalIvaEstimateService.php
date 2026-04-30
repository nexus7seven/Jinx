<?php

namespace App\Services;

use App\Models\Lead;

class LeadPortalIvaEstimateService
{
    private const IVA_THRESHOLD = 6000.0;
    private const EXAMPLE_REPAYMENT_TOTAL = 6000.0;

    public function calculateTotalDebt(Lead $lead): float
    {
        return (float) $lead->debts()
            ->whereIn('source_expected', ['credit_check', 'customer_added'])
            ->whereNotNull('balance')
            ->where('balance', '>', 0)
            ->sum('balance');
    }

    public function isEligible(float $totalDebt): bool
    {
        return $totalDebt >= self::IVA_THRESHOLD;
    }

    public function estimateWriteOff(float $totalDebt): float
    {
        return max($totalDebt - self::EXAMPLE_REPAYMENT_TOTAL, 0);
    }

    public function hasCourtJudgmentDebt(Lead $lead): bool
    {
        $debts = $lead->debts()
            ->with('creditor')
            ->whereIn('source_expected', ['credit_check', 'customer_added'])
            ->get();

        foreach ($debts as $debt) {
            $creditorName = (string) ($debt->creditor?->name ?? '');
            $reference = (string) ($debt->reference ?? '');

            if (
                stripos($creditorName, 'County Court Judgment') !== false
                || stripos($reference, 'County Court Judgment') !== false
                || stripos($reference, 'CCJ') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{total_debt:float,is_eligible:bool,example_repayment_total:float,estimated_write_off:float,has_court_judgment_debt:bool}
     */
    public function buildEstimateForLead(Lead $lead): array
    {
        $totalDebt = $this->calculateTotalDebt($lead);

        return [
            'total_debt' => $totalDebt,
            'is_eligible' => $this->isEligible($totalDebt),
            'example_repayment_total' => self::EXAMPLE_REPAYMENT_TOTAL,
            'estimated_write_off' => $this->estimateWriteOff($totalDebt),
            'has_court_judgment_debt' => $this->hasCourtJudgmentDebt($lead),
        ];
    }
}
