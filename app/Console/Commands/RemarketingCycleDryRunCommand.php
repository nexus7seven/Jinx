<?php

namespace App\Console\Commands;

use App\Models\RemarketingCycle;
use App\Models\RemarketingStepDefinition;
use App\Services\RemarketingCycleService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RemarketingCycleDryRunCommand extends Command
{
    protected $signature = 'remarketing:cycle-dry-run
                            {--flow= : Filter by flow_key}
                            {--status= : Filter by cycle status}
                            {--lead= : Filter by local lead id}
                            {--limit=50 : Max cycles to scan}';

    protected $description = 'Read-only report: what the V2 remarketing cycle layer would infer (no writes, no actions).';

    public function handle(RemarketingCycleService $cycles): int
    {
        $limitOpt = $this->option('limit');
        $limit = is_numeric($limitOpt) ? (int) $limitOpt : 50;
        if ($limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::FAILURE;
        }
        if ($limit > 500) {
            $this->error('--limit cannot exceed 500.');

            return self::FAILURE;
        }

        $flow = $this->option('flow');
        $flow = is_string($flow) && $flow !== '' ? $flow : null;

        $status = $this->option('status');
        $status = is_string($status) && $status !== '' ? $status : null;

        $leadIdOpt = $this->option('lead');
        $leadId = null;
        if ($leadIdOpt !== null && $leadIdOpt !== '') {
            if (! is_numeric($leadIdOpt)) {
                $this->error('--lead must be a numeric id.');

                return self::FAILURE;
            }
            $leadId = (int) $leadIdOpt;
        }

        $query = RemarketingCycle::query()->with('lead');

        if ($flow !== null) {
            $query->where('flow_key', $flow);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($leadId !== null) {
            $query->where('lead_id', $leadId);
        }

        $this->applyReportOrdering($query);

        $cyclesList = $query->limit($limit)->get();

        $this->line('Remarketing cycle dry-run (read-only)');
        $this->line('Filters: flow=' . ($flow ?? '(any)') . ', status=' . ($status ?? '(any)') . ', lead=' . ($leadId !== null ? (string) $leadId : '(any)') . ', limit=' . $limit);
        $this->newLine();

        $scanned = 0;
        $activeCount = 0;
        $dueCount = 0;
        $stopCount = 0;
        $terminalCount = 0;
        $missingStepCount = 0;
        $noFlowStepsCount = 0;

        foreach ($cyclesList as $cycle) {
            $scanned++;

            $currentStep = $cycles->getCurrentStepDefinition($cycle);
            $nextStep = $cycles->getNextStepDefinition($cycle);
            $firstStep = $cycles->getFirstStepDefinition((string) ($cycle->flow_key ?? ''));

            $isDue = $cycles->isStepDue($cycle);
            $stopEval = $cycles->evaluateStopConditions($cycle);
            $isTerminal = $cycles->cycleHasReachedTerminalStep($cycle);

            $recommendation = $this->buildRecommendation(
                $cycle,
                $currentStep,
                $firstStep,
                $isDue,
                $stopEval,
                $cycles
            );

            if ($cycle->status === RemarketingCycle::STATUS_ACTIVE) {
                $activeCount++;
            }
            if ($isDue) {
                $dueCount++;
            }
            if ($stopEval['should_stop']) {
                $stopCount++;
            }
            if ($isTerminal) {
                $terminalCount++;
            }
            if ($currentStep === null && $firstStep === null) {
                $noFlowStepsCount++;
            } elseif ($currentStep === null) {
                $missingStepCount++;
            }

            $vicidial = $this->resolveVicidialLeadId($cycle);

            $this->line('--- Cycle #' . $cycle->id . ' ---');
            $this->line('  cycle id: ' . $cycle->id);
            $this->line('  lead_id: ' . ($cycle->lead_id !== null ? (string) $cycle->lead_id : '—'));
            $this->line('  vicidial_lead_id: ' . ($vicidial ?? '—'));
            $this->line('  flow_key: ' . ($cycle->flow_key ?? '—'));
            $this->line('  status: ' . ($cycle->status ?? '—'));
            $this->line('  current_step_key: ' . ($cycle->current_step_key ?? '—'));
            $this->line('  current_stage: ' . ($cycle->current_stage ?? '—'));
            $this->line('  entered_step_at: ' . $this->formatDt($cycle->entered_step_at));
            $this->line('  next_due_at: ' . $this->formatDt($cycle->next_due_at));
            $this->line('  step is due: ' . ($isDue ? 'yes' : 'no'));
            $this->line('  current step action_type: ' . ($currentStep !== null ? (string) $currentStep->action_type : '—'));
            $this->line('  current step template_name: ' . ($currentStep !== null && $currentStep->template_name !== null ? (string) $currentStep->template_name : '—'));
            $this->line('  manual completion required: ' . ($currentStep !== null ? ($cycles->isManualCompletionRequired($currentStep) ? 'yes' : 'no') : '—'));
            $this->line('  auto_advance_on_send: ' . ($currentStep !== null ? ($cycles->shouldAutoAdvanceOnSend($currentStep) ? 'yes' : 'no') : '—'));
            $this->line('  next step key: ' . ($nextStep !== null ? (string) $nextStep->step_key : '—'));
            $this->line('  at terminal step: ' . ($isTerminal ? 'yes' : 'no'));
            $this->line('  stop evaluation — should_stop: ' . ($stopEval['should_stop'] ? 'true' : 'false'));
            $this->line('  stop evaluation — reason: ' . ($stopEval['reason'] ?? 'null'));
            $this->line('  stop checks — reengaged: ' . ($stopEval['checks']['reengaged'] ? 'true' : 'false'));
            $this->line('  stop checks — dead: ' . ($stopEval['checks']['dead'] ? 'true' : 'false'));
            $this->line('  stop checks — opted_out: ' . ($stopEval['checks']['opted_out'] ? 'true' : 'false'));
            $this->line('  recommendation: ' . $recommendation);
            $this->newLine();
        }

        if ($scanned === 0) {
            $this->comment('No cycles matched the filters.');
        }

        $this->line('--- Summary ---');
        $this->line('cycles scanned: ' . $scanned);
        $this->line('active cycles: ' . $activeCount);
        $this->line('due cycles: ' . $dueCount);
        $this->line('stop candidates: ' . $stopCount);
        $this->line('terminal cycles: ' . $terminalCount);
        $this->line('missing-step cycles: ' . $missingStepCount);
        $this->line('no flow steps (flow has no first step): ' . $noFlowStepsCount);

        return self::SUCCESS;
    }

    /**
     * Active cycles first, then non-null next_due_at before nulls, earliest due first, then id.
     */
    private function applyReportOrdering(Builder $query): void
    {
        $query->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RemarketingCycle::STATUS_ACTIVE])
            ->orderByRaw('CASE WHEN next_due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_due_at')
            ->orderBy('id');
    }

    /**
     * @param  array{should_stop: bool, reason: string|null, checks: array{reengaged: bool, dead: bool, opted_out: bool}}  $stopEval
     */
    private function buildRecommendation(
        RemarketingCycle $cycle,
        ?RemarketingStepDefinition $currentStep,
        ?RemarketingStepDefinition $firstStep,
        bool $isDue,
        array $stopEval,
        RemarketingCycleService $service,
    ): string {
        if ($currentStep === null) {
            if ($firstStep === null) {
                return 'NO FLOW STEPS FOUND';
            }

            return 'CURRENT STEP MISSING';
        }

        if ($stopEval['should_stop']) {
            $reason = $stopEval['reason'] ?? 'unknown';

            return 'STOP cycle (reason: ' . $reason . ')';
        }

        if ($cycle->status !== RemarketingCycle::STATUS_ACTIVE) {
            return 'NO ACTION (cycle not active)';
        }

        if (! $isDue) {
            return 'WAIT until due';
        }

        if ($service->cycleHasReachedTerminalStep($cycle)) {
            return 'WOULD EXECUTE current step; terminal step reached';
        }

        return 'WOULD EXECUTE current step';
    }

    private function resolveVicidialLeadId(RemarketingCycle $cycle): ?string
    {
        $attrs = $cycle->getAttributes();
        if (array_key_exists('vicidial_lead_id', $attrs) && $attrs['vicidial_lead_id'] !== null && $attrs['vicidial_lead_id'] !== '') {
            return (string) $attrs['vicidial_lead_id'];
        }

        $lead = $cycle->lead;
        if ($lead !== null && $lead->vicidial_lead_id !== null && $lead->vicidial_lead_id !== '') {
            return (string) $lead->vicidial_lead_id;
        }

        return null;
    }

    private function formatDt(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return (string) $value;
    }
}
