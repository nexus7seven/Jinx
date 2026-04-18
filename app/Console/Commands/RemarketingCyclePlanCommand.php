<?php

namespace App\Console\Commands;

use App\Models\RemarketingCycle;
use App\Services\RemarketingCycleExecutionPlanner;
use Illuminate\Console\Command;
use Throwable;

class RemarketingCyclePlanCommand extends Command
{
    protected $signature = 'remarketing:cycle-plan
                            {cycleId : Local remarketing_cycles.id}
                            {--json : Dump raw planner array as pretty JSON}';

    protected $description = 'Read-only: show RemarketingCycleExecutionPlanner output for one cycle.';

    public function handle(RemarketingCycleExecutionPlanner $planner): int
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
            $plan = $planner->plan($cycle);
        } catch (Throwable $e) {
            $this->error('Planner failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $this->error('Failed to encode plan as JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return self::SUCCESS;
        }

        $this->printHumanReport($cycle, $plan);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function printHumanReport(RemarketingCycle $cycle, array $plan): void
    {
        $this->line('Cycle');
        $this->line('  id: ' . $cycle->id);
        $this->line('  lead_id: ' . ($cycle->lead_id !== null ? (string) $cycle->lead_id : '—'));
        $this->line('  vicidial_lead_id: ' . $this->formatScalar($plan['vicidial_lead_id'] ?? null));
        $this->line('  flow_key: ' . $this->formatScalar($cycle->flow_key));
        $this->line('  cycle_status: ' . $this->formatScalar($cycle->status));
        $this->line('  current_step_key: ' . $this->formatScalar($cycle->current_step_key));
        $this->line('  current_stage: ' . $this->formatScalar($cycle->current_stage));
        $this->line('  entered_step_at: ' . $this->formatDt($cycle->entered_step_at));
        $this->line('  next_due_at: ' . $this->formatDt($cycle->next_due_at));
        $this->newLine();

        $this->line('Current Step');
        $current = $plan['current_step'] ?? null;
        if (! is_array($current)) {
            $this->line('  (none — no active step definition resolved for this cycle.)');
        } else {
            $this->line('  step_key: ' . $this->formatScalar($current['step_key'] ?? null));
            $this->line('  sequence: ' . $this->formatScalar($current['sequence'] ?? null));
            $this->line('  stage: ' . $this->formatScalar($current['stage'] ?? null));
            $this->line('  action_type: ' . $this->formatScalar($current['action_type'] ?? null));
            $this->line('  template_name: ' . $this->formatScalar($current['template_name'] ?? null));
            $this->line('  delay_minutes: ' . $this->formatScalar($current['delay_minutes'] ?? null));
            $this->line('  requires_manual_completion: ' . $this->formatBool($current['requires_manual_completion'] ?? null));
            $this->line('  auto_advance_on_send: ' . $this->formatBool($current['auto_advance_on_send'] ?? null));
        }
        $this->newLine();

        $this->line('Next Step');
        $next = $plan['next_step'] ?? null;
        if (! is_array($next)) {
            $this->line('  (none — no following step in this flow.)');
        } else {
            $this->line('  step_key: ' . $this->formatScalar($next['step_key'] ?? null));
            $this->line('  sequence: ' . $this->formatScalar($next['sequence'] ?? null));
            $this->line('  stage: ' . $this->formatScalar($next['stage'] ?? null));
            $this->line('  action_type: ' . $this->formatScalar($next['action_type'] ?? null));
            $this->line('  template_name: ' . $this->formatScalar($next['template_name'] ?? null));
        }
        $this->newLine();

        $this->line('Decision');
        $this->line('  due: ' . $this->formatBool($plan['due'] ?? null));
        $this->line('  terminal: ' . $this->formatBool($plan['terminal'] ?? null));
        $this->line('  decision: ' . $this->formatScalar($plan['decision'] ?? null));
        $rec = $plan['recommended_action'] ?? [];
        if (! is_array($rec)) {
            $rec = [];
        }
        $this->line('  recommended_action.type: ' . $this->formatScalar($rec['type'] ?? null));
        $this->line('  recommended_action.reason: ' . $this->formatScalar($rec['reason'] ?? null));
        $this->line('  recommended_action.step_key: ' . $this->formatScalar($rec['step_key'] ?? null));
        $this->line('  recommended_action.action_type: ' . $this->formatScalar($rec['action_type'] ?? null));
        $this->line('  recommended_action.template_name: ' . $this->formatScalar($rec['template_name'] ?? null));
        $this->newLine();

        $this->line('Stop Evaluation');
        $stop = $plan['stop_evaluation'] ?? [];
        if (! is_array($stop)) {
            $stop = [];
        }
        $checks = is_array($stop['checks'] ?? null) ? $stop['checks'] : [];
        $this->line('  should_stop: ' . $this->formatBool($stop['should_stop'] ?? null));
        $this->line('  reason: ' . $this->formatScalar($stop['reason'] ?? null));
        $this->line('  reengaged: ' . $this->formatBool($checks['reengaged'] ?? null));
        $this->line('  dead: ' . $this->formatBool($checks['dead'] ?? null));
        $this->line('  opted_out: ' . $this->formatBool($checks['opted_out'] ?? null));
        $this->newLine();

        $this->line('Post-Action Expectation');
        $post = $plan['post_action_expectation'] ?? [];
        if (! is_array($post)) {
            $post = [];
        }
        $this->line('  would_create_task: ' . $this->formatBool($post['would_create_task'] ?? null));
        $this->line('  would_require_manual_completion: ' . $this->formatBool($post['would_require_manual_completion'] ?? null));
        $this->line('  would_auto_advance_on_send: ' . $this->formatBool($post['would_auto_advance_on_send'] ?? null));
        $this->line('  would_be_terminal_after_execution: ' . $this->formatBool($post['would_be_terminal_after_execution'] ?? null));
        $this->line('  next_step_key_after_execution: ' . $this->formatScalar($post['next_step_key_after_execution'] ?? null));
        $this->newLine();

        $this->line('Messages');
        $messages = $plan['messages'] ?? [];
        if (! is_array($messages) || $messages === []) {
            $this->line('  (none)');
        } else {
            foreach ($messages as $msg) {
                $this->line('  - ' . (is_string($msg) ? $msg : (string) $msg));
            }
        }
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

    private function formatDt(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return (string) $value;
    }
}
