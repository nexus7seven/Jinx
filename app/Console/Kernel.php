<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        if (env('REMARKETING_CBNA_SCANNER_ENABLED', false)) {
            $schedule->command('remarketing:scan-cbna --commit --limit=50')
                ->everyMinute()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/remarketing-cbna-scan.log'));
        }

        if (env('REMARKETING_LINEAR_EXECUTOR_ENABLED', false)) {
            $schedule->command('remarketing:linear-execute --commit --limit=50')
                ->everyMinute()
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/remarketing-linear-execute.log'));
        }
    }
}
