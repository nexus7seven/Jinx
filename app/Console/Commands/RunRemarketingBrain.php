<?php

namespace App\Console\Commands;

use App\Models\RemarketingTask;
use App\Services\SmsService;
use Illuminate\Console\Command;

class RunRemarketingBrain extends Command
{
    protected $signature = 'remarketing:run';

    protected $description = 'Run remarketing automation steps.';

    private const SMS_REASON_PREFIX = 'sms_follow_up';

    private const SMS_MESSAGE = 'Hi, just a quick message regarding your debt enquiry. You could be eligible to write off a large portion of your debt. Find out more here: https://clearmycredit.co.uk/iva';

    public function __construct(
        private SmsService $smsService,
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

        return self::SUCCESS;
    }

    private function smsReasonForWhatsAppTask(RemarketingTask $whatsAppTask): string
    {
        return self::SMS_REASON_PREFIX . ':' . (int) $whatsAppTask->id;
    }
}
