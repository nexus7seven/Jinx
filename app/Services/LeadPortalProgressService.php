<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadPortalProgress;

class LeadPortalProgressService
{
    public function ensureForLead(Lead $lead): LeadPortalProgress
    {
        return LeadPortalProgress::firstOrCreate(
            ['lead_id' => $lead->id],
            [
                'current_step' => 'welcome',
                'started_at' => now(),
            ]
        );
    }

    public function updateStep(
        Lead $lead,
        string $currentStep,
        ?string $lastCompletedStep = null
    ): LeadPortalProgress {
        $progress = $this->ensureForLead($lead);

        $progress->current_step = $currentStep;
        if ($lastCompletedStep !== null) {
            $progress->last_completed_step = $lastCompletedStep;
        }
        $progress->last_seen_at = now();
        $progress->save();

        return $progress;
    }

    public function complete(Lead $lead): LeadPortalProgress
    {
        $progress = $this->ensureForLead($lead);

        $progress->current_step = 'complete';
        $progress->last_completed_step = 'review';
        $progress->completed_at = now();
        $progress->last_seen_at = now();
        $progress->save();

        return $progress;
    }
}
