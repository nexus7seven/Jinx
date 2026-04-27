<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingProgress;
use App\Models\LeadRemarketingStepLog;
use App\Models\RemarketingStep;
use App\Services\RemarketingScheduleWindowService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemarketingLinearExecuteCommand extends Command
{
    protected $signature = 'remarketing:linear-execute
        {--lead_id= : Optional single vicidial lead_id}
        {--limit=50 : Max number of progress rows to inspect}
        {--json : Output JSON instead of human report}
        {--commit : Commit planner decisions to linear progress/log tables}
        {--only-log : Commit mode guard to ensure no external actions are used}';

    protected $description = 'Read-only dry-run execution planner for linear remarketing.';

    public function __construct(
        private readonly RemarketingLinearBrainPreviewCommand $previewCommand,
        private readonly RemarketingScheduleWindowService $scheduleWindowService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $onlyLog = (bool) $this->option('only-log');
        if ($commit && ! $onlyLog) {
            $this->error('Live execution not implemented yet. Use --only-log.');

            return self::FAILURE;
        }

        $steps = RemarketingStep::query()
            ->where('is_active', true)
            ->orderBy('step_order')
            ->get();

        if ($steps->isEmpty()) {
            $this->error('No active remarketing steps found in remarketing_steps.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $leadId = $this->option('lead_id');

        $progressQuery = LeadRemarketingProgress::query()
            ->whereIn('status', ['active', 'waiting']);

        if ($leadId !== null && $leadId !== '') {
            $progressQuery->where('lead_id', (int) $leadId);
        }

        $progressRows = $progressQuery
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($progressRows->isEmpty()) {
            $this->line('No active progress rows found');

            return self::SUCCESS;
        }

        $now = Carbon::now(RemarketingScheduleWindowService::TIMEZONE);
        $rows = [];
        $summary = [
            'total_checked' => 0,
            'due_now' => 0,
            'would_execute' => 0,
            'skipped' => 0,
            'committed' => 0,
            'advanced' => 0,
        ];

        foreach ($progressRows as $progress) {
            $summary['total_checked']++;

            $currentStepOrder = $progress->current_step_order;
            if ($currentStepOrder === null && $progress->current_step_id !== null) {
                $currentStepOrder = optional($steps->firstWhere('id', $progress->current_step_id))->step_order;
            }

            $nextStep = $this->previewCommand->getNextStep($steps, $currentStepOrder);
            $baseTime = $this->previewCommand->resolveBaseTime($progress);

            $dueAt = null;
            $nextAllowedTime = null;
            $isDueNow = false;
            $isAllowedNow = false;

            if ($nextStep !== null) {
                $dueAt = $baseTime->copy()->addMinutes((int) $nextStep->delay_minutes);
                $nextAllowedTime = $this->scheduleWindowService->nextAllowedTime($nextStep, $dueAt->copy());
                $isDueNow = $now->greaterThanOrEqualTo($nextAllowedTime);
                $isAllowedNow = $this->scheduleWindowService->isAllowedNow($nextStep, $now->copy());
            }

            $executionAction = $this->resolveExecutionAction(
                progressStatus: (string) $progress->status,
                nextStep: $nextStep,
                isDueNow: $isDueNow
            );
            $commitAction = 'dry_run_no_change';

            if ($isDueNow) {
                $summary['due_now']++;
            }

            if (str_starts_with($executionAction, 'would_')) {
                $summary['would_execute']++;
            } else {
                $summary['skipped']++;
            }

            if ($commit && $this->isCommitEligible($executionAction, $isDueNow) && $nextStep !== null) {
                $commitResult = $this->commitStepDecision(
                    progress: $progress,
                    currentStep: $nextStep,
                    allSteps: $steps,
                    executionAction: $executionAction,
                    dueAt: $dueAt,
                    now: $now
                );

                $commitAction = $commitResult['commit_action'];
                if ($commitResult['committed']) {
                    $summary['committed']++;
                }
                if ($commitResult['advanced']) {
                    $summary['advanced']++;
                }
            }

            $rows[] = [
                'lead_id' => (int) $progress->lead_id,
                'progress_status' => $progress->status,
                'next_step_order' => $nextStep?->step_order,
                'next_step_key' => $nextStep?->step_key,
                'medium' => $nextStep?->medium,
                'due_at' => $dueAt?->format('Y-m-d H:i:s'),
                'next_allowed_time' => $nextAllowedTime?->format('Y-m-d H:i:s'),
                'is_due_now' => $isDueNow,
                'is_allowed_now' => $isAllowedNow,
                'execution_action' => $executionAction,
                'commit_action' => $commitAction,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $this->line('Lead ID: '.$row['lead_id']);
                $this->line('Progress status: '.$row['progress_status']);
                $this->line('Next step: '.($row['next_step_order'] !== null ? $row['next_step_order'].' '.$row['next_step_key'] : 'none'));
                $this->line('Medium: '.($row['medium'] ?? 'n/a'));
                $this->line('Due at: '.($row['due_at'] ?? 'n/a'));
                $this->line('Next allowed time: '.($row['next_allowed_time'] ?? 'n/a'));
                $this->line('Due now: '.($row['is_due_now'] ? 'yes' : 'no'));
                $this->line('Allowed now: '.($row['is_allowed_now'] ? 'yes' : 'no'));
                $this->line('Execution decision: '.$row['execution_action']);
                $this->line('Commit action: '.$row['commit_action']);
                $this->line('');
                $this->line(str_repeat('-', 40));
                $this->line('');
            }

            $this->line('Total checked: '.$summary['total_checked']);
            $this->line('Due now: '.$summary['due_now']);
            $this->line('Would execute: '.$summary['would_execute']);
            $this->line('Skipped: '.$summary['skipped']);
            $this->line('Committed: '.$summary['committed']);
            $this->line('Advanced: '.$summary['advanced']);
        }

        return self::SUCCESS;
    }

    private function isCommitEligible(string $executionAction, bool $isDueNow): bool
    {
        if (! $isDueNow) {
            return false;
        }

        return in_array($executionAction, [
            'would_send_sms',
            'would_send_email',
            'would_send_or_queue_whatsapp',
            'would_queue_call_task',
        ], true);
    }

    private function commitStepDecision(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        $allSteps,
        string $executionAction,
        ?Carbon $dueAt,
        Carbon $now
    ): array {
        return DB::transaction(function () use ($progress, $currentStep, $allSteps, $executionAction, $dueAt, $now): array {
            $startedAt = $now->copy();
            $completedAt = $now->copy();

            if ($currentStep->requires_manual_completion) {
                LeadRemarketingStepLog::query()->create([
                    'lead_id' => $progress->lead_id,
                    'remarketing_step_id' => $currentStep->id,
                    'step_order' => $currentStep->step_order,
                    'medium' => $currentStep->medium,
                    'template_id' => $currentStep->template_id,
                    'status' => 'queued_task',
                    'due_at' => $dueAt,
                    'started_at' => $startedAt,
                    'completed_at' => null,
                    'failed_at' => null,
                    'provider_message_id' => null,
                    'error_message' => null,
                    'created_task_id' => null,
                    'context_json' => [
                        'mode' => 'commit_only_log',
                        'execution_action' => $executionAction,
                        'note' => 'no external action performed',
                    ],
                ]);

                $progress->update([
                    'current_step_id' => $currentStep->id,
                    'current_step_order' => $currentStep->step_order,
                    'status' => 'pending_manual_task',
                    'next_step_due_at' => $dueAt,
                ]);

                return [
                    'committed' => true,
                    'advanced' => false,
                    'commit_action' => 'logged_manual_pending',
                ];
            }

            LeadRemarketingStepLog::query()->create([
                'lead_id' => $progress->lead_id,
                'remarketing_step_id' => $currentStep->id,
                'step_order' => $currentStep->step_order,
                'medium' => $currentStep->medium,
                'template_id' => $currentStep->template_id,
                'status' => 'completed',
                'due_at' => $dueAt,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'failed_at' => null,
                'provider_message_id' => null,
                'error_message' => null,
                'created_task_id' => null,
                'context_json' => [
                    'mode' => 'commit_only_log',
                    'execution_action' => $executionAction,
                    'note' => 'no external action performed',
                ],
            ]);

            $followingStep = $allSteps->first(
                static fn (RemarketingStep $step): bool => $step->step_order > $currentStep->step_order
            );

            $updates = [
                'current_step_id' => $currentStep->id,
                'current_step_order' => $currentStep->step_order,
                'last_step_completed_at' => $completedAt,
                'status' => 'active',
            ];

            if ($followingStep === null) {
                $updates['status'] = 'completed';
                $updates['next_step_due_at'] = null;
            } else {
                $rawNextDue = $completedAt->copy()->addMinutes((int) $followingStep->delay_minutes);
                $updates['next_step_due_at'] = $this->scheduleWindowService->nextAllowedTime($followingStep, $rawNextDue);
            }

            $progress->update($updates);

            return [
                'committed' => true,
                'advanced' => true,
                'commit_action' => 'logged_and_advanced',
            ];
        });
    }

    private function resolveExecutionAction(string $progressStatus, ?RemarketingStep $nextStep, bool $isDueNow): string
    {
        if ($progressStatus === 'pending_manual_task') {
            return 'skip_manual_pending';
        }

        if ($nextStep === null) {
            return 'would_mark_completed';
        }

        if (! $isDueNow) {
            return 'skip_not_due';
        }

        return match ($nextStep->medium) {
            'call' => 'would_queue_call_task',
            'sms' => 'would_send_sms',
            'email' => 'would_send_email',
            'whatsapp' => 'would_send_or_queue_whatsapp',
            default => 'unknown_medium',
        };
    }
}
