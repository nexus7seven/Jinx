<?php

namespace App\Services;

use App\Models\LeadRemarketingProgress;
use App\Models\LeadRemarketingStepLog;
use App\Models\RemarketingStep;
use Illuminate\Support\Facades\DB;

class RemarketingProgressionService
{
    public function __construct(private RemarketingScheduleWindowService $scheduleWindowService)
    {
    }

    public function completeManualStepForLead(int $leadId, ?string $expectedStepKey = null, array $metadata = []): array
    {
        return DB::transaction(function () use ($leadId, $expectedStepKey, $metadata) {
            $progress = LeadRemarketingProgress::query()->where('lead_id', $leadId)->lockForUpdate()->first();
            if (! $progress) {
                return ['ok' => false, 'message' => 'Remarketing progress not found for this lead.'];
            }

            $steps = RemarketingStep::query()->where('is_active', true)->orderBy('step_order')->get();
            if ($steps->isEmpty()) {
                return ['ok' => false, 'message' => 'No active remarketing steps are configured.'];
            }

            $manualStep = null;
            if ($progress->status === LeadRemarketingProgress::STATUS_PENDING_MANUAL_TASK) {
                if ($progress->current_step_id !== null) {
                    $manualStep = $steps->firstWhere('id', (int) $progress->current_step_id);
                }
                if (! $manualStep && $progress->current_step_order !== null) {
                    $manualStep = $steps->first(fn (RemarketingStep $s) => (int) $s->step_order === (int) $progress->current_step_order);
                }
            } else {
                $manualStep = $progress->current_step_order === null ? $steps->first() : $steps->first(fn (RemarketingStep $s) => $s->step_order > $progress->current_step_order);
            }

            if (! $manualStep) {
                $progress->status = LeadRemarketingProgress::STATUS_COMPLETED;
                $progress->save();
                return ['ok' => true, 'message' => 'Remarketing flow already completed.', 'already_completed' => true];
            }

            if ($expectedStepKey !== null && $expectedStepKey !== '' && (string) $manualStep->step_key !== $expectedStepKey) {
                return ['ok' => false, 'message' => 'Current manual step does not match expected step key.'];
            }

            if (! in_array($manualStep->medium, ['call', 'whatsapp'], true)) {
                return ['ok' => false, 'message' => 'Only call or WhatsApp steps can be completed manually.'];
            }

            $baseTime = $progress->current_step_order === null ? ($progress->started_at ?? $progress->created_at) : ($progress->last_step_completed_at ?? $progress->updated_at ?? $progress->created_at);
            $rawDue = $baseTime->copy()->addMinutes((int) $manualStep->delay_minutes);
            $nextAllowed = $this->scheduleWindowService->nextAllowedTime($manualStep, $rawDue);
            $dueNow = now()->greaterThanOrEqualTo($nextAllowed);
            if (! $dueNow && $progress->status !== LeadRemarketingProgress::STATUS_PENDING_MANUAL_TASK) {
                return ['ok' => false, 'message' => 'This manual step is not due yet.'];
            }

            $now = now();
            LeadRemarketingStepLog::query()->create([
                'lead_id' => $leadId,
                'remarketing_step_id' => $manualStep->id,
                'step_order' => $manualStep->step_order,
                'medium' => $manualStep->medium,
                'template_id' => $manualStep->template_id,
                'status' => 'completed',
                'execution_status' => 'completed_manual_task',
                'due_at' => $nextAllowed,
                'started_at' => $now,
                'completed_at' => $now,
                'context_json' => array_merge(['mode' => 'manual_complete_service'], $metadata),
            ]);

            $progress->current_step_id = $manualStep->id;
            $progress->current_step_order = $manualStep->step_order;
            $progress->status = LeadRemarketingProgress::STATUS_ACTIVE;
            $progress->last_step_completed_at = $now;

            $following = $steps->first(fn (RemarketingStep $s) => $s->step_order > $manualStep->step_order);
            if ($following) {
                $progress->next_step_due_at = $this->scheduleWindowService->nextAllowedTime($following, $now->copy()->addMinutes((int) $following->delay_minutes));
            } else {
                $progress->status = LeadRemarketingProgress::STATUS_COMPLETED;
                $progress->next_step_due_at = null;
            }
            $progress->save();

            return ['ok' => true, 'message' => 'Manual step completed.', 'step_key' => $manualStep->step_key, 'step_order' => $manualStep->step_order];
        });
    }
}
