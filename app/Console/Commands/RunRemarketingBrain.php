<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\RemarketingTask;
use App\Services\EmailService;
use App\Services\SmsService;
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

    public function __construct(
        private SmsService $smsService,
        private EmailService $emailService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $overdueWhatsAppTasks = RemarketingTask::query()
            ->where('task_type', 'whatsapp')
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subHours(2))
            ->get();

        foreach ($overdueWhatsAppTasks as $whatsAppTask) {
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
            ->where('created_at', '<=', now()->subHours(12))
            ->get();

        foreach ($overdueWhatsAppEmailTasks as $whatsAppTask) {
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
}
