<?php

namespace App\Services;

use App\Models\RemarketingCycle;

/**
 * Read-only normalizer from mutation proposal -> explicit execution buckets.
 * This service prepares payloads for a future executor and performs no writes/sends.
 */
class RemarketingCycleExecutionPayloadBuilder
{
    public function __construct(
        private RemarketingCycleMutationPlanner $mutationPlanner,
    ) {
    }

    /**
     * @return array{
     *     cycle_id: int|null,
     *     lead_id: int|null,
     *     vicidial_lead_id: int|string|null,
     *     decision: 'stop'|'wait'|'execute'|'config_missing'|'no_action',
     *     execution_buckets: array{
     *         cycle_update: array{should_run: bool, operation: 'stop'|'advance'|'complete'|'none', attributes: array<string, mixed>},
     *         task_create: array{should_run: bool, attributes: array<string, mixed>},
     *         delivery: array{should_run: bool, channel: string|null, template_name: string|null, payload: array<string, mixed>},
     *         side_effects: array{
     *             should_close_pending_tasks: bool,
     *             should_set_wip_status: bool,
     *             should_log_reengagement: bool,
     *             notes: list<string>
     *         }
     *     },
     *     proposal: array<string, mixed>,
     *     messages: list<string>
     * }
     */
    public function buildPayload(RemarketingCycle $cycle): array
    {
        $proposal = $this->mutationPlanner->buildProposal($cycle);

        $messages = [];
        $messages = array_merge(
            $messages,
            $this->normalizeMessages($proposal['messages'] ?? [])
        );

        $cycleBucket = $this->normalizeCycleBucket($proposal, $messages);
        $taskBucket = $this->normalizeTaskBucket($proposal, $messages);
        $deliveryBucket = $this->normalizeDeliveryBucket($proposal, $messages);
        $sideEffectsBucket = $this->normalizeSideEffectsBucket($proposal, $messages);

        return [
            'cycle_id' => $proposal['cycle_id'] ?? $cycle->id,
            'lead_id' => $proposal['lead_id'] ?? $cycle->lead_id,
            'vicidial_lead_id' => $proposal['vicidial_lead_id'] ?? null,
            'decision' => $this->normalizeDecision($proposal['decision'] ?? null),
            'execution_buckets' => [
                'cycle_update' => $cycleBucket,
                'task_create' => $taskBucket,
                'delivery' => $deliveryBucket,
                'side_effects' => $sideEffectsBucket,
            ],
            'proposal' => $proposal,
            'messages' => array_values(array_unique($messages)),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  &$messages
     * @return array{should_run: bool, operation: 'stop'|'advance'|'complete'|'none', attributes: array<string, mixed>}
     */
    private function normalizeCycleBucket(array $proposal, array &$messages): array
    {
        $bucket = $this->emptyCycleBucket();
        $raw = $this->extractBucket($proposal, 'cycle');
        if ($raw === []) {
            $messages[] = 'Cycle update bucket missing in proposal; defaulted to no-op.';

            return $bucket;
        }

        $bucket['should_run'] = (bool) ($raw['should_update'] ?? false);
        $operation = $raw['operation'] ?? 'none';
        $bucket['operation'] = in_array($operation, ['stop', 'advance', 'complete', 'none'], true) ? $operation : 'none';
        $bucket['attributes'] = is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [];

        $messages[] = 'Cycle update bucket normalized from proposal payload.';

        return $bucket;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  &$messages
     * @return array{should_run: bool, attributes: array<string, mixed>}
     */
    private function normalizeTaskBucket(array $proposal, array &$messages): array
    {
        $bucket = $this->emptyTaskBucket();
        $raw = $this->extractBucket($proposal, 'task');
        if ($raw === []) {
            $messages[] = 'Task creation bucket missing in proposal; defaulted to no-op.';

            return $bucket;
        }

        $bucket['should_run'] = (bool) ($raw['should_create'] ?? false);
        $bucket['attributes'] = is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [];

        $messages[] = $bucket['should_run']
            ? 'Task creation bucket is populated.'
            : 'Task creation bucket is empty.';

        return $bucket;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  &$messages
     * @return array{should_run: bool, channel: string|null, template_name: string|null, payload: array<string, mixed>}
     */
    private function normalizeDeliveryBucket(array $proposal, array &$messages): array
    {
        $bucket = $this->emptyDeliveryBucket();
        $raw = $this->extractBucket($proposal, 'delivery');
        if ($raw === []) {
            $messages[] = 'Delivery bucket missing in proposal; defaulted to no-op.';

            return $bucket;
        }

        $bucket['should_run'] = (bool) ($raw['should_send'] ?? false);
        $bucket['channel'] = isset($raw['channel']) && $raw['channel'] !== '' ? (string) $raw['channel'] : null;
        $bucket['template_name'] = isset($raw['template_name']) && $raw['template_name'] !== '' ? (string) $raw['template_name'] : null;
        $bucket['payload'] = is_array($raw['payload'] ?? null) ? $raw['payload'] : [];

        $messages[] = $bucket['should_run']
            ? 'Delivery bucket indicates a send action.'
            : 'Delivery bucket indicates no send action.';

        return $bucket;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  &$messages
     * @return array{
     *     should_close_pending_tasks: bool,
     *     should_set_wip_status: bool,
     *     should_log_reengagement: bool,
     *     notes: list<string>
     * }
     */
    private function normalizeSideEffectsBucket(array $proposal, array &$messages): array
    {
        $bucket = $this->emptySideEffectsBucket();
        $raw = $this->extractBucket($proposal, 'side_effects');
        if ($raw === []) {
            $messages[] = 'Side effects bucket missing in proposal; defaulted to safe defaults.';

            return $bucket;
        }

        $bucket['should_close_pending_tasks'] = (bool) ($raw['should_close_pending_tasks'] ?? false);
        $bucket['should_set_wip_status'] = (bool) ($raw['should_set_wip_status'] ?? false);
        $bucket['should_log_reengagement'] = (bool) ($raw['should_log_reengagement'] ?? false);
        $bucket['notes'] = $this->normalizeStringList($raw['notes'] ?? []);

        return $bucket;
    }

    /**
     * @param  mixed  $decision
     * @return 'stop'|'wait'|'execute'|'config_missing'|'no_action'
     */
    private function normalizeDecision(mixed $decision): string
    {
        $allowed = ['stop', 'wait', 'execute', 'config_missing', 'no_action'];
        if (is_string($decision) && in_array($decision, $allowed, true)) {
            return $decision;
        }

        return 'config_missing';
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function extractBucket(array $proposal, string $key): array
    {
        $mutations = $proposal['proposed_mutations'] ?? null;
        if (! is_array($mutations)) {
            return [];
        }

        $bucket = $mutations[$key] ?? null;

        return is_array($bucket) ? $bucket : [];
    }

    /**
     * @param  mixed  $messages
     * @return list<string>
     */
    private function normalizeMessages(mixed $messages): array
    {
        return $this->normalizeStringList($messages);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return array_values($out);
    }

    /**
     * @return array{should_run: false, operation: 'none', attributes: array<string, mixed>}
     */
    private function emptyCycleBucket(): array
    {
        return [
            'should_run' => false,
            'operation' => 'none',
            'attributes' => [],
        ];
    }

    /**
     * @return array{should_run: false, attributes: array<string, mixed>}
     */
    private function emptyTaskBucket(): array
    {
        return [
            'should_run' => false,
            'attributes' => [],
        ];
    }

    /**
     * @return array{should_run: false, channel: null, template_name: null, payload: array<string, mixed>}
     */
    private function emptyDeliveryBucket(): array
    {
        return [
            'should_run' => false,
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
    private function emptySideEffectsBucket(): array
    {
        return [
            'should_close_pending_tasks' => false,
            'should_set_wip_status' => false,
            'should_log_reengagement' => false,
            'notes' => [],
        ];
    }
}
