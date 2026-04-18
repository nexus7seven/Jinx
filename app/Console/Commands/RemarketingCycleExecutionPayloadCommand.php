<?php

namespace App\Console\Commands;

use App\Models\RemarketingCycle;
use App\Services\RemarketingCycleExecutionPayloadBuilder;
use Illuminate\Console\Command;
use Throwable;

class RemarketingCycleExecutionPayloadCommand extends Command
{
    protected $signature = 'remarketing:cycle-execution-payload
                            {cycleId : Local remarketing_cycles.id}
                            {--json : Pretty-print the raw execution payload as JSON}';

    protected $description = 'Read-only: show RemarketingCycleExecutionPayloadBuilder output for one cycle.';

    public function handle(RemarketingCycleExecutionPayloadBuilder $payloadBuilder): int
    {
        $raw = $this->argument('cycleId');
        if (! is_numeric($raw)) {
            $this->error('cycleId must be a positive integer.');

            return self::FAILURE;
        }

        $cycleId = (int) $raw;
        if ($cycleId < 1) {
            $this->error('cycleId must be a positive integer.');

            return self::FAILURE;
        }

        $cycle = RemarketingCycle::with('lead')->find($cycleId);
        if ($cycle === null) {
            $this->error("Remarketing cycle {$cycleId} was not found.");

            return self::FAILURE;
        }

        try {
            $payload = $payloadBuilder->buildPayload($cycle);
        } catch (Throwable $e) {
            $this->error('Payload build failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $this->error('Failed to encode payload as JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return self::SUCCESS;
        }

        $this->printHumanReport($payload);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function printHumanReport(array $payload): void
    {
        $this->line('Cycle');
        $this->line('  id: ' . $this->formatScalar($payload['cycle_id'] ?? null));
        $this->line('  lead_id: ' . $this->formatScalar($payload['lead_id'] ?? null));
        $this->line('  vicidial_lead_id: ' . $this->formatScalar($payload['vicidial_lead_id'] ?? null));
        $this->line('  decision: ' . $this->formatScalar($payload['decision'] ?? null));
        $this->newLine();

        $buckets = is_array($payload['execution_buckets'] ?? null) ? $payload['execution_buckets'] : [];

        $this->line('Execution Buckets');

        $this->line('  Cycle Update');
        $cycleUp = is_array($buckets['cycle_update'] ?? null) ? $buckets['cycle_update'] : [];
        $this->line('    should_run: ' . $this->formatBool($cycleUp['should_run'] ?? null));
        $this->line('    operation: ' . $this->formatScalar($cycleUp['operation'] ?? null));
        $this->line('    attributes:');
        $this->printNestedLines(is_array($cycleUp['attributes'] ?? null) ? $cycleUp['attributes'] : [], 3);

        $this->line('  Task Create');
        $task = is_array($buckets['task_create'] ?? null) ? $buckets['task_create'] : [];
        $this->line('    should_run: ' . $this->formatBool($task['should_run'] ?? null));
        $this->line('    attributes:');
        $this->printNestedLines(is_array($task['attributes'] ?? null) ? $task['attributes'] : [], 3);

        $this->line('  Delivery');
        $delivery = is_array($buckets['delivery'] ?? null) ? $buckets['delivery'] : [];
        $this->line('    should_run: ' . $this->formatBool($delivery['should_run'] ?? null));
        $this->line('    channel: ' . $this->formatScalar($delivery['channel'] ?? null));
        $this->line('    template_name: ' . $this->formatScalar($delivery['template_name'] ?? null));
        $this->line('    payload:');
        $this->printNestedLines(is_array($delivery['payload'] ?? null) ? $delivery['payload'] : [], 3);

        $this->line('  Side Effects');
        $side = is_array($buckets['side_effects'] ?? null) ? $buckets['side_effects'] : [];
        $this->line('    should_close_pending_tasks: ' . $this->formatBool($side['should_close_pending_tasks'] ?? null));
        $this->line('    should_set_wip_status: ' . $this->formatBool($side['should_set_wip_status'] ?? null));
        $this->line('    should_log_reengagement: ' . $this->formatBool($side['should_log_reengagement'] ?? null));
        $this->line('    notes:');
        $notes = $side['notes'] ?? [];
        if (! is_array($notes) || $notes === []) {
            $this->printIndentLine(3, '(none)');
        } else {
            foreach ($notes as $note) {
                $this->printIndentLine(3, '- ' . (is_string($note) ? $note : (string) $note));
            }
        }
        $this->newLine();

        $this->line('Proposal Snapshot');
        $proposal = is_array($payload['proposal'] ?? null) ? $payload['proposal'] : [];
        $planner = is_array($proposal['planner'] ?? null) ? $proposal['planner'] : [];
        $mutations = is_array($proposal['proposed_mutations'] ?? null) ? $proposal['proposed_mutations'] : [];
        $propCycle = is_array($mutations['cycle'] ?? null) ? $mutations['cycle'] : [];
        $propTask = is_array($mutations['task'] ?? null) ? $mutations['task'] : [];
        $propDelivery = is_array($mutations['delivery'] ?? null) ? $mutations['delivery'] : [];
        $this->line('  planner.decision: ' . $this->formatScalar($planner['decision'] ?? null));
        $this->line('  proposed_mutations.cycle.operation: ' . $this->formatScalar($propCycle['operation'] ?? null));
        $this->line('  proposed_mutations.task.should_create: ' . $this->formatBool($propTask['should_create'] ?? null));
        $this->line('  proposed_mutations.delivery.should_send: ' . $this->formatBool($propDelivery['should_send'] ?? null));
        $this->newLine();

        $this->line('Messages');
        $messages = $payload['messages'] ?? [];
        if (! is_array($messages) || $messages === []) {
            $this->line('  (none)');
        } else {
            foreach ($messages as $msg) {
                $this->line('  - ' . (is_string($msg) ? $msg : (string) $msg));
            }
        }
    }

    /**
     * @param  array<string|int, mixed>  $data
     */
    private function printNestedLines(array $data, int $indentLevel): void
    {
        if ($data === []) {
            $this->printIndentLine($indentLevel, '(empty)');

            return;
        }

        foreach ($data as $key => $value) {
            $label = is_string($key) || is_int($key) ? (string) $key : 'item';
            if (is_array($value)) {
                $this->printIndentLine($indentLevel, $label . ':');
                $this->printNestedLines($value, $indentLevel + 1);
            } else {
                $this->printIndentLine($indentLevel, $label . ': ' . $this->formatScalar($value));
            }
        }
    }

    private function printIndentLine(int $level, string $text): void
    {
        $this->line(str_repeat('  ', $level) . $text);
    }

    private function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function formatBool(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return $value ? 'true' : 'false';
    }
}
