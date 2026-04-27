<?php

namespace App\Console\Commands;

use App\Models\RemarketingStep;
use App\Services\RemarketingScheduleWindowService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RemarketingScheduleWindowTestCommand extends Command
{
    protected $signature = 'remarketing:schedule-window-test
        {medium : sms,email,call,whatsapp}
        {dueAt? : Optional due datetime, e.g. "2026-04-26 22:30:00"}';

    protected $description = 'Diagnostic command for remarketing allowed day/time logic.';

    public function __construct(private readonly RemarketingScheduleWindowService $windowService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $medium = strtolower((string) $this->argument('medium'));
        if (! in_array($medium, ['sms', 'email', 'call', 'whatsapp'], true)) {
            $this->error('Invalid medium. Use one of: sms,email,call,whatsapp');

            return self::FAILURE;
        }

        $dueAtInput = $this->argument('dueAt');
        $dueAt = $dueAtInput
            ? Carbon::parse((string) $dueAtInput, RemarketingScheduleWindowService::TIMEZONE)
            : Carbon::now(RemarketingScheduleWindowService::TIMEZONE);

        $step = new RemarketingStep([
            'medium' => $medium,
            'respect_send_window' => true,
            'send_window_start_time' => RemarketingScheduleWindowService::DEFAULT_START_TIME,
            'send_window_end_time' => RemarketingScheduleWindowService::DEFAULT_END_TIME,
            'allowed_days_json' => $medium === 'email'
                ? RemarketingScheduleWindowService::DAYS_ALL
                : RemarketingScheduleWindowService::DAYS_NO_SUNDAY,
        ]);

        $this->line('medium: '.$medium);
        $this->line('due_at: '.$dueAt->format('Y-m-d H:i:s'));
        $this->line('is_allowed_now: '.($this->windowService->isAllowedNow($step, $dueAt) ? 'true' : 'false'));
        $this->line('next_allowed_time: '.$this->windowService->nextAllowedTime($step, $dueAt)->format('Y-m-d H:i:s'));
        $this->line('allowed_days: '.json_encode($this->windowService->allowedDaysForStep($step)));

        return self::SUCCESS;
    }
}
