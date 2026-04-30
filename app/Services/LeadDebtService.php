<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\DebtDocument;
use App\Models\Lead;

class LeadDebtService
{
    public function __construct(
        private readonly LeadChecklistService $leadChecklistService
    ) {
    }

    /**
     * @param array{creditor_id:int,balance:numeric-string|int|float,source_expected:string,reference?:string|null} $data
     */
    public function createForLead(Lead $lead, array $data): Debt
    {
        $debt = Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $data['creditor_id'],
            'balance' => $data['balance'],
            'source_expected' => $data['source_expected'],
            'reference' => $data['reference'] ?? null,
        ]);

        $isComplete = $data['source_expected'] === 'credit_check';

        DebtDocument::create([
            'debt_id' => $debt->id,
            'proof_type' => $data['source_expected'],
            'is_complete' => $isComplete,
        ]);

        $this->leadChecklistService->syncForLead($lead);

        return $debt;
    }
}
