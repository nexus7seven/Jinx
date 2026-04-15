<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\RemarketingTask;
use App\Services\EmailService;
use App\Services\RemarketingTaskService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunRemarketingBrain extends Command
{
    protected $signature = 'remarketing:run';

    protected $description = 'Run remarketing automation steps.';

    private const SMS_REASON_PREFIX = 'sms_follow_up';

    private const SMS_MESSAGE = 'Hi, just a quick message regarding your debt enquiry. You could be eligible to write off a large portion of your debt. Find out more here: https://clearmycredit.co.uk/iva';

    private const EMAIL_REASON_PREFIX = 'email_1';

    private const EMAIL_SUBJECT = 'Struggling with debt? You may qualify for help';

    private const EMAIL_HTML = '<h1>Clear My Credit</h1><p>You could write off a large portion of your debt and stop creditor pressure.</p><p>Many people reduce their monthly payments to something affordable.</p><p><a href="https://clearmycredit.co.uk/iva">Check if you qualify here</a></p>';

    private const EMAIL_2_REASON_PREFIX = 'email_2';

    private const EMAIL_2_SUBJECT = 'Complete this in your own time';

    private const EMAIL_2_HTML = '<h1>Clear My Credit</h1><p>If now is not a good time to speak, you can complete everything in your own time through our self-serve portal.</p><p>You can add your debts, complete your income and expenditure, and upload your documents when it suits you.</p><p><a href="https://hextech.lol/portal/start">Start here</a></p>';

    /**
     * @var array<int, string>
     */
    private const ACTIVE_REMARKETING_TASK_TYPES = ['call', 'whatsapp'];

    public function __construct(
        private SmsService $smsService,
        private EmailService $emailService,
        private RemarketingTaskService $remarketingTaskService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $overdueWhatsAppTasks = RemarketingTask::query()
            ->where('task_type', 'whatsapp')
            ->where('status', 'pending')
            ->get();

        foreach ($overdueWhatsAppTasks as $whatsAppTask) {
            $flowStartedAt = $this->flowStartedAtForWhatsAppTask($whatsAppTask);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(2))) {
                continue;
            }

            $smsReason = $this->smsReasonForWhatsAppTask($whatsAppTask);

            $smsAlreadySent = RemarketingTask::query()
                ->where('lead_id', $whatsAppTask->lead_id)
                ->where('task_type', 'sms_sent')
                ->where('reason', $smsReason)
                ->exists();

            if ($smsAlreadySent) {
                continue;
            }

            $sent = $this->smsService->sendSms(
                (string) $whatsAppTask->phone,
                self::SMS_MESSAGE
            );

            if (! $sent) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $whatsAppTask->lead_id,
                'lead_name' => $whatsAppTask->lead_name,
                'phone' => $whatsAppTask->phone,
                'campaign_id' => $whatsAppTask->campaign_id,
                'task_type' => 'sms_sent',
                'reason' => $smsReason,
                'stage' => $whatsAppTask->stage,
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

        $overdueWhatsAppEmailTasks = RemarketingTask::query()
            ->where('task_type', 'whatsapp')
            ->where('status', 'pending')
            ->get();

        foreach ($overdueWhatsAppEmailTasks as $whatsAppTask) {
            $flowStartedAt = $this->flowStartedAtForWhatsAppTask($whatsAppTask);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(12))) {
                continue;
            }

            $emailReason = $this->emailReasonForWhatsAppTask($whatsAppTask);

            $emailAlreadySent = RemarketingTask::query()
                ->where('lead_id', $whatsAppTask->lead_id)
                ->where('task_type', 'email_sent')
                ->where('reason', $emailReason)
                ->exists();

            if ($emailAlreadySent) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', (int) $whatsAppTask->lead_id)
                ->first();

            $toEmail = trim((string) ($lead?->email ?? ''));
            if ($toEmail === '') {
                continue;
            }

            $sent = $this->emailService->sendEmail(
                $toEmail,
                self::EMAIL_SUBJECT,
                self::EMAIL_HTML
            );

            if (! $sent) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $whatsAppTask->lead_id,
                'lead_name' => $whatsAppTask->lead_name,
                'phone' => $whatsAppTask->phone,
                'campaign_id' => $whatsAppTask->campaign_id,
                'task_type' => 'email_sent',
                'reason' => $emailReason,
                'stage' => $whatsAppTask->stage,
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

        $overdueWhatsAppEmail2Tasks = RemarketingTask::query()
            ->where('task_type', 'whatsapp')
            ->where('status', 'pending')
            ->get();

        foreach ($overdueWhatsAppEmail2Tasks as $whatsAppTask) {
            $flowStartedAt = $this->flowStartedAtForWhatsAppTask($whatsAppTask);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(24))) {
                continue;
            }

            $emailReason = $this->email2ReasonForWhatsAppTask($whatsAppTask);

            $emailAlreadySent = RemarketingTask::query()
                ->where('lead_id', $whatsAppTask->lead_id)
                ->where('task_type', 'email_sent')
                ->where('reason', $emailReason)
                ->exists();

            if ($emailAlreadySent) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', (int) $whatsAppTask->lead_id)
                ->first();

            $toEmail = trim((string) ($lead?->email ?? ''));
            if ($toEmail === '') {
                continue;
            }

            $sent = $this->emailService->sendEmail(
                $toEmail,
                self::EMAIL_2_SUBJECT,
                self::EMAIL_2_HTML
            );

            if (! $sent) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $whatsAppTask->lead_id,
                'lead_name' => $whatsAppTask->lead_name,
                'phone' => $whatsAppTask->phone,
                'campaign_id' => $whatsAppTask->campaign_id,
                'task_type' => 'email_sent',
                'reason' => $emailReason,
                'stage' => $whatsAppTask->stage,
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

        $pendingFreshTasks = RemarketingTask::query()
            ->where('status', 'pending')
            ->where('stage', 'fresh')
            ->whereIn('task_type', ['call', 'whatsapp'])
            ->get();

        $processedLeadIds = [];
        foreach ($pendingFreshTasks as $task) {
            $leadId = $task->lead_id !== null ? (int) $task->lead_id : 0;
            if ($leadId <= 0 || isset($processedLeadIds[$leadId])) {
                continue;
            }

            $processedLeadIds[$leadId] = true;

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId) ?? $task->created_at;
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(24))) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            RemarketingTask::query()
                ->where('lead_id', $leadId)
                ->where('status', 'pending')
                ->where('stage', 'fresh')
                ->update([
                    'stage' => 'cooling',
                ]);
        }

        $coolingReason = $this->remarketingTaskService->resolveReason('cooling_call_follow_up');
        $pendingCoolingTasks = RemarketingTask::query()
            ->where('stage', 'cooling')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedCoolingLeadIds = [];
        foreach ($pendingCoolingTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedCoolingLeadIds[$leadId])) {
                continue;
            }

            $processedCoolingLeadIds[$leadId] = true;

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $existingPendingCall = RemarketingTask::query()
                ->where('lead_id', $leadId)
                ->where('task_type', 'call')
                ->where('status', 'pending')
                ->exists();

            if ($existingPendingCall) {
                continue;
            }

            $existingCoolingCall = RemarketingTask::query()
                ->where('lead_id', $leadId)
                ->where('task_type', 'call')
                ->where('stage', 'cooling')
                ->where('status', 'pending')
                ->where('reason', $coolingReason)
                ->exists();

            if ($existingCoolingCall) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => 'MAIN',
                'task_type' => 'call',
                'reason' => $coolingReason,
                'stage' => 'cooling',
                'status' => 'pending',
                'time_waiting_text' => '0h',
            ]);
        }

        return self::SUCCESS;
    }

    private function smsReasonForWhatsAppTask(RemarketingTask $whatsAppTask): string
    {
        return self::SMS_REASON_PREFIX . ':' . (int) $whatsAppTask->id;
    }

    private function emailReasonForWhatsAppTask(RemarketingTask $whatsAppTask): string
    {
        return self::EMAIL_REASON_PREFIX . ':' . (int) $whatsAppTask->id;
    }

    private function email2ReasonForWhatsAppTask(RemarketingTask $whatsAppTask): string
    {
        return self::EMAIL_2_REASON_PREFIX . ':' . (int) $whatsAppTask->id;
    }

    private function flowStartedAtForWhatsAppTask(RemarketingTask $whatsAppTask): ?Carbon
    {
        if ($whatsAppTask->lead_id === null) {
            return $whatsAppTask->created_at;
        }

        $flowStartTask = RemarketingTask::query()
            ->where('lead_id', $whatsAppTask->lead_id)
            ->where('task_type', 'flow_started')
            ->where('reason', 'flow_start')
            ->orderBy('id')
            ->first();

        return $flowStartTask?->created_at ?? $whatsAppTask->created_at;
    }

    private function flowStartedAtForLeadId(int $leadId): ?Carbon
    {
        if ($leadId <= 0) {
            return null;
        }

        $flowStartTask = RemarketingTask::query()
            ->where('lead_id', $leadId)
            ->where('task_type', 'flow_started')
            ->where('reason', 'flow_start')
            ->orderBy('id')
            ->first();

        return $flowStartTask?->created_at;
    }
}
