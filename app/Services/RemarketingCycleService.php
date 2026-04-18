<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\RemarketingCycle;
use App\Models\RemarketingStepDefinition;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class RemarketingCycleService
{
    public function getCurrentStepDefinition(RemarketingCycle $cycle): ?RemarketingStepDefinition
    {
        if ($cycle->current_step_key === null || $cycle->current_step_key === '') {
            return null;
        }

        if ($cycle->flow_key === null || $cycle->flow_key === '') {
            return null;
        }

        return RemarketingStepDefinition::query()
            ->where('flow_key', $cycle->flow_key)
            ->where('step_key', $cycle->current_step_key)
            ->where('active', true)
            ->first();
    }

    public function getFirstStepDefinition(string $flowKey): ?RemarketingStepDefinition
    {
        if ($flowKey === '') {
            return null;
        }

        return RemarketingStepDefinition::query()
            ->where('flow_key', $flowKey)
            ->where('active', true)
            ->orderBy('sequence')
            ->first();
    }

    public function getNextStepDefinition(RemarketingCycle $cycle, ?RemarketingStepDefinition $currentStep = null): ?RemarketingStepDefinition
    {
        $flowKey = $cycle->flow_key;
        if ($flowKey === null || $flowKey === '') {
            return null;
        }

        if ($currentStep !== null && $currentStep->flow_key !== $flowKey) {
            $currentStep = null;
        }

        $current = $currentStep ?? $this->getCurrentStepDefinition($cycle);

        if ($current === null) {
            return $this->getFirstStepDefinition($flowKey);
        }

        $nextKey = $current->next_step_key;
        if ($nextKey !== null && $nextKey !== '') {
            $byKey = $this->findActiveStepByFlowAndKey($flowKey, $nextKey);
            if ($byKey !== null) {
                return $byKey;
            }
        }

        return RemarketingStepDefinition::query()
            ->where('flow_key', $flowKey)
            ->where('active', true)
            ->where('sequence', '>', $current->sequence)
            ->orderBy('sequence')
            ->first();
    }

    public function calculateNextDueAt(Carbon|DateTimeInterface|string $enteredStepAt, RemarketingStepDefinition $step): Carbon
    {
        return Carbon::parse($enteredStepAt)->copy()->addMinutes($step->delay_minutes);
    }

    public function isStepDue(RemarketingCycle $cycle, ?Carbon $now = null): bool
    {
        if ($cycle->status !== RemarketingCycle::STATUS_ACTIVE) {
            return false;
        }

        if ($cycle->next_due_at === null) {
            return false;
        }

        $now = $now ?? now();

        return $cycle->next_due_at->lte($now);
    }

    public function isManualCompletionRequired(RemarketingStepDefinition $step): bool
    {
        return (bool) $step->requires_manual_completion;
    }

    public function shouldAutoAdvanceOnSend(RemarketingStepDefinition $step): bool
    {
        return (bool) $step->auto_advance_on_send;
    }

    /**
     * Read-only evaluation of whether the linked lead matches stop heuristics.
     * Honors per-step stop_if_* flags when a current step definition exists; if there is no
     * current step, stop_if_* defaults to true so a clear lead signal (e.g. re-engaged) still stops.
     *
     * @return array{
     *     should_stop: bool,
     *     reason: 'reengaged'|'dead'|'opted_out'|null,
     *     checks: array{reengaged: bool, dead: bool, opted_out: bool}
     * }
     */
    public function evaluateStopConditions(RemarketingCycle $cycle): array
    {
        $lead = $this->resolveLeadForCycle($cycle);

        $checks = [
            'reengaged' => false,
            'dead' => false,
            'opted_out' => false,
        ];

        if ($lead === null) {
            return [
                'should_stop' => false,
                'reason' => null,
                'checks' => $checks,
            ];
        }

        $checks['reengaged'] = $lead->wip_status === Lead::WIP_STATUS_REENGAGED;
        $checks['dead'] = $lead->wip_status === 'DEAD';
        $checks['opted_out'] = $this->leadAppearsOptedOut($lead);

        $current = $this->getCurrentStepDefinition($cycle);

        $stopReengaged = $checks['reengaged'] && ($current?->stop_if_reengaged ?? true);
        $stopDead = $checks['dead'] && ($current?->stop_if_dead ?? true);
        $stopOptedOut = $checks['opted_out'] && ($current?->stop_if_opted_out ?? true);

        $shouldStop = $stopReengaged || $stopDead || $stopOptedOut;

        $reason = null;
        if ($shouldStop) {
            if ($stopReengaged) {
                $reason = 'reengaged';
            } elseif ($stopDead) {
                $reason = 'dead';
            } elseif ($stopOptedOut) {
                $reason = 'opted_out';
            }
        }

        return [
            'should_stop' => $shouldStop,
            'reason' => $reason,
            'checks' => $checks,
        ];
    }

    public function cycleHasReachedTerminalStep(RemarketingCycle $cycle): bool
    {
        $current = $this->getCurrentStepDefinition($cycle);
        if ($current === null) {
            return false;
        }

        return $this->getNextStepDefinition($cycle, $current) === null;
    }

    /**
     * Sets the cycle to the first active step of its flow in memory only (does not persist).
     * Uses entered_step_at = now and next_due_at = entered + step delay.
     */
    public function initializeCycleAtFirstStep(RemarketingCycle $cycle): RemarketingCycle
    {
        $flowKey = $cycle->flow_key;
        if ($flowKey === null || $flowKey === '') {
            return $cycle;
        }

        $first = $this->getFirstStepDefinition($flowKey);
        if ($first === null) {
            return $cycle;
        }

        $now = now();

        $cycle->current_step_key = $first->step_key;
        $cycle->current_stage = $first->stage;
        $cycle->entered_step_at = $now;
        $cycle->next_due_at = $this->calculateNextDueAt($now, $first);

        return $cycle;
    }

    private function findActiveStepByFlowAndKey(string $flowKey, string $stepKey): ?RemarketingStepDefinition
    {
        return RemarketingStepDefinition::query()
            ->where('flow_key', $flowKey)
            ->where('step_key', $stepKey)
            ->where('active', true)
            ->first();
    }

    private function resolveLeadForCycle(RemarketingCycle $cycle): ?Lead
    {
        if ($cycle->lead_id === null) {
            return null;
        }

        if ($cycle->relationLoaded('lead')) {
            return $cycle->getRelation('lead');
        }

        return Lead::query()->find($cycle->lead_id);
    }

    /**
     * TODO: No dedicated marketing/opt-out field exists on Lead yet; wire when schema/rules exist.
     */
    private function leadAppearsOptedOut(Lead $lead): bool
    {
        return false;
    }
}
