<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingProgress;
use App\Models\LeadRemarketingStepLog;
use App\Models\RemarketingStep;
use App\Services\RemarketingScheduleWindowService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RemarketingLinearExecuteCommand extends Command
{
    protected $signature = 'remarketing:linear-execute
        {--lead_id= : Optional single vicidial lead_id}
        {--limit=50 : Max number of progress rows to inspect}
        {--json : Output JSON instead of human report}
        {--commit : Commit planner decisions to linear progress/log tables}
        {--only-log : Commit mode guard to ensure no external actions are used}
        {--send-sms : Send SMS only for explicit single-lead commit mode}';

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

        if ($commit && ! $onlyLog && ! $sendSms) {
            $this->error('Live execution not implemented yet. Use --only-log or --send-sms.');

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
        $summary = [
            'total_checked' => 0,
            'due_now' => 0,
            'would_execute' => 0,
            'skipped' => 0,
            'committed' => 0,
            'advanced' => 0,
            'sms_sent' => 0,
            'sms_failed' => 0,
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
            $smsAction = 'skipped';
            $smsTo = null;
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

            if ($commit && $this->isCommitEligible($executionAction, $isDueNow) && $nextStep !== null) {
                if ($onlyLog) {
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
                } elseif ($sendSms) {
                    if ($executionAction !== 'would_send_sms') {
                        $commitAction = 'skipped_non_sms_step';
                        $smsAction = 'skipped';
                        $errorMessage = 'execution_action is not would_send_sms';
                    } else {
                        $sendResult = $this->commitSmsStepDecision(
                            progress: $progress,
                            currentStep: $nextStep,
                            allSteps: $steps,
                            executionAction: $executionAction,
                            dueAt: $dueAt,
                            now: $now
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
                    }
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
                'sms_action' => $smsAction,
                'sms_to' => $smsTo,
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
                $this->line('Next step: '.($row['next_step_order'] !== null ? $row['next_step_order'].' '.$row['next_step_key'] : 'none'));
                $this->line('Medium: '.($row['medium'] ?? 'n/a'));
                $this->line('Due at: '.($row['due_at'] ?? 'n/a'));
                $this->line('Next allowed time: '.($row['next_allowed_time'] ?? 'n/a'));
                $this->line('Due now: '.($row['is_due_now'] ? 'yes' : 'no'));
                $this->line('Allowed now: '.($row['is_allowed_now'] ? 'yes' : 'no'));
                $this->line('Execution decision: '.$row['execution_action']);
                $this->line('SMS action: '.$row['sms_action']);
                $this->line('SMS to: '.($row['sms_to'] ?? '-'));
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

    private function commitSmsStepDecision(
        LeadRemarketingProgress $progress,
        RemarketingStep $currentStep,
        $allSteps,
        string $executionAction,
        ?Carbon $dueAt,
        Carbon $now
    ): array {
        if ($currentStep->medium !== 'sms') {
            return [
                'committed' => false,
                'advanced' => false,
                'sms_action' => 'skipped',
                'sms_to' => null,
                'provider_message_id' => null,
                'error_message' => 'next step medium is not sms',
                'commit_action' => 'skipped_non_sms_step',
            ];
        }

        $leadData = $this->fetchVicidialLeadData((int) $progress->lead_id);
        $normalizedPhone = $this->normalizeUkPhone((string) ($leadData['phone_number'] ?? ''));

        if ($normalizedPhone === null) {
            $error = 'Missing or invalid phone number for lead.';
            $this->logFailedSmsStep($progress, $currentStep, $executionAction, $dueAt, $now, null, $error);

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
            $this->logFailedSmsStep($progress, $currentStep, $executionAction, $dueAt, $now, $normalizedPhone, $error);

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

        $messageBody = $this->renderTemplateBody(
            $templateBody,
            (string) ($leadData['first_name'] ?? ''),
            (int) $progress->lead_id
        );

        $sendResult = $this->sendSmsViaTwilio($normalizedPhone, $messageBody);
        if (! $sendResult['success']) {
            $this->logFailedSmsStep(
                $progress,
                $currentStep,
                $executionAction,
                $dueAt,
                $now,
                $normalizedPhone,
                $sendResult['error'] ?? 'Unknown Twilio error.'
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
            $from
        ): array {
            LeadRemarketingStepLog::query()->create([
                'lead_id' => $progress->lead_id,
                'remarketing_step_id' => $currentStep->id,
                'step_order' => $currentStep->step_order,
                'medium' => $currentStep->medium,
                'template_id' => $currentStep->template_id,
                'status' => 'sent',
                'due_at' => $dueAt,
                'started_at' => $now->copy(),
                'completed_at' => $now->copy(),
                'failed_at' => null,
                'provider_message_id' => $providerSid,
                'error_message' => null,
                'created_task_id' => null,
                'context_json' => [
                    'mode' => 'commit_send_sms',
                    'to' => $normalizedPhone,
                    'from' => $from,
                    'execution_action' => $executionAction,
                ],
            ]);

            $followingStep = $allSteps->first(
                static fn (RemarketingStep $step): bool => $step->step_order > $currentStep->step_order
            );

            $updates = [
                'current_step_id' => $currentStep->id,
                'current_step_order' => $currentStep->step_order,
                'last_step_completed_at' => $now->copy(),
                'status' => 'active',
            ];

            if ($followingStep === null) {
                $updates['status'] = 'completed';
                $updates['next_step_due_at'] = null;
            } else {
                $rawNextDue = $now->copy()->addMinutes((int) $followingStep->delay_minutes);
                $updates['next_step_due_at'] = $this->scheduleWindowService->nextAllowedTime($followingStep, $rawNextDue);
            }

            $progress->update($updates);

            return [
                'committed' => true,
                'advanced' => true,
                'sms_action' => 'sent',
                'sms_to' => $normalizedPhone,
                'provider_message_id' => $providerSid,
                'error_message' => null,
                'commit_action' => 'sent_logged_and_advanced',
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

    private function normalizeUkPhone(string $phone): ?string
    {
        $value = trim($phone);
        if ($value === '') {
            return null;
        }

        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/[^\d]/', '', $value);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if ($hasPlus) {
            $normalized = '+' . $digits;
        } elseif (str_starts_with($digits, '44')) {
            $normalized = '+' . $digits;
        } elseif (str_starts_with($digits, '0')) {
            $normalized = '+44' . substr($digits, 1);
        } else {
            return null;
        }

        if (! preg_match('/^\+\d{10,15}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function renderTemplateBody(string $templateBody, string $firstName, int $leadId): string
    {
        return str_replace(
            ['{{first_name}}', '{{lead_id}}'],
            [$firstName, (string) $leadId],
            $templateBody
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
        string $error
    ): void {
        LeadRemarketingStepLog::query()->create([
            'lead_id' => $progress->lead_id,
            'remarketing_step_id' => $currentStep->id,
            'step_order' => $currentStep->step_order,
            'medium' => $currentStep->medium,
            'template_id' => $currentStep->template_id,
            'status' => 'failed',
            'due_at' => $dueAt,
            'started_at' => $now->copy(),
            'completed_at' => null,
            'failed_at' => $now->copy(),
            'provider_message_id' => null,
            'error_message' => $error,
            'created_task_id' => null,
            'context_json' => [
                'mode' => 'commit_send_sms',
                'to' => $to,
                'execution_action' => $executionAction,
                'note' => 'sms send failed; no progress advance',
            ],
        ]);
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
