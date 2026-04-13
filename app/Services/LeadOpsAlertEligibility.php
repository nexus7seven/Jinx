<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Rules for "ops" notifications (WIP UI now; Teams / assistants later).
 * Dialler agent web form creates leads with from_vicidial_webform=true — those are excluded.
 */
class LeadOpsAlertEligibility
{
    public function shouldNotifyNewLeadForOps(Lead $lead): bool
    {
        if ($lead->from_vicidial_webform) {
            return false;
        }

        return true;
    }
}
