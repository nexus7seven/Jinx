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

    private const COOLING_SMS_REASON_PREFIX = 'cooling_sms_follow_up';

    private const COOLING_SMS_MESSAGE = "Hi, just checking in regarding your debt enquiry. If you'd prefer, you can still find out whether you qualify for help here: https://clearmycredit.co.uk/iva";

    private const COLD_SMS_REASON_PREFIX = 'cold_sms_follow_up';

    private const COLD_SMS_MESSAGE = 'Hi, just following up in case you still wanted to look into your debt options. You can check whether you may qualify for help here: https://clearmycredit.co.uk/iva';

    private const EMAIL_REASON_PREFIX = 'email_1';

    private const EMAIL_SUBJECT = 'Struggling with debt? You may qualify for help';

    private const EMAIL_HTML = '<h1>Clear My Credit</h1><p>You could write off a large portion of your debt and stop creditor pressure.</p><p>Many people reduce their monthly payments to something affordable.</p><p><a href="https://clearmycredit.co.uk/iva">Check if you qualify here</a></p>';

    private const EMAIL_2_REASON_PREFIX = 'email_2';

    private const EMAIL_2_SUBJECT = 'Complete this in your own time';

    private const EMAIL_2_HTML = '<h1>Clear My Credit</h1><p>If now is not a good time to speak, you can complete everything in your own time through our self-serve portal.</p><p>You can add your debts, complete your income and expenditure, and upload your documents when it suits you.</p><p><a href="https://hextech.lol/portal/start">Start here</a></p>';

    private const COOLING_EMAIL_3_REASON_PREFIX = 'cooling_email_3_follow_up';

    private const COOLING_EMAIL_3_SUBJECT = 'A quick reminder about your debt options';

    private const COOLING_EMAIL_3_HTML = '<h1>Clear My Credit</h1><p>Just a quick reminder that help may still be available if you\'re struggling with debt.</p><p>You may be able to reduce your monthly payments and write off a large portion of what you owe.</p><p>If you\'d rather do things in your own time, you can also use our self-serve portal.</p><p><a href="https://clearmycredit.co.uk/iva">See if you qualify</a></p><p><a href="https://hextech.lol/portal/start">Use the self-serve portal</a></p>';

    private const COLD_EMAIL_PORTAL_PUSH_REASON = 'cold_email_portal_push';

    private const COLD_EMAIL_PORTAL_PUSH_SUBJECT = 'Complete this in your own time';

    private const COLD_EMAIL_PORTAL_PUSH_HTML = '<h1>Clear My Credit</h1><p>If now is not the right time to speak, you can still move things forward in your own time.</p><p>Our self-serve portal lets you add your debts, complete your income and expenditure, and upload documents when it suits you.</p><p><a href="https://hextech.lol/portal/start">Use the self-serve portal</a></p>';

    private const DORMANT_EMAIL_IVA_REENGAGEMENT_REASON = 'dormant_email_iva_reengagement';

    private const DORMANT_EMAIL_IVA_REENGAGEMENT_SUBJECT = 'Still looking for help with your debt?';

    private const DORMANT_EMAIL_IVA_REENGAGEMENT_HTML = '<h1>Clear My Credit</h1><p>If you\'re still struggling with debt, help may still be available.</p><p>You could still be eligible to reduce your monthly payments and write off a large portion of what you owe.</p><p><a href="https://clearmycredit.co.uk/iva">See if you may qualify</a></p>';

    private const DORMANT_SMS_PORTAL_NUDGE_REASON = 'dormant_sms_portal_nudge';

    private const DORMANT_SMS_PORTAL_NUDGE_MESSAGE = "Hi, if you'd still like to look at your debt options, you can do everything in your own time here: https://hextech.lol/portal/start";

    private const DORMANT_EMAIL_PORTAL_PUSH_REASON = 'dormant_email_portal_push';

    private const DORMANT_EMAIL_PORTAL_PUSH_SUBJECT = 'Complete this in your own time';

    private const DORMANT_EMAIL_PORTAL_PUSH_HTML = '<h1>Clear My Credit</h1><p>If now is not the right time to speak, you can still move things forward in your own time.</p><p>Our self-serve portal lets you add your debts, complete your income and expenditure, and upload documents whenever it suits you.</p><p><a href="https://hextech.lol/portal/start">Use the self-serve portal</a></p>';

    private const DORMANT_SMS_FINAL_NUDGE_REASON = 'dormant_sms_final_nudge';

    private const DORMANT_SMS_FINAL_NUDGE_MESSAGE = 'Hi, just a final quick nudge in case you still wanted to look at your debt options: https://hextech.lol/portal/start';

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
        $dormantWhatsAppFinalTouchReason = $this->remarketingTaskService->resolveReason('dormant_whatsapp_final_touch');

        $freshWhatsAppReason = $this->remarketingTaskService->resolveReason('whatsapp_follow_up');
        $overdueWhatsAppTasks = RemarketingTask::query()
            ->where('task_type', 'whatsapp')
            ->where('status', 'pending')
            ->where('stage', 'fresh')
            ->get();

        foreach ($overdueWhatsAppTasks as $whatsAppTask) {
            if ($whatsAppTask->lead_id !== null
                && $this->leadRemarketingTimelineStopped((int) $whatsAppTask->lead_id, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            if ((string) $whatsAppTask->reason !== $freshWhatsAppReason) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForWhatsAppTask($whatsAppTask);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(2))) {
                continue;
            }

            $smsReason = $this->smsReasonForWhatsAppTask($whatsAppTask);

            $smsAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $whatsAppTask->lead_id !== null ? (int) $whatsAppTask->lead_id : null,
                'sms_sent',
                $smsReason
            );

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

        $coolingWhatsAppReason = $this->remarketingTaskService->resolveReason('cooling_whatsapp_follow_up');
        $overdueCoolingWhatsAppTasks = RemarketingTask::query()
            ->where('task_type', 'whatsapp')
            ->where('status', 'pending')
            ->where('stage', 'cooling')
            ->get();

        foreach ($overdueCoolingWhatsAppTasks as $whatsAppTask) {
            if ($whatsAppTask->lead_id !== null
                && $this->leadRemarketingTimelineStopped((int) $whatsAppTask->lead_id, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            if ((string) $whatsAppTask->reason !== $coolingWhatsAppReason) {
                continue;
            }

            if ($whatsAppTask->created_at === null || $whatsAppTask->created_at->greaterThan(now()->subHours(24))) {
                continue;
            }

            $smsReason = $this->coolingSmsReasonForWhatsAppTask($whatsAppTask);

            $smsAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $whatsAppTask->lead_id !== null ? (int) $whatsAppTask->lead_id : null,
                'sms_sent',
                $smsReason
            );

            if ($smsAlreadySent) {
                continue;
            }

            $sent = $this->smsService->sendSms(
                (string) $whatsAppTask->phone,
                self::COOLING_SMS_MESSAGE
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
            if ($whatsAppTask->lead_id !== null
                && $this->leadRemarketingTimelineStopped((int) $whatsAppTask->lead_id, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForWhatsAppTask($whatsAppTask);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(12))) {
                continue;
            }

            $emailReason = $this->emailReasonForWhatsAppTask($whatsAppTask);

            $emailAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $whatsAppTask->lead_id !== null ? (int) $whatsAppTask->lead_id : null,
                'email_sent',
                $emailReason
            );

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
            if ($whatsAppTask->lead_id !== null
                && $this->leadRemarketingTimelineStopped((int) $whatsAppTask->lead_id, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForWhatsAppTask($whatsAppTask);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(24))) {
                continue;
            }

            $emailReason = $this->email2ReasonForWhatsAppTask($whatsAppTask);

            $emailAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $whatsAppTask->lead_id !== null ? (int) $whatsAppTask->lead_id : null,
                'email_sent',
                $emailReason
            );

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

        $overdueCoolingWhatsAppEmail3Tasks = RemarketingTask::query()
            ->where('task_type', 'whatsapp')
            ->where('status', 'pending')
            ->where('stage', 'cooling')
            ->get();

        foreach ($overdueCoolingWhatsAppEmail3Tasks as $whatsAppTask) {
            if ($whatsAppTask->lead_id !== null
                && $this->leadRemarketingTimelineStopped((int) $whatsAppTask->lead_id, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            if ((string) $whatsAppTask->reason !== $coolingWhatsAppReason) {
                continue;
            }

            if ($whatsAppTask->created_at === null || $whatsAppTask->created_at->greaterThan(now()->subHours(48))) {
                continue;
            }

            $emailReason = $this->coolingEmail3ReasonForWhatsAppTask($whatsAppTask);

            $emailAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $whatsAppTask->lead_id !== null ? (int) $whatsAppTask->lead_id : null,
                'email_sent',
                $emailReason
            );

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
                self::COOLING_EMAIL_3_SUBJECT,
                self::COOLING_EMAIL_3_HTML
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

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

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

        $pendingCoolingStageTasks = RemarketingTask::query()
            ->where('status', 'pending')
            ->where('stage', 'cooling')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->get();

        $processedCoolingToColdLeadIds = [];
        foreach ($pendingCoolingStageTasks as $task) {
            $leadId = $task->lead_id !== null ? (int) $task->lead_id : 0;
            if ($leadId <= 0 || isset($processedCoolingToColdLeadIds[$leadId])) {
                continue;
            }

            $processedCoolingToColdLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(72))) {
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
                ->where('stage', 'cooling')
                ->update([
                    'stage' => 'cold',
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

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

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

        $pendingColdStageTasks = RemarketingTask::query()
            ->where('status', 'pending')
            ->where('stage', 'cold')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->get();

        $processedColdToDormantLeadIds = [];
        foreach ($pendingColdStageTasks as $task) {
            $leadId = $task->lead_id !== null ? (int) $task->lead_id : 0;
            if ($leadId <= 0 || isset($processedColdToDormantLeadIds[$leadId])) {
                continue;
            }

            $processedColdToDormantLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(336))) {
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
                ->where('stage', 'cold')
                ->update([
                    'stage' => 'dormant',
                ]);
        }

        $coldWhatsAppReason = $this->remarketingTaskService->resolveReason('cold_whatsapp_follow_up');
        $pendingColdTasks = RemarketingTask::query()
            ->where('stage', 'cold')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedColdLeadIds = [];
        foreach ($pendingColdTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedColdLeadIds[$leadId])) {
                continue;
            }

            $processedColdLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $existingColdWhatsApp = RemarketingTask::query()
                ->where('lead_id', $leadId)
                ->where('task_type', 'whatsapp')
                ->where('stage', 'cold')
                ->where('status', 'pending')
                ->where('reason', $coldWhatsAppReason)
                ->exists();

            if ($existingColdWhatsApp) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => $task->campaign_id,
                'task_type' => 'whatsapp',
                'reason' => $coldWhatsAppReason,
                'stage' => 'cold',
                'status' => 'pending',
                'time_waiting_text' => '0h',
            ]);
        }

        $overdueColdWhatsAppTasks = RemarketingTask::query()
            ->where('task_type', 'whatsapp')
            ->where('status', 'pending')
            ->where('stage', 'cold')
            ->get();

        foreach ($overdueColdWhatsAppTasks as $whatsAppTask) {
            if ($whatsAppTask->lead_id !== null
                && $this->leadRemarketingTimelineStopped((int) $whatsAppTask->lead_id, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            if ((string) $whatsAppTask->reason !== $coldWhatsAppReason) {
                continue;
            }

            if ($whatsAppTask->created_at === null || $whatsAppTask->created_at->greaterThan(now()->subHours(48))) {
                continue;
            }

            $smsReason = $this->coldSmsReasonForWhatsAppTask($whatsAppTask);

            $smsAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $whatsAppTask->lead_id !== null ? (int) $whatsAppTask->lead_id : null,
                'sms_sent',
                $smsReason
            );

            if ($smsAlreadySent) {
                continue;
            }

            $sent = $this->smsService->sendSms(
                (string) $whatsAppTask->phone,
                self::COLD_SMS_MESSAGE
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

        $pendingColdEmailTasks = RemarketingTask::query()
            ->where('stage', 'cold')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedColdEmailLeadIds = [];
        foreach ($pendingColdEmailTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedColdEmailLeadIds[$leadId])) {
                continue;
            }

            $processedColdEmailLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(168))) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $toEmail = trim((string) ($lead?->email ?? ''));
            if ($toEmail === '') {
                continue;
            }

            $emailAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $leadId,
                'email_sent',
                self::COLD_EMAIL_PORTAL_PUSH_REASON
            );

            if ($emailAlreadySent) {
                continue;
            }

            $sent = $this->emailService->sendEmail(
                $toEmail,
                self::COLD_EMAIL_PORTAL_PUSH_SUBJECT,
                self::COLD_EMAIL_PORTAL_PUSH_HTML
            );

            if (! $sent) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => $task->campaign_id,
                'task_type' => 'email_sent',
                'reason' => self::COLD_EMAIL_PORTAL_PUSH_REASON,
                'stage' => 'cold',
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

        $pendingDormantEmailTasks = RemarketingTask::query()
            ->where('stage', 'dormant')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedDormantEmailLeadIds = [];
        foreach ($pendingDormantEmailTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedDormantEmailLeadIds[$leadId])) {
                continue;
            }

            $processedDormantEmailLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(504))) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $toEmail = trim((string) ($lead?->email ?? ''));
            if ($toEmail === '') {
                continue;
            }

            $emailAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $leadId,
                'email_sent',
                self::DORMANT_EMAIL_IVA_REENGAGEMENT_REASON
            );

            if ($emailAlreadySent) {
                continue;
            }

            $sent = $this->emailService->sendEmail(
                $toEmail,
                self::DORMANT_EMAIL_IVA_REENGAGEMENT_SUBJECT,
                self::DORMANT_EMAIL_IVA_REENGAGEMENT_HTML
            );

            if (! $sent) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => $task->campaign_id,
                'task_type' => 'email_sent',
                'reason' => self::DORMANT_EMAIL_IVA_REENGAGEMENT_REASON,
                'stage' => 'dormant',
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

        $pendingDormantSmsTasks = RemarketingTask::query()
            ->where('stage', 'dormant')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedDormantSmsLeadIds = [];
        foreach ($pendingDormantSmsTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedDormantSmsLeadIds[$leadId])) {
                continue;
            }

            $processedDormantSmsLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(672))) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $smsAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $leadId,
                'sms_sent',
                self::DORMANT_SMS_PORTAL_NUDGE_REASON
            );

            if ($smsAlreadySent) {
                continue;
            }

            $sent = $this->smsService->sendSms(
                (string) $task->phone,
                self::DORMANT_SMS_PORTAL_NUDGE_MESSAGE
            );

            if (! $sent) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => $task->campaign_id,
                'task_type' => 'sms_sent',
                'reason' => self::DORMANT_SMS_PORTAL_NUDGE_REASON,
                'stage' => 'dormant',
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

        $dormantWhatsAppReason = $this->remarketingTaskService->resolveReason('dormant_whatsapp_light_touch');
        $pendingDormantLightTouchTasks = RemarketingTask::query()
            ->where('stage', 'dormant')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedDormantWhatsAppLeadIds = [];
        foreach ($pendingDormantLightTouchTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedDormantWhatsAppLeadIds[$leadId])) {
                continue;
            }

            $processedDormantWhatsAppLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(840))) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $existingDormantWhatsApp = RemarketingTask::query()
                ->where('lead_id', $leadId)
                ->where('task_type', 'whatsapp')
                ->where('stage', 'dormant')
                ->where('status', 'pending')
                ->where('reason', $dormantWhatsAppReason)
                ->exists();

            if ($existingDormantWhatsApp) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => $task->campaign_id,
                'task_type' => 'whatsapp',
                'reason' => $dormantWhatsAppReason,
                'stage' => 'dormant',
                'status' => 'pending',
                'time_waiting_text' => '0h',
            ]);
        }

        $pendingDormantDay42PortalEmailTasks = RemarketingTask::query()
            ->where('stage', 'dormant')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedDormantDay42PortalEmailLeadIds = [];
        foreach ($pendingDormantDay42PortalEmailTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedDormantDay42PortalEmailLeadIds[$leadId])) {
                continue;
            }

            $processedDormantDay42PortalEmailLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(1008))) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $toEmail = trim((string) ($lead?->email ?? ''));
            if ($toEmail === '') {
                continue;
            }

            $emailAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $leadId,
                'email_sent',
                self::DORMANT_EMAIL_PORTAL_PUSH_REASON
            );

            if ($emailAlreadySent) {
                continue;
            }

            $sent = $this->emailService->sendEmail(
                $toEmail,
                self::DORMANT_EMAIL_PORTAL_PUSH_SUBJECT,
                self::DORMANT_EMAIL_PORTAL_PUSH_HTML
            );

            if (! $sent) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => $task->campaign_id,
                'task_type' => 'email_sent',
                'reason' => self::DORMANT_EMAIL_PORTAL_PUSH_REASON,
                'stage' => 'dormant',
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

        $pendingDormantDay49SmsTasks = RemarketingTask::query()
            ->where('stage', 'dormant')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedDormantDay49SmsLeadIds = [];
        foreach ($pendingDormantDay49SmsTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedDormantDay49SmsLeadIds[$leadId])) {
                continue;
            }

            $processedDormantDay49SmsLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(1176))) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $smsAlreadySent = $this->sentTaskExistsInCurrentCycle(
                $leadId,
                'sms_sent',
                self::DORMANT_SMS_FINAL_NUDGE_REASON
            );

            if ($smsAlreadySent) {
                continue;
            }

            $sent = $this->smsService->sendSms(
                (string) $task->phone,
                self::DORMANT_SMS_FINAL_NUDGE_MESSAGE
            );

            if (! $sent) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => $task->campaign_id,
                'task_type' => 'sms_sent',
                'reason' => self::DORMANT_SMS_FINAL_NUDGE_REASON,
                'stage' => 'dormant',
                'status' => 'completed',
                'time_waiting_text' => null,
            ]);
        }

        $pendingDormantDay56FinalWhatsAppTasks = RemarketingTask::query()
            ->where('stage', 'dormant')
            ->where('status', 'pending')
            ->whereIn('task_type', self::ACTIVE_REMARKETING_TASK_TYPES)
            ->whereNotNull('lead_id')
            ->get();

        $processedDormantDay56FinalWhatsAppLeadIds = [];
        foreach ($pendingDormantDay56FinalWhatsAppTasks as $task) {
            $leadId = (int) $task->lead_id;
            if ($leadId <= 0 || isset($processedDormantDay56FinalWhatsAppLeadIds[$leadId])) {
                continue;
            }

            $processedDormantDay56FinalWhatsAppLeadIds[$leadId] = true;

            if ($this->leadRemarketingTimelineStopped($leadId, $dormantWhatsAppFinalTouchReason)) {
                continue;
            }

            $flowStartedAt = $this->flowStartedAtForLeadId($leadId);
            if ($flowStartedAt === null || $flowStartedAt->greaterThan(now()->subHours(1344))) {
                continue;
            }

            $lead = Lead::query()
                ->where('vicidial_lead_id', $leadId)
                ->first();

            if ($lead !== null && $lead->wip_status === 'DEAD') {
                continue;
            }

            $existingDormantFinalWhatsApp = RemarketingTask::query()
                ->where('lead_id', $leadId)
                ->where('task_type', 'whatsapp')
                ->where('stage', 'dormant')
                ->where('status', 'pending')
                ->where('reason', $dormantWhatsAppFinalTouchReason)
                ->exists();

            if ($existingDormantFinalWhatsApp) {
                continue;
            }

            RemarketingTask::create([
                'lead_id' => $task->lead_id,
                'lead_name' => $task->lead_name,
                'phone' => $task->phone,
                'campaign_id' => $task->campaign_id,
                'task_type' => 'whatsapp',
                'reason' => $dormantWhatsAppFinalTouchReason,
                'stage' => 'dormant',
                'status' => 'pending',
                'time_waiting_text' => '0h',
            ]);
        }

        return self::SUCCESS;
    }

    private function leadRemarketingTimelineStopped(int $leadId, string $dormantWhatsAppFinalTouchReason): bool
    {
        if ($leadId <= 0) {
            return false;
        }

        $latestFlowStarted = RemarketingTask::query()
            ->where('lead_id', $leadId)
            ->where('task_type', 'flow_started')
            ->where('reason', 'flow_start')
            ->orderByDesc('id')
            ->first();

        if ($latestFlowStarted === null) {
            return false;
        }

        return RemarketingTask::query()
            ->where('lead_id', $leadId)
            ->where('task_type', 'whatsapp')
            ->where('reason', $dormantWhatsAppFinalTouchReason)
            ->whereIn('status', [
                RemarketingTask::STATUS_PENDING,
                RemarketingTask::STATUS_COMPLETED,
                RemarketingTask::STATUS_CLOSED,
            ])
            ->where('id', '>', $latestFlowStarted->id)
            ->exists();
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

    private function coolingSmsReasonForWhatsAppTask(RemarketingTask $whatsAppTask): string
    {
        return self::COOLING_SMS_REASON_PREFIX . ':' . (int) $whatsAppTask->id;
    }

    private function coldSmsReasonForWhatsAppTask(RemarketingTask $whatsAppTask): string
    {
        return self::COLD_SMS_REASON_PREFIX . ':' . (int) $whatsAppTask->id;
    }

    private function coolingEmail3ReasonForWhatsAppTask(RemarketingTask $whatsAppTask): string
    {
        return self::COOLING_EMAIL_3_REASON_PREFIX . ':' . (int) $whatsAppTask->id;
    }

    private function sentTaskExistsInCurrentCycle(?int $leadId, string $taskType, string $reason): bool
    {
        $query = RemarketingTask::query()
            ->where('task_type', $taskType)
            ->where('reason', $reason);

        if ($leadId === null) {
            $query->whereNull('lead_id');

            return $query->exists();
        }

        $query->where('lead_id', $leadId);

        $latestFlowStartedId = $this->latestFlowStartedTaskIdForLeadId($leadId);
        if ($latestFlowStartedId === null) {
            return $query->exists();
        }

        return $query
            ->where('id', '>', $latestFlowStartedId)
            ->exists();
    }

    private function latestFlowStartedTaskIdForLeadId(int $leadId): ?int
    {
        if ($leadId <= 0) {
            return null;
        }

        $flowStartTask = RemarketingTask::query()
            ->where('lead_id', $leadId)
            ->where('task_type', 'flow_started')
            ->where('reason', 'flow_start')
            ->orderByDesc('id')
            ->first();

        return $flowStartTask?->id;
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
            ->orderByDesc('id')
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
            ->orderByDesc('id')
            ->first();

        return $flowStartTask?->created_at;
    }
}
