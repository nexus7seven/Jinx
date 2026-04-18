<?php

namespace App\Services;

use App\Models\RemarketingCycle;

/**
 * Read-only bridge between planner output and future runner writes/actions.
 * Builds proposal payloads only; does not persist, dispatch, or send anything.
 */
class RemarketingCycleMutationPlanner
{
    public function __construct(
        private RemarketingCycleExecutionPlanner $executionPlanner,
    ) {
    }

    /**
     * @return array{
     *     cycle_id: int|null,
     *     lead_id: int|null,
     *     vicidial_lead_id: int|string|null,
     *     decision: 'stop'|'wait'|'execute'|'config_missing'|'no_action',
     *     planner: array<string, mixed>,
     *     proposed_mutations: array{
     *         cycle: array{should_update: bool, operation: 'stop'|'advance'|'complete'|'none', attributes: array<string, mixed>},
     *         task: array{should_create: bool, attributes: array<string, mixed>},
     *         delivery: array{should_send: bool, channel: string|null, template_name: string|null, payload: array<string, mixed>},
     *         side_effects: array{
     *             should_close_pending_tasks: bool,
     *             should_set_wip_status: bool,
     *             should_log_reengagement: bool,
     *             notes: list<string>
     *         }
     *     },
     *     messages: list<string>
     * }
     */
    public function buildProposal(RemarketingCycle $cycle): array
    {
        $planner = $this->executionPlanner->plan($cycle);
        $decision = $planner['decision'];

        $proposal = match ($decision) {
            'stop' => $this->buildStopProposal($planner),
            'execute' => $this->buildExecuteProposal($planner),
            'wait' => $this->buildWaitProposal(),
            'no_action' => $this->buildNoActionProposal(),
            'config_missing' => $this->buildConfigMissingProposal(),
            default => $this->buildConfigMissingProposal(),
        };

        return [
            'cycle_id' => $planner['cycle_id'] ?? $cycle->id,
            'lead_id' => $planner['lead_id'] ?? $cycle->lead_id,
            'vicidial_lead_id' => $planner['vicidial_lead_id'] ?? null,
            'decision' => $decision,
            'planner' => $planner,
            'proposed_mutations' => $proposal['proposed_mutations'],
            'messages' => array_values(array_merge(
                $planner['messages'] ?? [],
                $proposal['messages']
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $planner
     * @return array{
     *     proposed_mutations: array{
     *         cycle: array{should_update: bool, operation: 'stop', attributes: array<string, mixed>},
     *         task: array{should_create: bool, attributes: array<string, mixed>},
     *         delivery: array{should_send: bool, channel: null, template_name: null, payload: array<string, mixed>},
     *         side_effects: array{
     *             should_close_pending_tasks: bool,
     *             should_set_wip_status: bool,
     *             should_log_reengagement: bool,
     *             notes: list<string>
     *         }
     *     },
     *     messages: list<string>
     * }
     */
    private function buildStopProposal(array $planner): array
    {
        $reason = $planner['stop_evaluation']['reason'] ?? null;

        return [
            'proposed_mutations' => [
                'cycle' => [
                    'should_update' => true,
                    'operation' => 'stop',
                    'attributes' => [
                        'status' => RemarketingCycle::STATUS_STOPPED,
                        'stop_reason' => $reason,
                        'stopped_at' => '__NOW__',
                    ],
                ],
                'task' => $this->emptyTaskMutation(),
                'delivery' => $this->emptyDeliveryMutation(),
                'side_effects' => [
                    'should_close_pending_tasks' => true,
                    'should_set_wip_status' => false,
                    'should_log_reengagement' => false,
                    'notes' => [
                        'Future runner would close pending remarketing tasks for this cycle.',
                    ],
                ],
            ],
            'messages' => [
                'Planner decided this cycle should stop.',
                'Future runner would stop the cycle.',
                'Future runner would close pending remarketing tasks.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $planner
     * @return array{
     *     proposed_mutations: array{
     *         cycle: array{should_update: bool, operation: 'advance'|'complete'|'none', attributes: array<string, mixed>},
     *         task: array{should_create: bool, attributes: array<string, mixed>},
     *         delivery: array{should_send: bool, channel: string|null, template_name: string|null, payload: array<string, mixed>},
     *         side_effects: array{
     *             should_close_pending_tasks: bool,
     *             should_set_wip_status: bool,
     *             should_log_reengagement: bool,
     *             notes: list<string>
     *         }
     *     },
     *     messages: list<string>
     * }
     */
    private function buildExecuteProposal(array $planner): array
    {
        $current = is_array($planner['current_step'] ?? null) ? $planner['current_step'] : [];
        $next = is_array($planner['next_step'] ?? null) ? $planner['next_step'] : null;
        $post = is_array($planner['post_action_expectation'] ?? null) ? $planner['post_action_expectation'] : [];

        $actionType = $current['action_type'] ?? null;
        $templateName = $current['template_name'] ?? null;
        $stepKey = $current['step_key'] ?? null;
        $flowKey = $planner['flow_key'] ?? null;
        $sequence = $current['sequence'] ?? null;
        $stage = $current['stage'] ?? null;
        $autoAdvance = (bool) ($current['auto_advance_on_send'] ?? false);
        $manualCompletion = (bool) ($current['requires_manual_completion'] ?? false);
        $terminal = (bool) ($planner['terminal'] ?? false);
        $nextStepKey = $post['next_step_key_after_execution'] ?? null;

        $cycleMutation = $this->emptyCycleMutation();
        $messages = [
            'Planner decided this cycle should execute the current step.',
            'Future runner would create a remarketing task proposal.',
        ];

        if ($autoAdvance) {
            $cycleMutation['should_update'] = true;

            if ($next !== null) {
                $cycleMutation['operation'] = 'advance';
                $cycleMutation['attributes'] = [
                    'current_step_key' => $next['step_key'] ?? null,
                    'current_stage' => $next['stage'] ?? null,
                    'entered_step_at' => '__NOW__',
                    'next_due_at' => '__CALCULATE_FROM_NEXT_STEP__',
                ];
                $messages[] = 'Future runner would advance the cycle immediately after send.';
            } else {
                $cycleMutation['operation'] = 'complete';
                $cycleMutation['attributes'] = [
                    'status' => RemarketingCycle::STATUS_COMPLETED,
                    'completed_at' => '__NOW__',
                ];
                $messages[] = 'Future runner would complete the cycle after this step.';
            }
        } elseif ($manualCompletion) {
            $messages[] = 'Future runner would wait for manual completion before advancing the cycle.';
        } else {
            $messages[] = 'Current step does not auto-advance; future runner would leave cycle position unchanged for now.';
        }

        $shouldSend = in_array($actionType, ['whatsapp', 'sms', 'email'], true);
        if ($shouldSend) {
            $messages[] = 'Future runner would send the configured delivery for this step.';
        } else {
            $messages[] = 'Current step is a call; future runner would not send an outbound message directly.';
        }

        return [
            'proposed_mutations' => [
                'cycle' => $cycleMutation,
                'task' => [
                    'should_create' => true,
                    'attributes' => [
                        'lead_id' => $planner['lead_id'] ?? null,
                        'stage' => $stage,
                        'task_type' => $actionType,
                        'reason' => $stepKey !== null ? 'cycle_step:' . $stepKey : null,
                        'status' => 'pending',
                        'template_name' => $templateName,
                        'source' => 'remarketing_cycle_v2',
                        'meta' => [
                            'flow_key' => $flowKey,
                            'step_key' => $stepKey,
                            'sequence' => $sequence,
                            'auto_advance_on_send' => $autoAdvance,
                            'requires_manual_completion' => $manualCompletion,
                        ],
                    ],
                ],
                'delivery' => [
                    'should_send' => $shouldSend,
                    'channel' => $actionType,
                    'template_name' => $templateName,
                    'payload' => [
                        'lead_id' => $planner['lead_id'] ?? null,
                        'vicidial_lead_id' => $planner['vicidial_lead_id'] ?? null,
                        'flow_key' => $flowKey,
                        'step_key' => $stepKey,
                        'action_type' => $actionType,
                        'template_name' => $templateName,
                    ],
                ],
                'side_effects' => [
                    'should_close_pending_tasks' => false,
                    'should_set_wip_status' => false,
                    'should_log_reengagement' => false,
                    'notes' => [],
                ],
            ],
            'messages' => array_values(array_filter([
                ...$messages,
                $terminal ? 'Execution appears to reach the terminal step.' : null,
                $nextStepKey !== null ? 'Next step after execution would be ' . $nextStepKey . '.' : null,
            ])),
        ];
    }

    /**
     * @return array{
     *     proposed_mutations: array{
     *         cycle: array{should_update: bool, operation: 'none', attributes: array<string, mixed>},
     *         task: array{should_create: bool, attributes: array<string, mixed>},
     *         delivery: array{should_send: bool, channel: null, template_name: null, payload: array<string, mixed>},
     *         side_effects: array{
     *             should_close_pending_tasks: bool,
     *             should_set_wip_status: bool,
     *             should_log_reengagement: bool,
     *             notes: list<string>
     *         }
     *     },
     *     messages: list<string>
     * }
     */
    private function buildWaitProposal(): array
    {
        return [
            'proposed_mutations' => $this->emptyMutationSet(),
            'messages' => [
                'Cycle is not yet due; future runner would wait.',
            ],
        ];
    }

    /**
     * @return array{
     *     proposed_mutations: array{
     *         cycle: array{should_update: bool, operation: 'none', attributes: array<string, mixed>},
     *         task: array{should_create: bool, attributes: array<string, mixed>},
     *         delivery: array{should_send: bool, channel: null, template_name: null, payload: array<string, mixed>},
     *         side_effects: array{
     *             should_close_pending_tasks: bool,
     *             should_set_wip_status: bool,
     *             should_log_reengagement: bool,
     *             notes: list<string>
     *         }
     *     },
     *     messages: list<string>
     * }
     */
    private function buildNoActionProposal(): array
    {
        return [
            'proposed_mutations' => $this->emptyMutationSet(),
            'messages' => [
                'Cycle is not active; future runner would take no action.',
            ],
        ];
    }

    /**
     * @return array{
     *     proposed_mutations: array{
     *         cycle: array{should_update: bool, operation: 'none', attributes: array<string, mixed>},
     *         task: array{should_create: bool, attributes: array<string, mixed>},
     *         delivery: array{should_send: bool, channel: null, template_name: null, payload: array<string, mixed>},
     *         side_effects: array{
     *             should_close_pending_tasks: bool,
     *             should_set_wip_status: bool,
     *             should_log_reengagement: bool,
     *             notes: list<string>
     *         }
     *     },
     *     messages: list<string>
     * }
     */
    private function buildConfigMissingProposal(): array
    {
        return [
            'proposed_mutations' => $this->emptyMutationSet(),
            'messages' => [
                'Configuration gap prevents execution.',
            ],
        ];
    }

    /**
     * @return array{
     *     cycle: array{should_update: bool, operation: 'none', attributes: array<string, mixed>},
     *     task: array{should_create: bool, attributes: array<string, mixed>},
     *     delivery: array{should_send: bool, channel: null, template_name: null, payload: array<string, mixed>},
     *     side_effects: array{
     *         should_close_pending_tasks: bool,
     *         should_set_wip_status: bool,
     *         should_log_reengagement: bool,
     *         notes: list<string>
     *     }
     * }
     */
    private function emptyMutationSet(): array
    {
        return [
            'cycle' => $this->emptyCycleMutation(),
            'task' => $this->emptyTaskMutation(),
            'delivery' => $this->emptyDeliveryMutation(),
            'side_effects' => $this->emptySideEffects(),
        ];
    }

    /**
     * @return array{should_update: false, operation: 'none', attributes: array<string, mixed>}
     */
    private function emptyCycleMutation(): array
    {
        return [
            'should_update' => false,
            'operation' => 'none',
            'attributes' => [],
        ];
    }

    /**
     * @return array{should_create: false, attributes: array<string, mixed>}
     */
    private function emptyTaskMutation(): array
    {
        return [
            'should_create' => false,
            'attributes' => [],
        ];
    }

    /**
     * @return array{should_send: false, channel: null, template_name: null, payload: array<string, mixed>}
     */
    private function emptyDeliveryMutation(): array
    {
        return [
            'should_send' => false,
            'channel' => null,
            'template_name' => null,
            'payload' => [],
        ];
    }

    /**
     * @return array{
     *     should_close_pending_tasks: false,
     *     should_set_wip_status: false,
     *     should_log_reengagement: false,
     *     notes: list<string>
     * }
     */
    private function emptySideEffects(): array
    {
        return [
            'should_close_pending_tasks' => false,
            'should_set_wip_status' => false,
            'should_log_reengagement' => false,
            'notes' => [],
        ];
    }
}
