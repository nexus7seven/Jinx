<?php

namespace App\Services;

use App\Models\RemarketingCycle;

/**
 * Read-only dry-run execution trace for a single cycle.
 * Describes ordered operations only; performs no writes, sends, or side effects.
 */
class RemarketingCycleExecutionTraceBuilder
{
    public function __construct(
        private RemarketingCycleExecutionPayloadBuilder $payloadBuilder,
    ) {
    }

    /**
     * @return array{
     *     cycle_id: int|null,
     *     lead_id: int|null,
     *     vicidial_lead_id: int|string|null,
     *     decision: 'stop'|'wait'|'execute'|'config_missing'|'no_action',
     *     payload: array<string, mixed>,
     *     trace: list<array{
     *         step: int,
     *         type: string,
     *         action: string,
     *         status: 'would_run'|'skipped',
     *         details: array<string, mixed>
     *     }>,
     *     summary: array{
     *         steps_total: int,
     *         would_run_count: int,
     *         skipped_count: int,
     *         has_cycle_update: bool,
     *         has_task_create: bool,
     *         has_delivery: bool,
     *         has_side_effects: bool
     *     },
     *     messages: list<string>
     * }
     */
    public function buildTrace(RemarketingCycle $cycle): array
    {
        $payload = $this->payloadBuilder->buildPayload($cycle);
        $decision = $this->normalizeDecision($payload['decision'] ?? null);

        $trace = [
            $this->makeTraceRow(1, 'precheck', 'inspect_decision', 'would_run', [
                'decision' => $decision,
            ]),
        ];

        $trace = match ($decision) {
            'stop' => $this->buildStopTrace($payload, $trace),
            'execute' => $this->buildExecuteTrace($payload, $trace),
            'wait' => $this->buildWaitTrace($trace),
            'no_action' => $this->buildNoActionTrace($trace),
            'config_missing' => $this->buildConfigMissingTrace($trace),
            default => $this->buildConfigMissingTrace($trace),
        };

        return [
            'cycle_id' => $payload['cycle_id'] ?? $cycle->id,
            'lead_id' => $payload['lead_id'] ?? $cycle->lead_id,
            'vicidial_lead_id' => $payload['vicidial_lead_id'] ?? null,
            'decision' => $decision,
            'payload' => $payload,
            'trace' => $trace,
            'summary' => $this->buildSummary($payload, $trace),
            'messages' => array_values(array_merge(
                $this->normalizeMessages($payload['messages'] ?? []),
                [
                    'Dry-run trace generated from normalized execution payload.',
                    'No writes or sends were performed.',
                ]
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $trace
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildStopTrace(array $payload, array $trace): array
    {
        $buckets = $this->extractBuckets($payload);
        $step = count($trace) + 1;

        if ($buckets['cycle_update']['should_run']) {
            $trace[] = $this->makeTraceRow($step++, 'cycle', 'stop_cycle', 'would_run', [
                'operation' => $buckets['cycle_update']['operation'],
                'attributes' => $buckets['cycle_update']['attributes'],
            ]);
        }

        if ($buckets['side_effects']['should_close_pending_tasks']) {
            $trace[] = $this->makeTraceRow($step++, 'side_effect', 'close_pending_tasks', 'would_run', [
                'notes' => $buckets['side_effects']['notes'],
            ]);
        }

        return $trace;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $trace
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildExecuteTrace(array $payload, array $trace): array
    {
        $buckets = $this->extractBuckets($payload);
        $step = count($trace) + 1;

        if ($buckets['task_create']['should_run']) {
            $trace[] = $this->makeTraceRow($step++, 'task', 'create_remarketing_task', 'would_run', $buckets['task_create']['attributes']);
        }

        if ($buckets['delivery']['should_run']) {
            $trace[] = $this->makeTraceRow($step++, 'delivery', 'send_delivery', 'would_run', [
                'channel' => $buckets['delivery']['channel'],
                'template_name' => $buckets['delivery']['template_name'],
                'payload' => $buckets['delivery']['payload'],
            ]);
        }

        if ($buckets['cycle_update']['should_run']) {
            $trace[] = $this->makeTraceRow($step++, 'cycle', 'apply_cycle_operation', 'would_run', [
                'operation' => $buckets['cycle_update']['operation'],
                'attributes' => $buckets['cycle_update']['attributes'],
            ]);
        }

        if ($buckets['side_effects']['should_close_pending_tasks']) {
            $trace[] = $this->makeTraceRow($step++, 'side_effect', 'close_pending_tasks', 'would_run', [
                'notes' => $buckets['side_effects']['notes'],
            ]);
        }

        if ($buckets['side_effects']['should_set_wip_status']) {
            $trace[] = $this->makeTraceRow($step++, 'side_effect', 'set_wip_status', 'would_run', [
                'notes' => $buckets['side_effects']['notes'],
            ]);
        }

        if ($buckets['side_effects']['should_log_reengagement']) {
            $trace[] = $this->makeTraceRow($step, 'side_effect', 'log_reengagement', 'would_run', [
                'notes' => $buckets['side_effects']['notes'],
            ]);
        }

        return $trace;
    }

    /**
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $trace
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildWaitTrace(array $trace): array
    {
        $trace[] = $this->makeTraceRow(count($trace) + 1, 'scheduler', 'wait_until_due', 'would_run');

        return $trace;
    }

    /**
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $trace
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildNoActionTrace(array $trace): array
    {
        $trace[] = $this->makeTraceRow(count($trace) + 1, 'lifecycle', 'no_action', 'would_run');

        return $trace;
    }

    /**
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $trace
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildConfigMissingTrace(array $trace): array
    {
        $trace[] = $this->makeTraceRow(count($trace) + 1, 'config', 'halt_for_configuration_gap', 'would_run');

        return $trace;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $trace
     * @return array{
     *     steps_total: int,
     *     would_run_count: int,
     *     skipped_count: int,
     *     has_cycle_update: bool,
     *     has_task_create: bool,
     *     has_delivery: bool,
     *     has_side_effects: bool
     * }
     */
    private function buildSummary(array $payload, array $trace): array
    {
        $buckets = $this->extractBuckets($payload);

        return [
            'steps_total' => count($trace),
            'would_run_count' => count(array_filter($trace, fn (array $row): bool => $row['status'] === 'would_run')),
            'skipped_count' => count(array_filter($trace, fn (array $row): bool => $row['status'] === 'skipped')),
            'has_cycle_update' => (bool) ($buckets['cycle_update']['should_run'] ?? false),
            'has_task_create' => (bool) ($buckets['task_create']['should_run'] ?? false),
            'has_delivery' => (bool) ($buckets['delivery']['should_run'] ?? false),
            'has_side_effects' => (bool) (
                ($buckets['side_effects']['should_close_pending_tasks'] ?? false)
                || ($buckets['side_effects']['should_set_wip_status'] ?? false)
                || ($buckets['side_effects']['should_log_reengagement'] ?? false)
            ),
        ];
    }

    /**
     * @param  int  $step
     * @param  string  $type
     * @param  string  $action
     * @param  'would_run'|'skipped'  $status
     * @param  array<string, mixed>  $details
     * @return array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}
     */
    private function makeTraceRow(int $step, string $type, string $action, string $status, array $details = []): array
    {
        return [
            'step' => $step,
            'type' => $type,
            'action' => $action,
            'status' => $status,
            'details' => $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     cycle_update: array{should_run: bool, operation: string, attributes: array<string, mixed>},
     *     task_create: array{should_run: bool, attributes: array<string, mixed>},
     *     delivery: array{should_run: bool, channel: string|null, template_name: string|null, payload: array<string, mixed>},
     *     side_effects: array{
     *         should_close_pending_tasks: bool,
     *         should_set_wip_status: bool,
     *         should_log_reengagement: bool,
     *         notes: list<string>
     *     }
     * }
     */
    private function extractBuckets(array $payload): array
    {
        $buckets = is_array($payload['execution_buckets'] ?? null) ? $payload['execution_buckets'] : [];

        return [
            'cycle_update' => is_array($buckets['cycle_update'] ?? null) ? $buckets['cycle_update'] : [
                'should_run' => false,
                'operation' => 'none',
                'attributes' => [],
            ],
            'task_create' => is_array($buckets['task_create'] ?? null) ? $buckets['task_create'] : [
                'should_run' => false,
                'attributes' => [],
            ],
            'delivery' => is_array($buckets['delivery'] ?? null) ? $buckets['delivery'] : [
                'should_run' => false,
                'channel' => null,
                'template_name' => null,
                'payload' => [],
            ],
            'side_effects' => is_array($buckets['side_effects'] ?? null) ? $buckets['side_effects'] : [
                'should_close_pending_tasks' => false,
                'should_set_wip_status' => false,
                'should_log_reengagement' => false,
                'notes' => [],
            ],
        ];
    }

    /**
     * @param  mixed  $decision
     * @return 'stop'|'wait'|'execute'|'config_missing'|'no_action'
     */
    private function normalizeDecision(mixed $decision): string
    {
        $allowed = ['stop', 'wait', 'execute', 'config_missing', 'no_action'];

        return is_string($decision) && in_array($decision, $allowed, true)
            ? $decision
            : 'config_missing';
    }

    /**
     * @param  mixed  $messages
     * @return list<string>
     */
    private function normalizeMessages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        $normalized = [];
        foreach ($messages as $message) {
            if (is_string($message) && $message !== '') {
                $normalized[] = $message;
            }
        }

        return array_values($normalized);
    }
}
