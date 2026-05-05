<?php

namespace App\Console\Commands;

use App\Models\CreditCheckJobLog;
use App\Services\CreditCheckV3FlowService;
use Illuminate\Console\Command;

class CreditCheckImportExistingCommand extends Command
{
    protected $signature = 'credit-check:import-existing';

    protected $description = 'Import existing successful credit check v3 job logs from stored DB payloads';

    public function handle(CreditCheckV3FlowService $service): int
    {
        $logs = CreditCheckJobLog::query()
            ->where('status', CreditCheckJobLog::STATUS_SUCCESS)
            ->orderBy('id')
            ->get();

        $this->info('Found '.$logs->count().' success logs to import.');

        foreach ($logs as $log) {
            $result = $service->importFromJobLog($log);
            $ok = (bool) ($result['payload']['ok'] ?? false);
            $this->line(($ok ? '[OK] ' : '[FAIL] ').'log_id='.$log->id.' job_id='.$log->external_job_id.' status='.$result['http']);
        }

        return self::SUCCESS;
    }
}
