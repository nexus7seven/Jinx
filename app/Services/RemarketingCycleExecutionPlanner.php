<?php

namespace App\Services;

use App\Models\RemarketingCycle;
use App\Models\RemarketingStepDefinition;

/**
 * Read-only execution planning for a single remarketing cycle.
 * Produces a normalized plan array for a future runner; does not persist or dispatch work.
 */
class RemarketingCycleExecutionPlanner
{
    public function __construct(
        private RemarketingCycleService $cycles,
    ) {
    }

    /**
     * @return array{
     *     cycle_id: int|null,
     *     lead_id: int|null,
     *     vicidial_lead_id: int|string|null,
     *     flow_key: string|null,
     *     cycle_status: string|null,
     *     current_step: array|null,
     *     next_step: array|null,
     *     due: bool,
     *     terminal: bool,
     *     stop_evaluation: array{should_stop: bool, reason: string|null, checks: array{reengaged: bool, dead: bool, opted_out: bool}},
     *     decision: 'stop'|'wait'|'execute'|'config_missing'|'no_action',
     *     recommended_action: array{type: 'stop_cycle'|'execute_step'|'none', reason: string|null, step_key: string|null, action_type: string|null, template_name: string|null},
     *     post_action_expectation: array{
     *         would_create_task: bool,
     *         would_require_manual_completion: bool,
     *         would_auto_advance_on_send: bool,
     *         would_be_terminal_after_execution: bool,
     *         next_step_key_after_execution: string|null
     *     },
     *     messages: list<string>
     * }
     */
    public function plan(RemarketingCycle $cycle): array
    {
        $flowKey = $cycle->flow_key;
        $currentDef = $this->cycles->getCurrentStepDefinition($cycle);
        $firstDef = $this->cycles->getFirstStepDefinition((string) ($flowKey ?? ''));
        $nextDef = $this->cycles->getNextStepDefinition($cycle);
        $due = $this->cycles->isStepDue($cycle);
        $terminal = $this->cycles->cycleHasReachedTerminalStep($cycle);
        $stopEval = $this->cycles->evaluateStopConditions($cycle);

        $messages = [];

        $base = [
            'cycle_id' => $cycle->id,
            'lead_id' => $cycle->lead_id,
            'vicidial_lead_id' => $this->resolveVicidialLeadId($cycle),
            'flow_key' => $flowKey,
            'cycle_status' => $cycle->status,
            'current_step' => $this->serializeCurrentStep($currentDef),
            'next_step' => $this->serializeNextStep($nextDef),
            'due' => $due,
            'terminal' => $terminal,
            'stop_evaluation' => $stopEval,
        ];

        if ($currentDef === null) {
            return array_merge($base, $this->planConfigMissing(
                $cycle,
                $firstDef,
                $messages
            ));
        }

        if ($stopEval['should_stop']) {
            return array_merge($base, $this->planStop($stopEval, $messages));
        }

        if ($cycle->status !== RemarketingCycle::STATUS_ACTIVE) {
            $messages[] = 'Cycle is not active.';

            return array_merge($base, [
                'decision' => 'no_action',
                'recommended_action' => $this->buildRecommendedActionNone(),
                'post_action_expectation' => $this->emptyPostActionExpectation(),
                'messages' => $messages,
            ]);
        }

        if (! $due) {
            $messages[] = 'Cycle is waiting for next_due_at.';

            return array_merge($base, [
                'decision' => 'wait',
                'recommended_action' => $this->buildRecommendedActionNone(),
                'post_action_expectation' => $this->emptyPostActionExpectation(),
                'messages' => $messages,
            ]);
        }

        return array_merge($base, $this->planExecute(
            $currentDef,
            $nextDef,
            $terminal,
            $messages
        ));
    }

    /**
     * @param  list<string>  $messages
     * @return array{decision: 'config_missing', recommended_action: array, post_action_expectation: array, messages: list<string>}
     */
    private function planConfigMissing(
        RemarketingCycle $cycle,
        ?RemarketingStepDefinition $firstDef,
        array $messages,
    ): array {
        if ($firstDef === null) {
            $messages[] = 'No active steps exist for this flow.';
        } else {
            $hasKey = $cycle->current_step_key !== null && $cycle->current_step_key !== '';
            if ($hasKey) {
                $messages[] = 'Current step key does not resolve to an active definition.';
            } else {
                $messages[] = 'Cycle has no current step; it likely needs initialization at the first step.';
            }
        }

        return [
            'decision' => 'config_missing',
            'recommended_action' => $this->buildRecommendedActionNone(),
            'post_action_expectation' => $this->emptyPostActionExpectation(),
            'messages' => $messages,
        ];
    }

    /**
     * @param  array{should_stop: bool, reason: string|null, checks: array{reengaged: bool, dead: bool, opted_out: bool}}  $stopEval
     * @param  list<string>  $messages
     * @return array{decision: 'stop', recommended_action: array, post_action_expectation: array, messages: list<string>}
     */
    private function planStop(array $stopEval, array $messages): array
    {
        $reason = $stopEval['reason'];
        $messages[] = $this->stopMessageForReason($reason);

        return [
            'decision' => 'stop',
            'recommended_action' => [
                'type' => 'stop_cycle',
                'reason' => $reason,
                'step_key' => null,
                'action_type' => null,
                'template_name' => null,
            ],
            'post_action_expectation' => $this->emptyPostActionExpectation(),
            'messages' => $messages,
        ];
    }

    /**
     * @param  list<string>  $messages
     * @return array{decision: 'execute', recommended_action: array, post_action_expectation: array, messages: list<string>}
     */
    private function planExecute(
        RemarketingStepDefinition $currentDef,
        ?RemarketingStepDefinition $nextDef,
        bool $terminal,
        array $messages,
    ): array {
        $messages[] = 'Current step would execute.';
        if ($this->cycles->isManualCompletionRequired($currentDef)) {
            $messages[] = 'This step requires manual completion.';
        } else {
            $messages[] = 'This step does not require manual completion.';
        }
        if ($this->cycles->shouldAutoAdvanceOnSend($currentDef)) {
            $messages[] = 'Step is configured for auto-advance-on-send.';
        }
        if ($terminal) {
            $messages[] = 'This is the terminal step; no further active step follows.';
        }

        return [
            'decision' => 'execute',
            'recommended_action' => [
                'type' => 'execute_step',
                'reason' => null,
                'step_key' => $currentDef->step_key,
                'action_type' => $currentDef->action_type,
                'template_name' => $currentDef->template_name,
            ],
            'post_action_expectation' => [
                'would_create_task' => true,
                'would_require_manual_completion' => $this->cycles->isManualCompletionRequired($currentDef),
                'would_auto_advance_on_send' => $this->cycles->shouldAutoAdvanceOnSend($currentDef),
                'would_be_terminal_after_execution' => $terminal,
                'next_step_key_after_execution' => $nextDef !== null ? $nextDef->step_key : null,
            ],
            'messages' => $messages,
        ];
    }

    private function serializeCurrentStep(?RemarketingStepDefinition $step): ?array
    {
        if ($step === null) {
            return null;
        }

        return [
            'step_key' => $step->step_key,
            'sequence' => $step->sequence,
            'stage' => $step->stage,
            'action_type' => $step->action_type,
            'template_name' => $step->template_name,
            'delay_minutes' => $step->delay_minutes,
            'requires_manual_completion' => (bool) $step->requires_manual_completion,
            'auto_advance_on_send' => (bool) $step->auto_advance_on_send,
        ];
    }

    private function serializeNextStep(?RemarketingStepDefinition $step): ?array
    {
        if ($step === null) {
            return null;
        }

        return [
            'step_key' => $step->step_key,
            'sequence' => $step->sequence,
            'stage' => $step->stage,
            'action_type' => $step->action_type,
            'template_name' => $step->template_name,
        ];
    }

    /**
     * @return array{type: 'none', reason: null, step_key: null, action_type: null, template_name: null}
     */
    private function buildRecommendedActionNone(): array
    {
        return [
            'type' => 'none',
            'reason' => null,
            'step_key' => null,
            'action_type' => null,
            'template_name' => null,
        ];
    }

    /**
     * @return array{
     *     would_create_task: false,
     *     would_require_manual_completion: false,
     *     would_auto_advance_on_send: false,
     *     would_be_terminal_after_execution: false,
     *     next_step_key_after_execution: null
     * }
     */
    private function emptyPostActionExpectation(): array
    {
        return [
            'would_create_task' => false,
            'would_require_manual_completion' => false,
            'would_auto_advance_on_send' => false,
            'would_be_terminal_after_execution' => false,
            'next_step_key_after_execution' => null,
        ];
    }

    private function stopMessageForReason(?string $reason): string
    {
        return match ($reason) {
            'reengaged' => 'Cycle should stop because lead is re-engaged.',
            'dead' => 'Cycle should stop because lead is DEAD.',
            'opted_out' => 'Cycle should stop because lead appears opted out.',
            default => 'Cycle should stop.',
        };
    }

    private function resolveVicidialLeadId(RemarketingCycle $cycle): string|int|null
    {
        $attrs = $cycle->getAttributes();
        if (array_key_exists('vicidial_lead_id', $attrs) && $attrs['vicidial_lead_id'] !== null && $attrs['vicidial_lead_id'] !== '') {
            return is_numeric($attrs['vicidial_lead_id'])
                ? (int) $attrs['vicidial_lead_id']
                : (string) $attrs['vicidial_lead_id'];
        }

        if ($cycle->relationLoaded('lead')) {
            $lead = $cycle->getRelation('lead');
        } else {
            $lead = $cycle->lead;
        }

        if ($lead !== null && $lead->vicidial_lead_id !== null && $lead->vicidial_lead_id !== '') {
            return is_numeric($lead->vicidial_lead_id)
                ? (int) $lead->vicidial_lead_id
                : (string) $lead->vicidial_lead_id;
        }

        return null;
    }
}
