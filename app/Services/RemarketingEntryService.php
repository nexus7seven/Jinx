<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\RemarketingTask;

class RemarketingEntryService
{
    /** VICIdial list for remarketing leads (no autodial). */
    private const REMARKETING_VICIDIAL_LIST_ID = '5555555555';
    private const FLOW_START_REASON = 'flow_start';

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
        if ($lead->wip_status === 'DEAD') {
            return;
        }

        $vicidialLeadId = is_numeric($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : null;

        if ($vicidialLeadId === null) {
            return;
        }

        $this->vicidialListService->moveLeadToList($vicidialLeadId, self::REMARKETING_VICIDIAL_LIST_ID);

        $flowStartedExists = RemarketingTask::query()
            ->where('lead_id', $vicidialLeadId)
            ->where('task_type', 'flow_started')
            ->where('reason', self::FLOW_START_REASON)
            ->exists();

        if (! $flowStartedExists) {
            $first = trim((string) ($lead->first_name ?? ''));
            $last = trim((string) ($lead->last_name ?? ''));
            $fullName = trim($first . ' ' . $last);

            RemarketingTask::create([
                'lead_id' => $vicidialLeadId,
                'lead_name' => $fullName !== '' ? $fullName : ('Lead #' . $lead->id),
                'phone' => (string) ($lead->phone_number ?? ''),
                'campaign_id' => 'MAIN',
                'task_type' => 'flow_started',
                'reason' => self::FLOW_START_REASON,
                'stage' => 'fresh',
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

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
