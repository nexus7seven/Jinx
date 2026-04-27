<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingStep;
use App\Services\RemarketingScheduleWindowService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RemarketingLinearBrainPreviewCommand extends Command
{
    protected $signature = 'remarketing:linear-brain-preview
        {--lead_id= : Optional vicidial lead_id to preview one lead only}
        {--limit=50 : Maximum number of leads/progress rows to inspect}
        {--json : Output JSON instead of human table/report}';

    protected $description = 'Read-only preview for what the new linear remarketing brain would do.';

    public function __construct(private readonly RemarketingScheduleWindowService $scheduleWindowService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $steps = RemarketingStep::query()
            ->with('template')
            ->where('is_active', true)
            ->orderBy('step_order')
            ->get();

        if ($steps->isEmpty()) {
            $this->warn('No active remarketing steps found in remarketing_steps. Activate at least one step before previewing.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $leadId = $this->option('lead_id');

        $progressQuery = LeadRemarketingProgress::query()
            ->whereIn('status', ['active', 'waiting', 'pending_manual_task']);

        if ($leadId !== null && $leadId !== '') {
            $progressQuery->where('lead_id', (int) $leadId);
        }

        $progressRows = $progressQuery
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($progressRows->isEmpty()) {
            $this->line('No lead_remarketing_progress rows found yet. Seed/create progress rows before previewing live flow.');

            return self::SUCCESS;
        }

        $now = Carbon::now(RemarketingScheduleWindowService::TIMEZONE);
        $rows = [];

        foreach ($progressRows as $progress) {
            $currentStepOrder = $progress->current_step_order;
            if ($currentStepOrder === null && $progress->current_step_id !== null) {
                $currentStepOrder = optional($steps->firstWhere('id', $progress->current_step_id))->step_order;
            }

            $nextStep = $this->getNextStep($steps, $currentStepOrder);
            $baseTime = $this->resolveBaseTime($progress);

            $rows[] = $this->buildPreviewRow(
                progress: $progress,
                nextStep: $nextStep,
                currentStepOrder: $currentStepOrder,
                baseTime: $baseTime,
                now: $now
            );
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printHumanReport($rows);
        }

        return self::SUCCESS;
    }

    public function getNextStep(Collection $steps, ?int $currentStepOrder): ?RemarketingStep
    {
        if ($currentStepOrder === null) {
            return $steps->first();
        }

        return $steps->first(static fn (RemarketingStep $step): bool => $step->step_order > $currentStepOrder);
    }

    public function resolveBaseTime(LeadRemarketingProgress $progress): Carbon
    {
        $base = $progress->current_step_order === null
            ? ($progress->started_at ?? $progress->created_at)
            : ($progress->last_step_completed_at ?? $progress->updated_at ?? $progress->created_at);

        return ($base ?? Carbon::now(RemarketingScheduleWindowService::TIMEZONE))
            ->copy()
            ->setTimezone(RemarketingScheduleWindowService::TIMEZONE);
    }

    public function buildPreviewRow(
        LeadRemarketingProgress $progress,
        ?RemarketingStep $nextStep,
        ?int $currentStepOrder,
        Carbon $baseTime,
        Carbon $now
    ): array {
        $rawDueAt = null;
        $nextAllowedTime = null;
        $isDueNow = false;
        $isAllowedNow = false;
        $previewAction = 'would_mark_completed_later';

        if ($nextStep !== null) {
            $rawDueAt = $baseTime->copy()->addMinutes((int) $nextStep->delay_minutes);
            $nextAllowedTime = $this->scheduleWindowService->nextAllowedTime($nextStep, $rawDueAt->copy());
            $isDueNow = $now->greaterThanOrEqualTo($nextAllowedTime);
            $isAllowedNow = $this->scheduleWindowService->isAllowedNow($nextStep, $now->copy());

            if ($progress->status === 'pending_manual_task') {
                $previewAction = 'waiting_for_manual_completion';
            } elseif (! $isDueNow) {
                $previewAction = 'not_due_yet';
            } else {
                $previewAction = match ($nextStep->medium) {
                    'call' => 'would_queue_call_task',
                    'sms' => 'would_send_sms',
                    'email' => 'would_send_email',
                    'whatsapp' => 'would_send_or_queue_whatsapp',
                    default => 'unknown_medium',
                };
            }
        } elseif ($progress->status === 'pending_manual_task') {
            $previewAction = 'waiting_for_manual_completion';
        }

        return [
            'lead_id' => (int) $progress->lead_id,
            'progress_id' => $progress->id,
            'progress_status' => $progress->status,
            'current_step_order' => $currentStepOrder,
            'next_step_id' => $nextStep?->id,
            'next_step_order' => $nextStep?->step_order,
            'next_step_key' => $nextStep?->step_key,
            'next_step_name' => $nextStep?->step_name,
            'medium' => $nextStep?->medium,
            'template_key' => $nextStep?->template?->template_key,
            'base_time' => $baseTime->format('Y-m-d H:i:s'),
            'raw_due_at' => $rawDueAt?->format('Y-m-d H:i:s'),
            'next_allowed_time' => $nextAllowedTime?->format('Y-m-d H:i:s'),
            'is_due_now' => $isDueNow,
            'is_allowed_now' => $isAllowedNow,
            'preview_action' => $previewAction,
            'stop_check_whatsapp_reply' => 'not_checked_yet',
            'stop_check_converted' => 'not_checked_yet',
        ];
    }

    public function printHumanReport(array $rows): void
    {
        foreach ($rows as $row) {
            $nextStepText = $row['next_step_id'] === null
                ? 'none'
                : $row['next_step_order'].' '.$row['next_step_key'].' - '.$row['next_step_name'];

            $this->line('Lead ID: '.$row['lead_id']);
            $this->line('Progress status: '.$row['progress_status']);
            $this->line('Current step order: '.($row['current_step_order'] ?? 'null'));
            $this->line('Next step: '.$nextStepText);
            $this->line('Medium: '.($row['medium'] ?? 'n/a'));
            $this->line('Template: '.($row['template_key'] ?? 'n/a'));
            $this->line('Base time: '.$row['base_time']);
            $this->line('Raw due at: '.($row['raw_due_at'] ?? 'n/a'));
            $this->line('Next allowed time: '.($row['next_allowed_time'] ?? 'n/a'));
            $this->line('Due now: '.($row['is_due_now'] ? 'yes' : 'no'));
            $this->line('Allowed now: '.($row['is_allowed_now'] ? 'yes' : 'no'));
            $this->line('Preview action: '.$row['preview_action']);
            $this->line('Stop check whatsapp reply: '.$row['stop_check_whatsapp_reply']);
            $this->line('Stop check converted: '.$row['stop_check_converted']);
            $this->line(str_repeat('-', 70));
        }
    }
}
