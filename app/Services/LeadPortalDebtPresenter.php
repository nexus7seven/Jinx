<?php

namespace App\Services;

use App\Models\Debt;

class LeadPortalDebtPresenter
{
    public function customerFacingCreditorName(Debt $debt): string
    {
        $creditorName = trim((string) ($debt->creditor?->name ?? ''));
        $reference = trim((string) ($debt->reference ?? ''));

        if ($creditorName === 'Could Not Match' && str_contains($reference, 'Raw creditor:')) {
            $rawCreditor = trim(str_replace('Raw creditor:', '', $reference));

            if ($rawCreditor !== '') {
                return $rawCreditor;
            }
        }

        if ($creditorName !== '') {
            return $creditorName;
        }

        return 'Creditor';
    }
}
