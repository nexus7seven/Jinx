<?php

namespace App\Services;

use App\Models\RemarketingCycle;

/**
 * Read-only executor stub: simulates ordered execution attempts from a normalized payload.
 * Produces dry-run attempt records only; no writes, sends, or side effects.
 */
class RemarketingCycleExecutorStub
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
     *     attempts: list<array{
     *         attempt: int,
     *         type: string,
     *         operation: string,
     *         would_attempt: bool,
     *         outcome: 'dry_run_only'|'skipped',
     *         details: array<string, mixed>
     *     }>,
     *     summary: array{
     *         attempt_count: int,
     *         would_attempt_count: int,
     *         skipped_count: int,
     *         dry_run_only_count: int,
     *         decision_path: string
     *     },
     *     messages: list<string>
     * }
     */
    public function simulate(RemarketingCycle $cycle): array
    {
        $payload = $this->payloadBuilder->buildPayload($cycle);
        $decision = $this->normalizeDecision($payload['decision'] ?? null);

        $rows = [
            $this->makePlanRow(1, 'precheck', 'inspect_decision', 'would_run', [
                'decision' => $decision,
            ]),
        ];

        $rows = match ($decision) {
            'stop' => $this->buildStopRows($payload, $rows),
            'execute' => $this->buildExecuteRows($payload, $rows),
            'wait' => $this->buildWaitRows($rows),
            'no_action' => $this->buildNoActionRows($rows),
            'config_missing' => $this->buildConfigMissingRows($rows),
            default => $this->buildConfigMissingRows($rows),
        };

        $attempts = $this->planRowsToAttempts($rows);

        return [
            'cycle_id' => $payload['cycle_id'] ?? $cycle->id,
            'lead_id' => $payload['lead_id'] ?? $cycle->lead_id,
            'vicidial_lead_id' => $payload['vicidial_lead_id'] ?? null,
            'decision' => $decision,
            'payload' => $payload,
            'attempts' => $attempts,
            'summary' => $this->buildSummary($decision, $attempts),
            'messages' => array_values(array_merge(
                $this->normalizeMessages($payload['messages'] ?? []),
                [
                    'Executor stub produced a dry-run attempt list only.',
                    'No writes or sends were performed.',
                ]
            )),
        ];
    }

    /**
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $rows
     * @return list<array{attempt: int, type: string, operation: string, would_attempt: bool, outcome: 'dry_run_only'|'skipped', details: array<string, mixed>}>
     */
    private function planRowsToAttempts(array $rows): array
    {
        $attempts = [];
        foreach ($rows as $index => $row) {
            $wouldAttempt = ($row['status'] ?? '') === 'would_run';
            $attempts[] = [
                'attempt' => $index + 1,
                'type' => (string) ($row['type'] ?? ''),
                'operation' => (string) ($row['action'] ?? ''),
                'would_attempt' => $wouldAttempt,
                'outcome' => $wouldAttempt ? 'dry_run_only' : 'skipped',
                'details' => is_array($row['details'] ?? null) ? $row['details'] : [],
            ];
        }

        return $attempts;
    }

    /**
     * @param  list<array{attempt: int, type: string, operation: string, would_attempt: bool, outcome: 'dry_run_only'|'skipped', details: array<string, mixed>}>  $attempts
     * @return array{
     *     attempt_count: int,
     *     would_attempt_count: int,
     *     skipped_count: int,
     *     dry_run_only_count: int,
     *     decision_path: string
     * }
     */
    private function buildSummary(string $decision, array $attempts): array
    {
        return [
            'attempt_count' => count($attempts),
            'would_attempt_count' => count(array_filter($attempts, fn (array $a): bool => $a['would_attempt'])),
            'skipped_count' => count(array_filter($attempts, fn (array $a): bool => ! $a['would_attempt'])),
            'dry_run_only_count' => count(array_filter($attempts, fn (array $a): bool => $a['outcome'] === 'dry_run_only')),
            'decision_path' => $decision,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $rows
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildStopRows(array $payload, array $rows): array
    {
        $buckets = $this->extractBuckets($payload);
        $step = count($rows) + 1;

        if ($buckets['cycle_update']['should_run']) {
            $rows[] = $this->makePlanRow($step++, 'cycle', 'stop_cycle', 'would_run', [
                'operation' => $buckets['cycle_update']['operation'],
                'attributes' => $buckets['cycle_update']['attributes'],
            ]);
        }

        if ($buckets['side_effects']['should_close_pending_tasks']) {
            $rows[] = $this->makePlanRow($step++, 'side_effect', 'close_pending_tasks', 'would_run', [
                'notes' => $buckets['side_effects']['notes'],
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $rows
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildExecuteRows(array $payload, array $rows): array
    {
        $buckets = $this->extractBuckets($payload);
        $step = count($rows) + 1;

        if ($buckets['task_create']['should_run']) {
            $rows[] = $this->makePlanRow($step++, 'task', 'create_remarketing_task', 'would_run', $buckets['task_create']['attributes']);
        }

        if ($buckets['delivery']['should_run']) {
            $rows[] = $this->makePlanRow($step++, 'delivery', 'send_delivery', 'would_run', [
                'channel' => $buckets['delivery']['channel'],
                'template_name' => $buckets['delivery']['template_name'],
                'payload' => $buckets['delivery']['payload'],
            ]);
        }

        if ($buckets['cycle_update']['should_run']) {
            $rows[] = $this->makePlanRow($step++, 'cycle', 'apply_cycle_operation', 'would_run', [
                'operation' => $buckets['cycle_update']['operation'],
                'attributes' => $buckets['cycle_update']['attributes'],
            ]);
        }

        if ($buckets['side_effects']['should_close_pending_tasks']) {
            $rows[] = $this->makePlanRow($step++, 'side_effect', 'close_pending_tasks', 'would_run', [
                'notes' => $buckets['side_effects']['notes'],
            ]);
        }

        if ($buckets['side_effects']['should_set_wip_status']) {
            $rows[] = $this->makePlanRow($step++, 'side_effect', 'set_wip_status', 'would_run', [
                'notes' => $buckets['side_effects']['notes'],
            ]);
        }

        if ($buckets['side_effects']['should_log_reengagement']) {
            $rows[] = $this->makePlanRow($step, 'side_effect', 'log_reengagement', 'would_run', [
                'notes' => $buckets['side_effects']['notes'],
            ]);
        }

        return $rows;
    }

    /**
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $rows
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildWaitRows(array $rows): array
    {
        $rows[] = $this->makePlanRow(count($rows) + 1, 'scheduler', 'wait_until_due', 'would_run');

        return $rows;
    }

    /**
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $rows
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildNoActionRows(array $rows): array
    {
        $rows[] = $this->makePlanRow(count($rows) + 1, 'lifecycle', 'no_action', 'would_run');

        return $rows;
    }

    /**
     * @param  list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>  $rows
     * @return list<array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}>
     */
    private function buildConfigMissingRows(array $rows): array
    {
        $rows[] = $this->makePlanRow(count($rows) + 1, 'config', 'halt_for_configuration_gap', 'would_run');

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{step: int, type: string, action: string, status: 'would_run'|'skipped', details: array<string, mixed>}
     */
    private function makePlanRow(int $step, string $type, string $action, string $status, array $details = []): array
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
