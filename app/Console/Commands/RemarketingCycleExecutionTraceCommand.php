<?php

namespace App\Console\Commands;

use App\Models\RemarketingCycle;
use App\Services\RemarketingCycleExecutionTraceBuilder;
use Illuminate\Console\Command;
use Throwable;

class RemarketingCycleExecutionTraceCommand extends Command
{
    protected $signature = 'remarketing:cycle-execution-trace
                            {cycleId : Local remarketing_cycles.id}
                            {--json : Pretty-print the raw execution trace as JSON}';

    protected $description = 'Read-only: show RemarketingCycleExecutionTraceBuilder output for one cycle.';

    public function handle(RemarketingCycleExecutionTraceBuilder $traceBuilder): int
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
            $trace = $traceBuilder->buildTrace($cycle);
        } catch (Throwable $e) {
            $this->error('Trace build failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $json = json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $this->error('Failed to encode trace as JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return self::SUCCESS;
        }

        $this->printHumanReport($trace);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $trace
     */
    private function printHumanReport(array $trace): void
    {
        $this->line('Cycle');
        $this->line('  id: ' . $this->formatScalar($trace['cycle_id'] ?? null));
        $this->line('  lead_id: ' . $this->formatScalar($trace['lead_id'] ?? null));
        $this->line('  vicidial_lead_id: ' . $this->formatScalar($trace['vicidial_lead_id'] ?? null));
        $this->line('  decision: ' . $this->formatScalar($trace['decision'] ?? null));
        $this->newLine();

        $this->line('Trace');
        $rows = $trace['trace'] ?? [];
        if (! is_array($rows) || $rows === []) {
            $this->line('  (none)');
        } else {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $step = $this->formatScalar($row['step'] ?? null);
                $type = $this->formatScalar($row['type'] ?? null);
                $action = $this->formatScalar($row['action'] ?? null);
                $status = $this->formatScalar($row['status'] ?? null);

                $this->line($step . '. [' . $type . '] ' . $action . ' — ' . $status);

                $details = is_array($row['details'] ?? null) ? $row['details'] : [];
                if ($details === []) {
                    $this->printIndentLine(1, '(no details)');
                } else {
                    $this->printBulletDetails($details, 1);
                }

                $this->line('');
            }
        }

        $this->line('Summary');
        $summary = is_array($trace['summary'] ?? null) ? $trace['summary'] : [];
        $this->line('  steps_total: ' . $this->formatScalar($summary['steps_total'] ?? null));
        $this->line('  would_run_count: ' . $this->formatScalar($summary['would_run_count'] ?? null));
        $this->line('  skipped_count: ' . $this->formatScalar($summary['skipped_count'] ?? null));
        $this->line('  has_cycle_update: ' . $this->formatBool($summary['has_cycle_update'] ?? null));
        $this->line('  has_task_create: ' . $this->formatBool($summary['has_task_create'] ?? null));
        $this->line('  has_delivery: ' . $this->formatBool($summary['has_delivery'] ?? null));
        $this->line('  has_side_effects: ' . $this->formatBool($summary['has_side_effects'] ?? null));
        $this->newLine();

        $this->line('Payload Snapshot');
        $payload = is_array($trace['payload'] ?? null) ? $trace['payload'] : [];
        $buckets = is_array($payload['execution_buckets'] ?? null) ? $payload['execution_buckets'] : [];
        $cycleUpdate = is_array($buckets['cycle_update'] ?? null) ? $buckets['cycle_update'] : [];
        $taskCreate = is_array($buckets['task_create'] ?? null) ? $buckets['task_create'] : [];
        $delivery = is_array($buckets['delivery'] ?? null) ? $buckets['delivery'] : [];
        $sideEffects = is_array($buckets['side_effects'] ?? null) ? $buckets['side_effects'] : [];
        $this->line('  execution_buckets.cycle_update.operation: ' . $this->formatScalar($cycleUpdate['operation'] ?? null));
        $this->line('  execution_buckets.task_create.should_run: ' . $this->formatBool($taskCreate['should_run'] ?? null));
        $this->line('  execution_buckets.delivery.should_run: ' . $this->formatBool($delivery['should_run'] ?? null));
        $this->line('  execution_buckets.delivery.channel: ' . $this->formatScalar($delivery['channel'] ?? null));
        $this->line('  execution_buckets.side_effects.should_close_pending_tasks: ' . $this->formatBool($sideEffects['should_close_pending_tasks'] ?? null));
        $this->newLine();

        $this->line('Messages');
        $messages = $trace['messages'] ?? [];
        if (! is_array($messages) || $messages === []) {
            $this->line('  (none)');
        } else {
            foreach ($messages as $message) {
                $this->line('  - ' . (is_string($message) ? $message : (string) $message));
            }
        }
    }

    /**
     * @param  array<string|int, mixed>  $data
     */
    private function printBulletDetails(array $data, int $indentLevel): void
    {
        foreach ($data as $key => $value) {
            $label = is_string($key) || is_int($key) ? (string) $key : 'item';

            if (is_array($value)) {
                $this->printIndentLine($indentLevel, '- ' . $label . ':');
                $this->printNestedLines($value, $indentLevel + 1);
            } else {
                $this->printIndentLine($indentLevel, '- ' . $label . ': ' . $this->formatScalar($value));
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
