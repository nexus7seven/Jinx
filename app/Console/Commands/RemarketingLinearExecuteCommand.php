<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingResponseEvent;
use App\Models\LeadRemarketingStepLog;
use App\Models\Lead;
use App\Models\RemarketingStep;
use App\Models\RemarketingTemplate;
use App\Models\RemarketingTask;
use App\Services\RemarketingScheduleWindowService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RemarketingLinearExecuteCommand extends Command
{
    private const DEFAULT_AGENT_NAME = 'Alex';
    private const DEFAULT_COMPANY_NAME = 'Clear My Credit';
    private const WHATSAPP_LINK = 'https://whatsapp.clearmycredit.co.uk';
    private const MANUAL_TASK_WHATSAPP_NUMBER = '441617685416';
    private const DEFAULT_PORTAL_LINK = '#PORTAL_LINK_PENDING#';

    private array $stepLogColumnCache = [];

    protected $signature = 'remarketing:linear-execute
        {--lead_id= : Optional single vicidial lead_id}
        {--limit=50 : Max number of progress rows to inspect}
        {--json : Output JSON instead of human report}
        {--commit : Commit planner decisions to linear progress/log tables}
        {--only-log : Commit mode guard to ensure no external actions are used}
        {--ignore-send-window : Bypass send window checks for testing}
        {--send-sms : Send SMS only for explicit single-lead commit mode}
        {--send-email : Send email only for explicit single-lead commit mode}';

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
        $sendSms = (bool) $this->option('send-sms');
        $sendEmail = (bool) $this->option('send-email');
        $ignoreSendWindow = (bool) $this->option('ignore-send-window');
        $leadId = $this->option('lead_id');

        if ($sendSms && ! $commit) {
            $this->error('Use --commit with --send-sms.');
            return self::FAILURE;
        }

        if ($sendSms && ($leadId === null || $leadId === '')) {
            $this->error('SMS sending requires --lead_id for now.');
            return self::FAILURE;
        }

        if ($sendSms && $onlyLog) {
            $this->error('Choose either --only-log or --send-sms, not both.');
            return self::FAILURE;
        }

        if ($sendEmail && ! $commit) {
            $this->error('Use --commit with --send-email.');
            return self::FAILURE;
        }

        if ($sendEmail && ($leadId === null || $leadId === '')) {
            $this->error('Email sending requires --lead_id for now.');
            return self::FAILURE;
        }

        if ($sendEmail && $onlyLog) {
            $this->error('Choose either --only-log or --send-email, not both.');
            return self::FAILURE;
        }

        if ($sendEmail && $sendSms) {
            $this->error('Choose only one send medium at a time.');
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
        $pauseLoggedLeadIds = [];
        $summary = [
            'total_checked' => 0,
            'due_now' => 0,
            'would_execute' => 0,
            'skipped' => 0,
            'committed' => 0,
            'advanced' => 0,
            'sms_sent' => 0,
            'sms_failed' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
        ];

        foreach ($progressRows as $progress) {
            $summary['total_checked']++;

            $currentStepOrder = $progress->current_step_order;
            $currentStep = null;
            if ($progress->current_step_id !== null) {
                $currentStep = $steps->firstWhere('id', $progress->current_step_id);
            }
            if ($currentStepOrder === null && $progress->current_step_id !== null) {
                $currentStepOrder = optional($currentStep)->step_order;
            }
            if ($currentStep === null && $currentStepOrder !== null) {
                $currentStep = $steps->firstWhere('step_order', $currentStepOrder);
            }

            $journeyScopedSteps = $this->resolveJourneyScopedSteps($steps, $currentStep);
            $journeyScope = $currentStep !== null && str_starts_with((string) $currentStep->step_key, 'cbna_')
                ? 'cbna_'
                : 'default';
            $nextStep = $this->previewCommand->getNextStep($journeyScopedSteps, $currentStepOrder);
            $stepToExecute = $nextStep;

            $currentDueAt = $progress->next_step_due_at;
            $shouldExecuteCurrentStep = $currentStep !== null
                && $currentDueAt !== null
                && $progress->last_step_completed_at === null
                && Carbon::parse((string) $currentDueAt, RemarketingScheduleWindowService::TIMEZONE)->lessThanOrEqualTo($now);

            if ($shouldExecuteCurrentStep) {
                $stepToExecute = $currentStep;
            }

            $baseTime = $this->previewCommand->resolveBaseTime($progress);

            $dueAt = null;
            $nextAllowedTime = null;
            $isDueNow = false;
            $isAllowedNow = false;

            if ($stepToExecute !== null) {
                if ($shouldExecuteCurrentStep) {
                    $dueAt = $currentDueAt !== null
                        ? Carbon::parse((string) $currentDueAt, RemarketingScheduleWindowService::TIMEZONE)
                        : $now->copy();
                } else {
                    $dueAt = $baseTime->copy()->addMinutes((int) $stepToExecute->delay_minutes);
                }
                if ($ignoreSendWindow) {
                    $nextAllowedTime = $dueAt->copy();
                    $isDueNow = $now->greaterThanOrEqualTo($dueAt);
                    $isAllowedNow = true;
                } else {
                    $nextAllowedTime = $this->scheduleWindowService->nextAllowedTime($stepToExecute, $dueAt->copy());
                    $isDueNow = $now->greaterThanOrEqualTo($nextAllowedTime);
                    $isAllowedNow = $this->scheduleWindowService->isAllowedNow($stepToExecute, $now->copy());
                }
            }

            $lead = $this->findLeadByVicidialLeadId((int) $progress->lead_id);
            $plannedDelivery = $stepToExecute !== null ? $this->resolvePlannedDelivery($stepToExecute) : null;
            $actualDelivery = $stepToExecute !== null
                ? $this->resolveActualDelivery($stepToExecute, $progress, $lead)
                : null;

            $hasNeedsReviewResponse = RemarketingResponseEvent::query()
                ->where('lead_id', (int) $progress->lead_id)
                ->where('status', RemarketingResponseEvent::STATUS_NEEDS_REVIEW)
                ->exists();

            $pauseReason = null;

            if ($hasNeedsReviewResponse) {
                $executionAction = 'skip_needs_review_response';
                $pauseReason = 'inbound_response_needs_review';

                if (! isset($pauseLoggedLeadIds[(int) $progress->lead_id])) {
                    Log::info('[remarketing-linear] paused due to pending response event', [
                        'lead_id' => (int) $progress->lead_id,
                        'vicidial_lead_id' => (int) $progress->lead_id,
                    ]);
                    $pauseLoggedLeadIds[(int) $progress->lead_id] = true;
                }
            } else {
                $executionAction = $this->resolveExecutionAction(
                    progressStatus: (string) $progress->status,
                    nextStep: $stepToExecute,
                    isDueNow: $isDueNow,
                    actualDelivery: $actualDelivery
                );
            }
            $commitAction = 'dry_run_no_change';
            $smsAction = 'skipped';
            $smsTo = null;
            $emailAction = 'skipped';
            $emailTo = null;
            $whatsappAction = 'skipped';
            $whatsappBodyPreview = null;
            $providerMessageId = null;
            $errorMessage = null;

            if ($isDueNow) {
                $summary['due_now']++;
            }

            if (str_starts_with($executionAction, 'would_')) {
                $summary['would_execute']++;
            } else {
                $summary['skipped']++;
            }

            if ($commit && $this->isCommitEligible($executionAction, $isDueNow) && $stepToExecute !== null) {
                if ($onlyLog) {
                    $commitResult = $this->commitStepDecision(
                        progress: $progress,
                        currentStep: $stepToExecute,
                        allSteps: $journeyScopedSteps,
                        executionAction: $executionAction,
                        dueAt: $dueAt,
                            now: $now,
                            plannedDelivery: $plannedDelivery ?? [],
                            actualDelivery: $actualDelivery ?? []
                    );

                    $commitAction = $commitResult['commit_action'];
                    if ($commitResult['committed']) {
                        $summary['committed']++;
                    }
                    if ($commitResult['advanced']) {
                        $summary['advanced']++;
                    }
                } elseif ($executionAction === 'would_send_sms') {
                    $sendResult = $this->commitSmsStepDecision(
                        progress: $progress,
                        currentStep: $stepToExecute,
                        allSteps: $journeyScopedSteps,
                        executionAction: $executionAction,
                        dueAt: $dueAt,
                        now: $now,
                        plannedDelivery: $plannedDelivery ?? [],
                        actualDelivery: $actualDelivery ?? []
                    );

                    $commitAction = $sendResult['commit_action'];
                    $smsAction = $sendResult['sms_action'];
                    $smsTo = $sendResult['sms_to'];
                    $providerMessageId = $sendResult['provider_message_id'];
                    $errorMessage = $sendResult['error_message'];

                    if ($sendResult['committed']) {
                        $summary['committed']++;
                    }
                    if ($sendResult['advanced']) {
                        $summary['advanced']++;
                    }
                    if ($smsAction === 'sent') {
                        $summary['sms_sent']++;
                    }
                    if ($smsAction === 'failed') {
                        $summary['sms_failed']++;
                    }
                } elseif ($executionAction === 'would_send_or_queue_whatsapp') {
                    $sendResult = $this->commitWhatsAppManualTaskDecision(
                        progress: $progress,
                        currentStep: $stepToExecute,
                        allSteps: $journeyScopedSteps,
                        executionAction: $executionAction,
                        dueAt: $dueAt,
                        now: $now,
                        plannedDelivery: $plannedDelivery ?? [],
                        actualDelivery: $actualDelivery ?? []
                    );

                    $commitAction = $sendResult['commit_action'];
                    $whatsappAction = $sendResult['whatsapp_action'];
                    $whatsappBodyPreview = $sendResult['whatsapp_body_preview'];
                    $errorMessage = $sendResult['error_message'];

                    if ($sendResult['committed']) {
                        $summary['committed']++;
                    }
                    if ($sendResult['advanced']) {
                        $summary['advanced']++;
                    }
                } elseif ($sendEmail) {
                    if ($executionAction !== 'would_send_email') {
                        $commitAction = 'skipped_non_email_step';
                        $emailAction = 'skipped';
                        $errorMessage = 'execution_action is not would_send_email';
                    } else {
                        $sendResult = $this->commitEmailStepDecision(
                            progress: $progress,
                            currentStep: $stepToExecute,
                            allSteps: $journeyScopedSteps,
                            executionAction: $executionAction,
                            dueAt: $dueAt,
                            now: $now,
                            plannedDelivery: $plannedDelivery ?? [],
                            actualDelivery: $actualDelivery ?? []
                        );

                        $commitAction = $sendResult['commit_action'];
                        $emailAction = $sendResult['email_action'];
                        $emailTo = $sendResult['email_to'];
                        $providerMessageId = $sendResult['provider_message_id'];
                        $errorMessage = $sendResult['error_message'];

                        if ($sendResult['committed']) {
                            $summary['committed']++;
                        }
                        if ($sendResult['advanced']) {
                            $summary['advanced']++;
                        }
                        if ($emailAction === 'sent') {
                            $summary['email_sent']++;
                        }
                        if ($emailAction === 'failed') {
                            $summary['email_failed']++;
                        }
                    }
                } else {
                    $commitAction = 'skipped_live_non_sms_step';
                }
            }

            $rows[] = [
                'lead_id' => (int) $progress->lead_id,
                'progress_status' => $progress->status,
                'next_step_order' => $stepToExecute?->step_order,
                'next_step_key' => $stepToExecute?->step_key,
                'journey_scope' => $journeyScope,
                'medium' => $stepToExecute?->medium,
                'planned_medium' => $plannedDelivery['planned_medium'] ?? null,
                'actual_medium' => $actualDelivery['actual_medium'] ?? null,
                'planned_template_key' => $plannedDelivery['planned_template_key'] ?? null,
                'actual_template_key' => $actualDelivery['actual_template_key'] ?? null,
                'fallback_used' => (bool) ($actualDelivery['fallback_used'] ?? false),
                'fallback_reason' => $actualDelivery['fallback_reason'] ?? null,
                'due_at' => $dueAt?->format('Y-m-d H:i:s'),
                'next_allowed_time' => $nextAllowedTime?->format('Y-m-d H:i:s'),
                'is_due_now' => $isDueNow,
                'is_allowed_now' => $isAllowedNow,
                'execution_action' => $executionAction,
                'pause_reason' => $pauseReason,
                'sms_action' => $smsAction,
                'sms_to' => $smsTo,
                'email_action' => $emailAction,
                'email_to' => $emailTo,
                'whatsapp_action' => $whatsappAction,
                'whatsapp_body_preview' => $whatsappBodyPreview,
                'provider_message_id' => $providerMessageId,
                'error_message' => $errorMessage,
                'commit_action' => $commitAction,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $this->line('Lead ID: '.$row['lead_id']);
                $this->line('Progress status: '.$row['progress_status']);
                $this->line('Journey scope: '.$row['journey_scope']);
                $this->line('Next step: '.($row['next_step_order'] !== null ? $row['next_step_order'].' '.$row['next_step_key'] : 'none'));
                $this->line('Medium: '.($row['medium'] ?? 'n/a'));
                $this->line('Planned medium: '.($row['planned_medium'] ?? 'n/a'));
                $this->line('Actual medium: '.($row['actual_medium'] ?? 'n/a'));
                $this->line('Planned template key: '.($row['planned_template_key'] ?? 'n/a'));
                $this->line('Actual template key: '.($row['actual_template_key'] ?? 'n/a'));
                if ($row['fallback_used']) {
                    $this->line('Fallback: '.($row['fallback_reason'] ?? 'used'));
                    $this->line('Step '.($row['next_step_order'] ?? '?').' '.($row['next_step_key'] ?? 'unknown').': planned='.($row['planned_medium'] ?? 'n/a').' actual='.($row['actual_medium'] ?? 'n/a').' fallback='.($row['fallback_reason'] ?? 'used'));
                }
                $this->line('Due at: '.($row['due_at'] ?? 'n/a'));
                $this->line('Next allowed time: '.($row['next_allowed_time'] ?? 'n/a'));
                $this->line('Due now: '.($row['is_due_now'] ? 'yes' : 'no'));
                $this->line('Allowed now: '.($row['is_allowed_now'] ? 'yes' : 'no'));
                $this->line('Execution decision: '.$row['execution_action']);
                if (! empty($row['pause_reason'])) {
                    $this->line('Pause reason: inbound response needs review');
                }
                $this->line('SMS action: '.$row['sms_action']);
                $this->line('SMS to: '.($row['sms_to'] ?? '-'));
                $this->line('Email action: '.$row['email_action']);
                $this->line('Email to: '.($row['email_to'] ?? '-'));
                $this->line('WhatsApp action: '.$row['whatsapp_action']);
                $this->line('WhatsApp body preview: '.($row['whatsapp_body_preview'] ?? '-'));
                $this->line('Provider message ID: '.($row['provider_message_id'] ?? '-'));
                $this->line('Error: '.($row['error_message'] ?? '-'));
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
            $this->line('SMS sent: '.$summary['sms_sent']);
            $this->line('SMS failed: '.$summary['sms_failed']);
            $this->line('Email sent: '.$summary['email_sent']);
            $this->line('Email failed: '.$summary['email_failed']);
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
        Carbon $now,
        array $plannedDelivery,
        array $actualDelivery
    ): array {
        return DB::transaction(function () use ($progress, $currentStep, $allSteps, $executionAction, $dueAt, $now, $plannedDelivery, $actualDelivery): array {
            $startedAt = $now->copy();
            $completedAt = $now->copy();
            if (($actualDelivery['execution_error'] ?? null) !== null) {
                $error = (string) $actualDelivery['execution_error'];
                $this->createStepLog(
                    progress: $progress,
                    currentStep: $currentStep,
                    payload: [
                        'medium' => $currentStep->medium,
                        'status' => 'failed',
                        'execution_status' => 'failed_preflight',
                        'due_at' => $dueAt,
                        'executed_at' => $startedAt,
                        'started_at' => $startedAt,
                        'completed_at' => null,
                        'failed_at' => $startedAt,
                        'provider_message_id' => null,
                        'error_message' => $error,
                        'execution_error' => $error,
                        'created_task_id' => null,
                        'context_json' => [
                            'mode' => 'commit_only_log',
                            'execution_action' => $executionAction,
                            'note' => 'step skipped due to delivery resolution error',
                        ],
                        'metadata_json' => [],
                    ],
                    plannedDelivery: $plannedDelivery,
                    actualDelivery: $actualDelivery
                );

                return [
                    'committed' => true,
                    'advanced' => false,
                    'commit_action' => 'failed_preflight_no_advance',
                ];
            }

            $isManualCallStep = ($actualDelivery['actual_medium'] ?? null) === 'call'
                || (bool) ($currentStep->is_manual ?? false)
                || (bool) $currentStep->requires_manual_completion;

            if ($isManualCallStep) {
                $this->createStepLog(
                    progress: $progress,
                    currentStep: $currentStep,
                    payload: [
                        'medium' => $currentStep->medium,
                        'status' => 'queued_task',
                        'execution_status' => 'queued_manual_call',
                        'due_at' => $dueAt,
                        'executed_at' => $startedAt,
                        'started_at' => $startedAt,
                        'completed_at' => null,
                        'failed_at' => null,
                        'provider_message_id' => null,
                        'error_message' => null,
                        'execution_error' => null,
                        'created_task_id' => null,
                        'context_json' => [
                            'mode' => 'commit_only_log',
                            'execution_action' => $executionAction,
                            'note' => 'manual call step queued; no external action performed',
                        ],
                        'metadata_json' => [
                            'call_window_label' => $currentStep->call_window_label,
                            'call_window_start' => $currentStep->call_window_start,
                            'call_window_end' => $currentStep->call_window_end,
                        ],
                    ],
                    plannedDelivery: $plannedDelivery,
                    actualDelivery: $actualDelivery
                );

                $progress->update([
                    'current_step_id' => $currentStep->id,
                    'current_step_order' => $currentStep->step_order,
                    'status' => $currentStep->requires_manual_completion ? 'pending_manual_task' : 'active',
                    'next_step_due_at' => $dueAt,
                ]);

                return [
                    'committed' => true,
                    'advanced' => false,
                    'commit_action' => 'logged_manual_pending',
                ];
            }

            $this->createStepLog(
                progress: $progress,
                currentStep: $currentStep,
                payload: [
                    'medium' => $currentStep->medium,
                    'status' => 'completed',
                    'execution_status' => 'logged',
                    'due_at' => $dueAt,
                    'executed_at' => $completedAt,
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'failed_at' => null,
                    'provider_message_id' => null,
                    'error_message' => null,
                    'execution_error' => null,
                    'created_task_id' => null,
                    'context_json' => [
                        'mode' => 'commit_only_log',
                        'execution_action' => $executionAction,
                        'note' => 'no external action performed',
                    ],
                    'metadata_json' => [],
                ],
                plannedDelivery: $plannedDelivery,
                actualDelivery: $actualDelivery
            );

            $updates = [
                'current_step_id' => $currentStep->id,
                'current_step_order' => $currentStep->step_order,
                'last_step_completed_at' => $completedAt,
                'status' => 'active',
            ];
            $advanced = $this->applyProgressAdvance($progress, $currentStep, $allSteps, $completedAt, $updates);

            $progress->update($updates);

            return [
                'committed' => true,
                'advanced' => $advanced,
                'commit_action' => $advanced ? 'logged_and_advanced' : 'logged_no_advance',
            ];
        });
    }

    private function commitSmsStepDecision(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        $allSteps,
        string $executionAction,
        ?Carbon $dueAt,
        Carbon $now,
        array $plannedDelivery,
        array $actualDelivery
    ): array {
        if (($actualDelivery['actual_medium'] ?? null) !== 'sms') {
            return [
                'committed' => false,
                'advanced' => false,
                'sms_action' => 'skipped',
                'sms_to' => null,
                'provider_message_id' => null,
                'error_message' => 'next step actual medium is not sms',
                'commit_action' => 'skipped_non_sms_step',
            ];
        }

        $phoneData = $this->resolveSmsPhoneData((int) $progress->lead_id);
        $rawPhone = (string) ($phoneData['raw_phone'] ?? '');
        $normalizedPhone = $phoneData['normalized_phone'] ?? $this->normalizeUkPhone($rawPhone);
        $phoneSource = (string) ($phoneData['phone_source'] ?? 'none');
        $vicidialLeadId = $phoneData['vicidial_lead_id'] ?? null;
        $leadData = $vicidialLeadId !== null
            ? $this->fetchVicidialLeadData((int) $vicidialLeadId)
            : ['first_name' => ''];

        $this->line('SMS raw phone: '.($rawPhone !== '' ? $rawPhone : '(empty)'));
        $this->line('SMS normalized phone: '.($normalizedPhone ?? '(invalid)'));
        $this->line('SMS phone source: '.$phoneSource);

        if ($normalizedPhone === null) {
            $error = 'Missing or invalid phone number for lead. Raw phone: '.($rawPhone !== '' ? $rawPhone : '(empty)');
            $this->logFailedSmsStep(
                progress: $progress,
                currentStep: $currentStep,
                executionAction: $executionAction,
                dueAt: $dueAt,
                now: $now,
                to: null,
                error: $error,
                plannedDelivery: $plannedDelivery,
                actualDelivery: $actualDelivery,
                rawPhone: $rawPhone ?? null,
                normalizedPhone: $normalizedPhone ?? null
            );

            return [
                'committed' => true,
                'advanced' => false,
                'sms_action' => 'failed',
                'sms_to' => null,
                'provider_message_id' => null,
                'error_message' => $error,
                'commit_action' => 'failed_logged_no_advance',
            ];
        }

        $templateBody = trim((string) optional($currentStep->template)->body);
        if ($templateBody === '') {
            $error = 'SMS template body is empty for step.';
            $this->logFailedSmsStep(
                progress: $progress,
                currentStep: $currentStep,
                executionAction: $executionAction,
                dueAt: $dueAt,
                now: $now,
                to: $normalizedPhone,
                error: $error,
                plannedDelivery: $plannedDelivery,
                actualDelivery: $actualDelivery,
                rawPhone: $rawPhone ?? null,
                normalizedPhone: $normalizedPhone ?? null
            );

            return [
                'committed' => true,
                'advanced' => false,
                'sms_action' => 'failed',
                'sms_to' => $normalizedPhone,
                'provider_message_id' => null,
                'error_message' => $error,
                'commit_action' => 'failed_logged_no_advance',
            ];
        }

        $templateVariables = $this->resolveTemplateVariables((int) $progress->lead_id, $leadData);
        $messageBody = $this->renderTemplateBody($templateBody, $templateVariables);

        $sendResult = $this->sendSmsViaTwilio($normalizedPhone, $messageBody);
        if (! $sendResult['success']) {
            $this->logFailedSmsStep(
                progress: $progress,
                currentStep: $currentStep,
                executionAction: $executionAction,
                dueAt: $dueAt,
                now: $now,
                to: $normalizedPhone,
                error: $sendResult['error'] ?? 'Unknown Twilio error.',
                plannedDelivery: $plannedDelivery,
                actualDelivery: $actualDelivery,
                rawPhone: $rawPhone ?? null,
                normalizedPhone: $normalizedPhone ?? null
            );

            return [
                'committed' => true,
                'advanced' => false,
                'sms_action' => 'failed',
                'sms_to' => $normalizedPhone,
                'provider_message_id' => null,
                'error_message' => $sendResult['error'] ?? 'Unknown Twilio error.',
                'commit_action' => 'failed_logged_no_advance',
            ];
        }

        $providerSid = $sendResult['provider_message_id'] ?? null;
        $from = (string) config('services.twilio.from', env('TWILIO_FROM_NUMBER'));

        return DB::transaction(function () use (
            $progress,
            $currentStep,
            $allSteps,
            $executionAction,
            $dueAt,
            $now,
            $normalizedPhone,
            $providerSid,
            $from,
            $plannedDelivery,
            $actualDelivery,
            $rawPhone
        ): array {
            $this->createStepLog(
                progress: $progress,
                currentStep: $currentStep,
                payload: [
                    'medium' => $currentStep->medium,
                    'status' => 'sent',
                    'execution_status' => 'sent',
                    'due_at' => $dueAt,
                    'executed_at' => $now->copy(),
                    'started_at' => $now->copy(),
                    'completed_at' => $now->copy(),
                    'failed_at' => null,
                    'provider' => 'twilio',
                    'provider_message_id' => $providerSid,
                    'error_message' => null,
                    'execution_error' => null,
                    'created_task_id' => null,
                    'context_json' => [
                        'mode' => 'commit_send_sms',
                        'to' => $normalizedPhone,
                        'from' => $from,
                        'execution_action' => $executionAction,
                    ],
                    'metadata_json' => [
                        'raw_phone' => $rawPhone !== '' ? $rawPhone : null,
                        'normalized_phone' => $normalizedPhone,
                    ],
                ],
                plannedDelivery: $plannedDelivery,
                actualDelivery: $actualDelivery
            );

            $updates = [
                'current_step_id' => $currentStep->id,
                'current_step_order' => $currentStep->step_order,
                'last_step_completed_at' => $now->copy(),
                'status' => 'active',
            ];
            $advanced = $this->applyProgressAdvance($progress, $currentStep, $allSteps, $now->copy(), $updates);

            $progress->update($updates);

            return [
                'committed' => true,
                'advanced' => $advanced,
                'sms_action' => 'sent',
                'sms_to' => $normalizedPhone,
                'provider_message_id' => $providerSid,
                'error_message' => null,
                'commit_action' => $advanced ? 'sent_logged_and_advanced' : 'sent_logged_no_advance',
            ];
        });
    }

    private function fetchVicidialLeadData(int $leadId): array
    {
        $selectColumns = ['lead_id', 'phone_number'];
        if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'first_name')) {
            $selectColumns[] = 'first_name';
        }

        $row = DB::connection('asterisk')
            ->table('vicidial_list')
            ->select($selectColumns)
            ->where('lead_id', $leadId)
            ->first();

        return [
            'lead_id' => $row->lead_id ?? $leadId,
            'phone_number' => $row->phone_number ?? null,
            'first_name' => $row->first_name ?? '',
        ];
    }

    private function fetchEmailLeadData(int $vicidialLeadId): array
    {
        $leadEmail = null;
        $leadFirstName = '';

        $leadSelect = ['id', 'vicidial_lead_id'];
        if (Schema::hasColumn('leads', 'email')) {
            $leadSelect[] = 'email';
        }
        if (Schema::hasColumn('leads', 'email_address')) {
            $leadSelect[] = 'email_address';
        }
        if (Schema::hasColumn('leads', 'first_name')) {
            $leadSelect[] = 'first_name';
        }

        $lead = DB::table('leads')
            ->select($leadSelect)
            ->where('vicidial_lead_id', $vicidialLeadId)
            ->first();

        if ($lead !== null) {
            $leadFirstName = (string) ($lead->first_name ?? '');
            $leadEmail = $this->pickFirstValidEmail([
                $lead->email ?? null,
                $lead->email_address ?? null,
            ]);
        }

        $vicidialSelect = ['lead_id'];
        if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'email')) {
            $vicidialSelect[] = 'email';
        }
        if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'email_address')) {
            $vicidialSelect[] = 'email_address';
        }
        if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'first_name')) {
            $vicidialSelect[] = 'first_name';
        }

        $vicidial = DB::connection('asterisk')
            ->table('vicidial_list')
            ->select($vicidialSelect)
            ->where('lead_id', $vicidialLeadId)
            ->first();

        $vicidialEmail = $this->pickFirstValidEmail([
            $vicidial->email ?? null,
            $vicidial->email_address ?? null,
        ]);

        return [
            'email' => $leadEmail ?? $vicidialEmail,
            'first_name' => $leadFirstName !== '' ? $leadFirstName : (string) ($vicidial->first_name ?? ''),
        ];
    }

    private function pickFirstValidEmail(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $email = trim((string) ($candidate ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    private function normalizeUkPhone(string $phone): ?string
    {
        $value = trim($phone);
        if ($value === '') {
            return null;
        }

        $cleaned = preg_replace('/[\s\-\(\)\.]+/', '', $value);
        if (! is_string($cleaned) || $cleaned === '') {
            return null;
        }

        $hasPlus = str_starts_with($cleaned, '+');
        $digits = preg_replace('/[^\d]/', '', $cleaned);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if ($hasPlus) {
            return preg_match('/^\+\d{10,15}$/', $cleaned) ? $cleaned : null;
        }

        if (str_starts_with($digits, '44') && strlen($digits) === 12) {
            $normalized = '+' . $digits;
        } elseif (str_starts_with($digits, '07') && strlen($digits) === 11) {
            $normalized = '+44' . substr($digits, 1);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $normalized = '+44' . substr($digits, 1);
        } elseif (str_starts_with($digits, '7') && strlen($digits) === 10) {
            $normalized = '+44' . $digits;
        } else {
            return null;
        }

        if (! preg_match('/^\+\d{10,15}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function renderTemplateBody(string $templateBody, array $variables): string
    {
        $replacements = [
            '{{first_name}}' => (string) ($variables['first_name'] ?? ''),
            '{{last_name}}' => (string) ($variables['last_name'] ?? ''),
            '{{lead_id}}' => (string) ($variables['lead_id'] ?? ''),
            '{{agent_name}}' => (string) ($variables['agent_name'] ?? self::DEFAULT_AGENT_NAME),
            '{{company_name}}' => (string) ($variables['company_name'] ?? self::DEFAULT_COMPANY_NAME),
            '{{whatsapp_link}}' => (string) ($variables['whatsapp_link'] ?? self::WHATSAPP_LINK),
            '{{portal_link}}' => (string) ($variables['portal_link'] ?? self::DEFAULT_PORTAL_LINK),
        ];

        $rendered = str_replace(array_keys($replacements), array_values($replacements), $templateBody);
        if (preg_match('/\{\{[^}]+\}\}/', $rendered) === 1) {
            $this->warn('Template rendering warning: unresolved placeholders remain in rendered body.');
        }

        return $rendered;
    }

    private function commitEmailStepDecision(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        $allSteps,
        string $executionAction,
        ?Carbon $dueAt,
        Carbon $now,
        array $plannedDelivery,
        array $actualDelivery
    ): array {
        if (($actualDelivery['actual_medium'] ?? null) !== 'email') {
            return [
                'committed' => false,
                'advanced' => false,
                'email_action' => 'skipped',
                'email_to' => null,
                'provider_message_id' => null,
                'error_message' => 'next step actual medium is not email',
                'commit_action' => 'skipped_non_email_step',
            ];
        }

        if (($actualDelivery['execution_error'] ?? null) !== null) {
            $error = (string) $actualDelivery['execution_error'];
            $this->logFailedEmailStep($progress, $currentStep, $executionAction, $dueAt, $now, null, $error, $plannedDelivery, $actualDelivery);

            return [
                'committed' => true,
                'advanced' => false,
                'email_action' => 'failed',
                'email_to' => null,
                'provider_message_id' => null,
                'error_message' => $error,
                'commit_action' => 'failed_logged_no_advance',
            ];
        }

        $leadData = $this->fetchEmailLeadData((int) $progress->lead_id);
        $emailTo = $leadData['email'] ?? null;
        if ($emailTo === null) {
            $error = 'Missing or invalid email for lead.';
            $this->logFailedEmailStep($progress, $currentStep, $executionAction, $dueAt, $now, null, $error, $plannedDelivery, $actualDelivery);

            return [
                'committed' => true,
                'advanced' => false,
                'email_action' => 'failed',
                'email_to' => null,
                'provider_message_id' => null,
                'error_message' => $error,
                'commit_action' => 'failed_logged_no_advance',
            ];
        }

        $template = $currentStep->template;
        $subjectTemplate = trim((string) ($template?->subject ?? ''));
        $bodyTemplate = trim((string) ($template?->body ?? ''));
        if (($plannedDelivery['provider'] ?? null) === 'sendgrid'
            && (($plannedDelivery['provider_template_id'] ?? null) === null)
            && $bodyTemplate === '') {
            $error = 'pending_template';
            $this->createStepLog(
                progress: $progress,
                currentStep: $currentStep,
                payload: [
                    'medium' => $currentStep->medium,
                    'status' => 'skipped',
                    'execution_status' => 'skipped_pending_template',
                    'due_at' => $dueAt,
                    'executed_at' => $now->copy(),
                    'started_at' => $now->copy(),
                    'completed_at' => null,
                    'failed_at' => null,
                    'provider' => 'sendgrid',
                    'provider_message_id' => null,
                    'error_message' => $error,
                    'execution_error' => $error,
                    'created_task_id' => null,
                    'context_json' => [
                        'mode' => 'commit_send_email',
                        'execution_action' => $executionAction,
                        'note' => 'email step skipped because provider template is pending',
                    ],
                    'metadata_json' => [],
                ],
                plannedDelivery: $plannedDelivery,
                actualDelivery: $actualDelivery
            );

            return [
                'committed' => true,
                'advanced' => false,
                'email_action' => 'skipped',
                'email_to' => $emailTo,
                'provider_message_id' => null,
                'error_message' => $error,
                'commit_action' => 'skipped_pending_template_no_advance',
            ];
        }

        if ($bodyTemplate === '') {
            $error = 'Email template body is empty for step.';
            $this->logFailedEmailStep($progress, $currentStep, $executionAction, $dueAt, $now, $emailTo, $error, $plannedDelivery, $actualDelivery);

            return [
                'committed' => true,
                'advanced' => false,
                'email_action' => 'failed',
                'email_to' => $emailTo,
                'provider_message_id' => null,
                'error_message' => $error,
                'commit_action' => 'failed_logged_no_advance',
            ];
        }

        $templateVariables = $this->resolveTemplateVariables((int) $progress->lead_id, $leadData);
        $subject = $subjectTemplate !== ''
            ? $this->renderTemplateBody($subjectTemplate, $templateVariables)
            : (string) ($template?->template_name ?? 'Remarketing update');
        $body = $this->renderTemplateBody($bodyTemplate, $templateVariables);

        $sendResult = $this->sendEmailViaSendGrid($emailTo, $firstName, $subject, $body);
        if (! $sendResult['success']) {
            $this->logFailedEmailStep(
                $progress,
                $currentStep,
                $executionAction,
                $dueAt,
                $now,
                $emailTo,
                $sendResult['error'] ?? 'Unknown SendGrid error.',
                $plannedDelivery,
                $actualDelivery
            );

            return [
                'committed' => true,
                'advanced' => false,
                'email_action' => 'failed',
                'email_to' => $emailTo,
                'provider_message_id' => null,
                'error_message' => $sendResult['error'] ?? 'Unknown SendGrid error.',
                'commit_action' => 'failed_logged_no_advance',
            ];
        }

        $providerMessageId = $sendResult['provider_message_id'] ?? null;
        $fromEmail = (string) (config('mail.from.address') ?? env('EMAIL_FROM'));

        return DB::transaction(function () use (
            $progress,
            $currentStep,
            $allSteps,
            $executionAction,
            $dueAt,
            $now,
            $emailTo,
            $providerMessageId,
            $fromEmail,
            $plannedDelivery,
            $actualDelivery
        ): array {
            $this->createStepLog(
                progress: $progress,
                currentStep: $currentStep,
                payload: [
                    'medium' => $currentStep->medium,
                    'status' => 'sent',
                    'execution_status' => 'sent',
                    'due_at' => $dueAt,
                    'executed_at' => $now->copy(),
                    'started_at' => $now->copy(),
                    'completed_at' => $now->copy(),
                    'failed_at' => null,
                    'provider' => 'sendgrid',
                    'provider_message_id' => $providerMessageId,
                    'error_message' => null,
                    'execution_error' => null,
                    'created_task_id' => null,
                    'context_json' => [
                        'mode' => 'commit_send_email',
                        'to' => $emailTo,
                        'from' => $fromEmail,
                        'execution_action' => $executionAction,
                    ],
                    'metadata_json' => [],
                ],
                plannedDelivery: $plannedDelivery,
                actualDelivery: $actualDelivery
            );

            $updates = [
                'current_step_id' => $currentStep->id,
                'current_step_order' => $currentStep->step_order,
                'last_step_completed_at' => $now->copy(),
                'status' => 'active',
            ];
            $advanced = $this->applyProgressAdvance($progress, $currentStep, $allSteps, $now->copy(), $updates);

            $progress->update($updates);

            return [
                'committed' => true,
                'advanced' => $advanced,
                'email_action' => 'sent',
                'email_to' => $emailTo,
                'provider_message_id' => $providerMessageId,
                'error_message' => null,
                'commit_action' => $advanced ? 'sent_logged_and_advanced' : 'sent_logged_no_advance',
            ];
        });
    }

    private function commitWhatsAppManualTaskDecision(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        $allSteps,
        string $executionAction,
        ?Carbon $dueAt,
        Carbon $now,
        array $plannedDelivery,
        array $actualDelivery
    ): array {
        if (($actualDelivery['actual_medium'] ?? null) !== 'whatsapp') {
            return [
                'committed' => false,
                'advanced' => false,
                'whatsapp_action' => 'skipped',
                'whatsapp_body_preview' => null,
                'error_message' => 'next step actual medium is not whatsapp',
                'commit_action' => 'skipped_non_whatsapp_step',
            ];
        }

        $templateKey = (string) ($actualDelivery['actual_template_key'] ?? $plannedDelivery['planned_template_key'] ?? '');
        $templateBody = '';
        if ($templateKey !== '') {
            $templateBody = trim((string) optional(
                RemarketingTemplate::query()->where('template_key', $templateKey)->first()
            )->body);
        }
        if ($templateBody === '') {
            $templateBody = trim((string) optional($currentStep->template)->body);
        }

        $variables = $this->resolveTemplateVariables((int) $progress->lead_id);
        $renderedBody = $templateBody !== '' ? $this->renderTemplateBody($templateBody, $variables) : '';
        $bodyPreview = $renderedBody !== '' ? mb_substr($renderedBody, 0, 120) : null;

        return DB::transaction(function () use (
            $progress,
            $currentStep,
            $allSteps,
            $executionAction,
            $dueAt,
            $now,
            $plannedDelivery,
            $actualDelivery,
            $renderedBody,
            $bodyPreview,
            $templateKey
        ): array {
            $manualTask = $this->upsertPendingWhatsAppTask(
                progress: $progress,
                currentStep: $currentStep,
                renderedBody: $renderedBody,
                templateKey: $templateKey
            );

            $this->createStepLog(
                progress: $progress,
                currentStep: $currentStep,
                payload: [
                    'medium' => $currentStep->medium,
                    'status' => 'queued_task',
                    'execution_status' => 'manual_task_created',
                    'due_at' => $dueAt,
                    'executed_at' => $now->copy(),
                    'started_at' => $now->copy(),
                    'completed_at' => null,
                    'failed_at' => null,
                    'provider_message_id' => null,
                    'error_message' => null,
                    'execution_error' => null,
                    'created_task_id' => $manualTask?->id,
                    'context_json' => [
                        'mode' => 'commit_manual_whatsapp_task',
                        'execution_action' => $executionAction,
                        'note' => 'outbound_whatsapp_manual_task',
                    ],
                    'metadata_json' => [
                        'rendered_body' => $renderedBody,
                        'planned_medium' => $plannedDelivery['planned_medium'] ?? null,
                        'actual_medium' => $actualDelivery['actual_medium'] ?? null,
                        'fallback_used' => (bool) ($actualDelivery['fallback_used'] ?? false),
                        'fallback_reason' => $actualDelivery['fallback_reason'] ?? null,
                        'template_key' => $templateKey !== '' ? $templateKey : null,
                        'manual_task_id' => $manualTask?->id,
                        'note' => 'outbound_whatsapp_manual_task',
                    ],
                ],
                plannedDelivery: $plannedDelivery,
                actualDelivery: $actualDelivery
            );

            $updates = [
                'current_step_id' => $currentStep->id,
                'current_step_order' => $currentStep->step_order,
                'last_step_completed_at' => $now->copy(),
                'status' => 'active',
            ];
            $advanced = $this->applyProgressAdvance($progress, $currentStep, $allSteps, $now->copy(), $updates);
            $progress->update($updates);

            return [
                'committed' => true,
                'advanced' => $advanced,
                'whatsapp_action' => 'manual_task_created',
                'whatsapp_body_preview' => $bodyPreview,
                'error_message' => null,
                'commit_action' => $advanced ? 'manual_task_created_and_advanced' : 'manual_task_created_waiting',
            ];
        });
    }

    private function upsertPendingWhatsAppTask(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        string $renderedBody,
        string $templateKey
    ): ?RemarketingTask {
        if (! Schema::hasTable('remarketing_tasks')) {
            return null;
        }

        $lead = Lead::query()->find((int) $progress->lead_id);
        if ($lead === null) {
            $lead = Lead::query()->where('vicidial_lead_id', (int) $progress->lead_id)->first();
        }

        $phoneData = $this->resolveSmsPhoneData((int) $progress->lead_id);
        $rawPhone = trim((string) ($phoneData['raw_phone'] ?? ''));

        $leadName = trim((string) ($lead?->first_name ?? '').' '.(string) ($lead?->last_name ?? ''));
        if ($leadName === '') {
            $leadName = 'Lead #'.(string) ($lead?->id ?? $progress->lead_id);
        }

        $taskLeadId = $lead?->vicidial_lead_id !== null
            ? (int) $lead->vicidial_lead_id
            : (int) $progress->lead_id;
        $reason = trim((string) ($currentStep->step_name ?? $currentStep->step_key ?? 'WhatsApp follow-up'));
        $stage = trim((string) ($currentStep->stage ?? 'fresh'));
        if (! in_array($stage, ['fresh', 'cooling', 'cold', 'dormant'], true)) {
            $stage = 'fresh';
        }
        $cleanBody = $this->sanitizeManualWhatsAppBody($renderedBody);
        $whatsAppUrl = 'https://wa.me/'.self::MANUAL_TASK_WHATSAPP_NUMBER;
        if (trim($cleanBody) !== '') {
            $whatsAppUrl .= '?text='.rawurlencode($cleanBody);
        }
        $supportsMessageBody = Schema::hasColumn('remarketing_tasks', 'message_body');
        $supportsMetadataJson = Schema::hasColumn('remarketing_tasks', 'metadata_json');

        $existing = RemarketingTask::query()
            ->where('lead_id', $taskLeadId)
            ->where('task_type', 'whatsapp')
            ->where('status', RemarketingTask::STATUS_PENDING)
            ->where('reason', $reason)
            ->first();

        if ($existing !== null) {
            $updatePayload = [
                'lead_name' => $leadName,
                'phone' => $rawPhone !== '' ? $rawPhone : ($existing->phone ?? ''),
                'stage' => $stage,
                'whatsapp_url' => $whatsAppUrl,
                'time_waiting_text' => '0h',
            ];
            if ($supportsMessageBody) {
                $updatePayload['message_body'] = $cleanBody !== '' ? $cleanBody : null;
            }
            if ($supportsMetadataJson) {
                $updatePayload['metadata_json'] = array_merge((array) ($existing->metadata_json ?? []), [
                    'rendered_body' => $cleanBody !== '' ? $cleanBody : null,
                    'template_key' => $templateKey !== '' ? $templateKey : null,
                ]);
            }
            $existing->update($updatePayload);

            return $existing->fresh();
        }

        $createPayload = [
            'lead_id' => $taskLeadId,
            'lead_name' => $leadName,
            'phone' => $rawPhone !== '' ? $rawPhone : '-',
            'campaign_id' => null,
            'task_type' => 'whatsapp',
            'reason' => $reason,
            'stage' => $stage,
            'status' => RemarketingTask::STATUS_PENDING,
            'time_waiting_text' => '0h',
            'whatsapp_url' => $whatsAppUrl,
        ];
        if ($supportsMessageBody) {
            $createPayload['message_body'] = $cleanBody !== '' ? $cleanBody : null;
        }
        if ($supportsMetadataJson) {
            $createPayload['metadata_json'] = [
                'rendered_body' => $cleanBody !== '' ? $cleanBody : null,
                'template_key' => $templateKey !== '' ? $templateKey : null,
            ];
        }

        return RemarketingTask::query()->create($createPayload);
    }

    private function sanitizeManualWhatsAppBody(string $renderedBody): string
    {
        $cleanBody = mb_convert_encoding($renderedBody, 'UTF-8', 'UTF-8');
        $cleanBody = str_replace(["\u{1F44D}", "\u{FFFD}"], '', $cleanBody);

        return rtrim($cleanBody);
    }

    private function sendEmailViaSendGrid(string $toEmail, string $toName, string $subject, string $body): array
    {
        $apiKey = (string) (config('services.sendgrid.api_key') ?? env('SENDGRID_API_KEY'));
        $fromEmail = trim((string) (config('mail.from.address') ?? env('EMAIL_FROM')));
        $fromName = trim((string) (config('mail.from.name') ?? env('EMAIL_FROM_NAME')));

        if ($apiKey === '' || $fromEmail === '' || ! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'Invalid SendGrid/email from configuration.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.sendgrid.com/v3/mail/send', [
                'personalizations' => [[
                    'to' => [[
                        'email' => $toEmail,
                        'name' => $toName !== '' ? $toName : null,
                    ]],
                ]],
                'from' => [
                    'email' => $fromEmail,
                    'name' => $fromName !== '' ? $fromName : null,
                ],
                'subject' => $subject,
                'content' => [[
                    'type' => 'text/plain',
                    'value' => $body,
                ]],
            ]);

            if (! in_array($response->status(), [200, 201, 202], true)) {
                return [
                    'success' => false,
                    'error' => 'SendGrid error: '.$response->status().' '.$response->body(),
                ];
            }

            return [
                'success' => true,
                'provider_message_id' => $response->header('X-Message-Id'),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function logFailedEmailStep(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        string $executionAction,
        ?Carbon $dueAt,
        Carbon $now,
        ?string $to,
        string $error,
        array $plannedDelivery = [],
        array $actualDelivery = [],
        ?string $rawPhone = null,
        ?string $normalizedPhone = null
    ): void {
        $this->createStepLog(
            progress: $progress,
            currentStep: $currentStep,
            payload: [
                'medium' => $currentStep->medium,
                'status' => 'failed',
                'execution_status' => 'failed',
                'due_at' => $dueAt,
                'executed_at' => $now->copy(),
                'started_at' => $now->copy(),
                'completed_at' => null,
                'failed_at' => $now->copy(),
                'provider' => 'sendgrid',
                'provider_message_id' => null,
                'error_message' => $error,
                'execution_error' => $error,
                'created_task_id' => null,
                'context_json' => [
                    'mode' => 'commit_send_email',
                    'to' => $to,
                    'execution_action' => $executionAction,
                    'note' => 'email send failed; no progress advance',
                ],
                'metadata_json' => [],
            ],
            plannedDelivery: $plannedDelivery,
            actualDelivery: $actualDelivery
        );
    }

    private function sendSmsViaTwilio(string $to, string $body): array
    {
        $sid = (string) config('services.twilio.sid', env('TWILIO_ACCOUNT_SID'));
        $token = (string) config('services.twilio.token', env('TWILIO_AUTH_TOKEN'));
        $from = $this->normalizeUkPhone((string) config('services.twilio.from', env('TWILIO_FROM_NUMBER')));

        if ($sid === '' || $token === '' || $from === null || trim($body) === '') {
            return [
                'success' => false,
                'error' => 'Invalid Twilio configuration or SMS payload.',
            ];
        }

        try {
            if (class_exists(\Twilio\Rest\Client::class)) {
                $client = new \Twilio\Rest\Client($sid, $token);
                $message = $client->messages->create($to, [
                    'from' => $from,
                    'body' => $body,
                ]);

                return [
                    'success' => true,
                    'provider_message_id' => $message->sid ?? null,
                    'transport' => 'sdk',
                ];
            }

            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To' => $to,
                    'Body' => $body,
                ]);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => 'Twilio HTTP error: '.$response->status().' '.$response->body(),
                    'transport' => 'http',
                ];
            }

            return [
                'success' => true,
                'provider_message_id' => $response->json('sid'),
                'transport' => 'http',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function logFailedSmsStep(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        string $executionAction,
        ?Carbon $dueAt,
        Carbon $now,
        ?string $to,
        string $error,
        array $plannedDelivery = [],
        array $actualDelivery = [],
        ?string $rawPhone = null,
        ?string $normalizedPhone = null
    ): void {
        $this->createStepLog(
            progress: $progress,
            currentStep: $currentStep,
            payload: [
                'medium' => $currentStep->medium,
                'status' => 'failed',
                'execution_status' => 'failed',
                'due_at' => $dueAt,
                'executed_at' => $now->copy(),
                'started_at' => $now->copy(),
                'completed_at' => null,
                'failed_at' => $now->copy(),
                'provider' => 'twilio',
                'provider_message_id' => null,
                'error_message' => $error,
                'execution_error' => $error,
                'created_task_id' => null,
                'context_json' => [
                    'mode' => 'commit_send_sms',
                    'to' => $to,
                    'execution_action' => $executionAction,
                    'note' => 'sms send failed; no progress advance',
                ],
                'metadata_json' => [
                    'raw_phone' => $rawPhone,
                    'normalized_phone' => $normalizedPhone,
                ],
            ],
            plannedDelivery: $plannedDelivery,
            actualDelivery: $actualDelivery
        );
    }

    private function resolveSmsPhoneData(int $jinxLeadId): array
    {
        $rawPhone = null;
        $phoneSource = 'none';
        $vicidialLeadId = null;

        if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'id')) {
            $leadSelect = ['id'];
            if (Schema::hasColumn('leads', 'phone_number')) {
                $leadSelect[] = 'phone_number';
            }
            if (Schema::hasColumn('leads', 'phone')) {
                $leadSelect[] = 'phone';
            }
            if (Schema::hasColumn('leads', 'vicidial_lead_id')) {
                $leadSelect[] = 'vicidial_lead_id';
            }

            $leadRow = DB::table('leads')
                ->select($leadSelect)
                ->where('id', $jinxLeadId)
                ->first();

            if ($leadRow !== null) {
                $rawLeadPhoneNumber = trim((string) ($leadRow->phone_number ?? ''));
                $rawLeadPhone = trim((string) ($leadRow->phone ?? ''));
                $vicidialLeadId = isset($leadRow->vicidial_lead_id) && $leadRow->vicidial_lead_id !== null
                    ? (int) $leadRow->vicidial_lead_id
                    : null;

                if ($rawLeadPhoneNumber !== '') {
                    $rawPhone = $rawLeadPhoneNumber;
                    $phoneSource = 'leads.phone_number';
                } elseif ($rawLeadPhone !== '') {
                    $rawPhone = $rawLeadPhone;
                    $phoneSource = 'leads.phone';
                }
            }
        }

        if (($rawPhone === null || $rawPhone === '') && $vicidialLeadId !== null) {
            $vicidialData = $this->fetchVicidialLeadData($vicidialLeadId);
            $vicidialPhone = trim((string) ($vicidialData['phone_number'] ?? ''));
            if ($vicidialPhone !== '') {
                $rawPhone = $vicidialPhone;
                $phoneSource = 'vicidial_list.phone_number';
            }
        }

        return [
            'raw_phone' => $rawPhone,
            'normalized_phone' => $this->normalizeUkPhone((string) ($rawPhone ?? '')),
            'phone_source' => $phoneSource,
            'jinx_lead_id' => $jinxLeadId,
            'vicidial_lead_id' => $vicidialLeadId,
        ];
    }

    private function pickFirstNonEmptyValue(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveTemplateVariables(int $jinxLeadId, array $fallback = []): array
    {
        $leadFirstName = '';
        $leadLastName = '';
        $vicidialLeadId = null;

        if (Schema::hasTable('leads')) {
            $select = ['id'];
            if (Schema::hasColumn('leads', 'first_name')) {
                $select[] = 'first_name';
            }
            if (Schema::hasColumn('leads', 'last_name')) {
                $select[] = 'last_name';
            }
            if (Schema::hasColumn('leads', 'vicidial_lead_id')) {
                $select[] = 'vicidial_lead_id';
            }

            $lead = DB::table('leads')->select($select)->where('id', $jinxLeadId)->first();
            if ($lead !== null) {
                $leadFirstName = trim((string) ($lead->first_name ?? ''));
                $leadLastName = trim((string) ($lead->last_name ?? ''));
                $vicidialLeadId = isset($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : null;
            }
        }

        $vicidialFirstName = '';
        $vicidialLastName = '';
        if ($vicidialLeadId !== null) {
            $selectColumns = ['lead_id'];
            if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'first_name')) {
                $selectColumns[] = 'first_name';
            }
            if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'last_name')) {
                $selectColumns[] = 'last_name';
            }

            $vicidial = DB::connection('asterisk')
                ->table('vicidial_list')
                ->select($selectColumns)
                ->where('lead_id', $vicidialLeadId)
                ->first();

            $vicidialFirstName = trim((string) ($vicidial->first_name ?? ''));
            $vicidialLastName = trim((string) ($vicidial->last_name ?? ''));
        }

        return [
            'first_name' => $leadFirstName !== '' ? $leadFirstName : (string) ($fallback['first_name'] ?? $vicidialFirstName),
            'last_name' => $leadLastName !== '' ? $leadLastName : (string) ($fallback['last_name'] ?? $vicidialLastName),
            'lead_id' => $jinxLeadId,
            'agent_name' => self::DEFAULT_AGENT_NAME,
            'company_name' => self::DEFAULT_COMPANY_NAME,
            'whatsapp_link' => self::WHATSAPP_LINK,
            'portal_link' => self::DEFAULT_PORTAL_LINK,
        ];
    }

    private function resolveExecutionAction(string $progressStatus, ?RemarketingStep $nextStep, bool $isDueNow, ?array $actualDelivery = null): string
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

        $resolvedMedium = $actualDelivery['actual_medium'] ?? $nextStep->medium;

        return match ($resolvedMedium) {
            'call' => 'would_queue_call_task',
            'sms' => 'would_send_sms',
            'email' => 'would_send_email',
            'whatsapp' => 'would_send_or_queue_whatsapp',
            default => 'unknown_medium',
        };
    }

    private function resolvePlannedDelivery(RemarketingStep $step): array
    {
        $plannedMedium = trim((string) ($step->primary_medium ?? '')) !== ''
            ? (string) $step->primary_medium
            : (string) $step->medium;

        $plannedTemplateKey = trim((string) ($step->primary_template_key ?? ''));
        if ($plannedTemplateKey === '') {
            $plannedTemplateKey = trim((string) ($step->template?->template_key ?? ''));
        }
        if ($plannedTemplateKey === '') {
            $plannedTemplateKey = trim((string) ($step->template_name ?? ''));
        }

        $provider = trim((string) ($step->template?->provider ?? '')) ?: null;
        $providerTemplateId = $step->sendgrid_template_id
            ?? ($step->template?->provider_template_id ?? null);

        return [
            'planned_medium' => $plannedMedium !== '' ? $plannedMedium : null,
            'planned_template_key' => $plannedTemplateKey !== '' ? $plannedTemplateKey : null,
            'provider' => $provider,
            'provider_template_id' => $providerTemplateId,
        ];
    }

    private function resolveActualDelivery(RemarketingStep $step, LeadRemarketingProgress $progress, ?Lead $lead): array
    {
        $planned = $this->resolvePlannedDelivery($step);
        $actual = [
            'actual_medium' => $planned['planned_medium'],
            'actual_template_key' => $planned['planned_template_key'],
            'fallback_used' => false,
            'fallback_reason' => null,
            'execution_error' => null,
            'provider' => $planned['provider'],
            'provider_template_id' => $planned['provider_template_id'],
        ];

        $fallbackCondition = (string) ($step->fallback_condition ?? '');
        if ($actual['actual_medium'] === 'email'
            && $fallbackCondition === 'lead_email_missing'
            && ! $this->leadHasUsableEmail($lead)
        ) {
            $fallbackMedium = trim((string) ($step->fallback_medium ?? ''));
            $fallbackTemplateKey = trim((string) ($step->fallback_template_key ?? ''));

            if ($fallbackMedium === '' || $fallbackTemplateKey === '') {
                $actual['execution_error'] = 'fallback_config_missing_for_lead_email_missing';

                return $actual;
            }

            $actual['actual_medium'] = $fallbackMedium;
            $actual['actual_template_key'] = $fallbackTemplateKey;
            $actual['fallback_used'] = true;
            $actual['fallback_reason'] = 'lead_email_missing';

            if ($fallbackMedium !== 'email') {
                $actual['provider'] = null;
                $actual['provider_template_id'] = null;
            }
        }

        return $actual;
    }

    private function leadHasUsableEmail(?Lead $lead): bool
    {
        if ($lead === null) {
            return false;
        }

        $candidates = [
            $lead->email ?? null,
            $lead->getAttribute('email_address'),
        ];
        foreach ($candidates as $candidate) {
            $email = trim((string) ($candidate ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return true;
            }
        }

        return false;
    }

    private function findLeadByVicidialLeadId(int $vicidialLeadId): ?Lead
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'vicidial_lead_id')) {
            return null;
        }

        $query = Lead::query()->where('vicidial_lead_id', $vicidialLeadId);
        if (Schema::hasColumn('leads', 'email')) {
            $query->addSelect('email');
        }
        if (Schema::hasColumn('leads', 'email_address')) {
            $query->addSelect('email_address');
        }

        return $query->first();
    }

    private function createStepLog(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        array $payload,
        array $plannedDelivery = [],
        array $actualDelivery = []
    ): LeadRemarketingStepLog {
        $merged = array_merge([
            'lead_id' => $progress->lead_id,
            'remarketing_step_id' => $currentStep->id,
            'step_order' => $currentStep->step_order,
            'template_id' => $currentStep->template_id,
            'planned_medium' => $plannedDelivery['planned_medium'] ?? null,
            'actual_medium' => $actualDelivery['actual_medium'] ?? null,
            'planned_template_key' => $plannedDelivery['planned_template_key'] ?? null,
            'actual_template_key' => $actualDelivery['actual_template_key'] ?? null,
            'fallback_used' => (bool) ($actualDelivery['fallback_used'] ?? false),
            'fallback_reason' => $actualDelivery['fallback_reason'] ?? null,
            'provider' => $actualDelivery['provider'] ?? ($plannedDelivery['provider'] ?? null),
            'provider_template_id' => $actualDelivery['provider_template_id'] ?? ($plannedDelivery['provider_template_id'] ?? null),
            'execution_error' => $actualDelivery['execution_error'] ?? null,
        ], $payload);

        return LeadRemarketingStepLog::query()->create($this->columnSafeLogPayload($merged));
    }

    private function columnSafeLogPayload(array $payload): array
    {
        $allowed = [];
        foreach ($payload as $column => $value) {
            if (! isset($this->stepLogColumnCache[$column])) {
                $this->stepLogColumnCache[$column] = Schema::hasColumn('lead_remarketing_step_logs', $column);
            }

            if ($this->stepLogColumnCache[$column]) {
                $allowed[$column] = $value;
            }
        }

        return $allowed;
    }

    private function applyProgressAdvance(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        $allSteps,
        Carbon $completedAt,
        array &$updates
    ): bool {
        if (! (bool) $currentStep->auto_advance_on_send) {
            $updates['status'] = 'waiting';
            $updates['next_step_due_at'] = $progress->next_step_due_at;

            return false;
        }

        $followingStep = $allSteps->first(
            static fn (RemarketingStep $step): bool => $step->step_order > $currentStep->step_order
        );

        if ($followingStep === null) {
            $updates['status'] = 'completed';
            $updates['next_step_due_at'] = null;

            return true;
        }

        $rawNextDue = $completedAt->copy()->addMinutes((int) $followingStep->delay_minutes);
        $updates['next_step_due_at'] = $this->scheduleWindowService->nextAllowedTime($followingStep, $rawNextDue);

        return true;
    }

    private function resolveJourneyStepQuery(RemarketingStep $currentStep): Builder
    {
        if (str_starts_with((string) $currentStep->step_key, 'cbna_')) {
            return RemarketingStep::query()->where('step_key', 'like', 'cbna_%');
        }

        return RemarketingStep::query();
    }

    private function resolveJourneyScopedSteps($allSteps, ?RemarketingStep $currentStep)
    {
        if ($currentStep === null) {
            return $allSteps;
        }

        if (str_starts_with((string) $currentStep->step_key, 'cbna_')) {
            return $this->resolveJourneyStepQuery($currentStep)
                ->where('is_active', true)
                ->orderBy('step_order')
                ->get();
        }

        return $allSteps;
    }
}
