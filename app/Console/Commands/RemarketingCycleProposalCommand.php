<?php

namespace App\Console\Commands;

use App\Models\RemarketingCycle;
use App\Services\RemarketingCycleMutationPlanner;
use Illuminate\Console\Command;
use Throwable;

class RemarketingCycleProposalCommand extends Command
{
    protected $signature = 'remarketing:cycle-proposal
                            {cycleId : Local remarketing_cycles.id}
                            {--json : Pretty-print the raw proposal payload as JSON}';

    protected $description = 'Read-only: show RemarketingCycleMutationPlanner proposal for one cycle.';

    public function handle(RemarketingCycleMutationPlanner $mutationPlanner): int
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
            $proposal = $mutationPlanner->buildProposal($cycle);
        } catch (Throwable $e) {
            $this->error('Proposal build failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $json = json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $this->error('Failed to encode proposal as JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return self::SUCCESS;
        }

        $this->printHumanReport($proposal);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function printHumanReport(array $proposal): void
    {
        $this->line('Cycle');
        $this->line('  id: ' . $this->formatScalar($proposal['cycle_id'] ?? null));
        $this->line('  lead_id: ' . $this->formatScalar($proposal['lead_id'] ?? null));
        $this->line('  vicidial_lead_id: ' . $this->formatScalar($proposal['vicidial_lead_id'] ?? null));
        $this->line('  decision: ' . $this->formatScalar($proposal['decision'] ?? null));
        $this->newLine();

        $this->line('Planner Snapshot');
        $planner = is_array($proposal['planner'] ?? null) ? $proposal['planner'] : [];
        $current = is_array($planner['current_step'] ?? null) ? $planner['current_step'] : [];
        $next = is_array($planner['next_step'] ?? null) ? $planner['next_step'] : [];
        $stop = is_array($planner['stop_evaluation'] ?? null) ? $planner['stop_evaluation'] : [];
        $this->line('  flow_key: ' . $this->formatScalar($planner['flow_key'] ?? null));
        $this->line('  cycle_status: ' . $this->formatScalar($planner['cycle_status'] ?? null));
        $this->line('  due: ' . $this->formatBool($planner['due'] ?? null));
        $this->line('  terminal: ' . $this->formatBool($planner['terminal'] ?? null));
        $this->line('  current_step.step_key: ' . $this->formatScalar($current['step_key'] ?? null));
        $this->line('  current_step.action_type: ' . $this->formatScalar($current['action_type'] ?? null));
        $this->line('  current_step.template_name: ' . $this->formatScalar($current['template_name'] ?? null));
        $this->line('  next_step.step_key: ' . $this->formatScalar($next['step_key'] ?? null));
        $this->line('  stop_evaluation.should_stop: ' . $this->formatBool($stop['should_stop'] ?? null));
        $this->line('  stop_evaluation.reason: ' . $this->formatScalar($stop['reason'] ?? null));
        $this->newLine();

        $mutations = is_array($proposal['proposed_mutations'] ?? null) ? $proposal['proposed_mutations'] : [];

        $this->line('Proposed Cycle Mutation');
        $cycleMut = is_array($mutations['cycle'] ?? null) ? $mutations['cycle'] : [];
        $this->line('  should_update: ' . $this->formatBool($cycleMut['should_update'] ?? null));
        $this->line('  operation: ' . $this->formatScalar($cycleMut['operation'] ?? null));
        $this->line('  attributes:');
        $this->printNestedLines($cycleMut['attributes'] ?? [], 2);
        $this->newLine();

        $this->line('Proposed Task Mutation');
        $taskMut = is_array($mutations['task'] ?? null) ? $mutations['task'] : [];
        $this->line('  should_create: ' . $this->formatBool($taskMut['should_create'] ?? null));
        $taskAttrs = is_array($taskMut['attributes'] ?? null) ? $taskMut['attributes'] : [];
        $this->line('  attributes:');
        $this->printTaskAttributesHighlighted($taskAttrs, 2);
        $this->newLine();

        $this->line('Proposed Delivery Mutation');
        $deliveryMut = is_array($mutations['delivery'] ?? null) ? $mutations['delivery'] : [];
        $this->line('  should_send: ' . $this->formatBool($deliveryMut['should_send'] ?? null));
        $this->line('  channel: ' . $this->formatScalar($deliveryMut['channel'] ?? null));
        $this->line('  template_name: ' . $this->formatScalar($deliveryMut['template_name'] ?? null));
        $this->line('  payload:');
        $this->printNestedLines($deliveryMut['payload'] ?? [], 2);
        $this->newLine();

        $this->line('Proposed Side Effects');
        $side = is_array($mutations['side_effects'] ?? null) ? $mutations['side_effects'] : [];
        $this->line('  should_close_pending_tasks: ' . $this->formatBool($side['should_close_pending_tasks'] ?? null));
        $this->line('  should_set_wip_status: ' . $this->formatBool($side['should_set_wip_status'] ?? null));
        $this->line('  should_log_reengagement: ' . $this->formatBool($side['should_log_reengagement'] ?? null));
        $this->line('  notes:');
        $notes = $side['notes'] ?? [];
        if (! is_array($notes) || $notes === []) {
            $this->line('    (none)');
        } else {
            foreach ($notes as $note) {
                $this->line('    - ' . (is_string($note) ? $note : (string) $note));
            }
        }
        $this->newLine();

        $this->line('Messages');
        $messages = $proposal['messages'] ?? [];
        if (! is_array($messages) || $messages === []) {
            $this->line('  (none)');
        } else {
            foreach ($messages as $msg) {
                $this->line('  - ' . (is_string($msg) ? $msg : (string) $msg));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function printTaskAttributesHighlighted(array $attrs, int $indentLevel): void
    {
        if ($attrs === []) {
            $this->printIndentLine($indentLevel, '(empty)');

            return;
        }

        $order = ['lead_id', 'stage', 'task_type', 'reason', 'status', 'template_name', 'source', 'meta'];
        $printed = [];

        foreach ($order as $key) {
            if (! array_key_exists($key, $attrs)) {
                continue;
            }
            $printed[$key] = true;
            if ($key === 'meta') {
                $this->printIndentLine($indentLevel, 'meta:');
                $this->printNestedLines(is_array($attrs[$key]) ? $attrs[$key] : [], $indentLevel + 1);
            } else {
                $this->printIndentLine($indentLevel, $key . ': ' . $this->formatScalar($attrs[$key]));
            }
        }

        foreach ($attrs as $key => $value) {
            if (isset($printed[$key])) {
                continue;
            }
            if (is_array($value)) {
                $this->printIndentLine($indentLevel, $key . ':');
                $this->printNestedLines($value, $indentLevel + 1);
            } else {
                $this->printIndentLine($indentLevel, $key . ': ' . $this->formatScalar($value));
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
