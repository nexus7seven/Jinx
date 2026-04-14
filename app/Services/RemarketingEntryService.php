<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\RemarketingTask;

class RemarketingEntryService
{
    /** VICIdial list for remarketing leads (no autodial). */
    private const REMARKETING_VICIDIAL_LIST_ID = '5555555555';

    public function __construct(
        private RemarketingTaskService $remarketingTaskService,
        private VicidialListService $vicidialListService,
    ) {
    }

    /**
     * Single entry point for leads entering the remarketing flow (step 1: first CALL task only).
     *
     * @param  string  $triggerSource  e.g. lost_contact — reserved for later timeline routing
     */
    public function enterRemarketingFlow(Lead $lead, string $triggerSource): void
    {
        $vicidialLeadId = is_numeric($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : null;

        if ($vicidialLeadId === null) {
            return;
        }

        $this->vicidialListService->moveLeadToList($vicidialLeadId, self::REMARKETING_VICIDIAL_LIST_ID);

        $pendingCallExists = RemarketingTask::query()
            ->where('lead_id', $vicidialLeadId)
            ->where('task_type', 'call')
            ->where('status', 'pending')
            ->exists();

        if ($pendingCallExists) {
            return;
        }

        $this->remarketingTaskService->createCallTaskForLead($lead->fresh(), [
            'campaign_id' => 'MAIN',
            'reason' => $this->remarketingTaskService->resolveReason('no_answer'),
            'stage' => 'fresh',
            'time_waiting_text' => '0h',
        ]);
    }
}
