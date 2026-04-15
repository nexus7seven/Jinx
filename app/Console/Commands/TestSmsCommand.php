<?php

namespace App\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;

class TestSmsCommand extends Command
{
    protected $signature = 'sms:test {to}';

    protected $description = 'Temporary SMS test command.';

    public function __construct(
        private SmsService $smsService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        $sent = $this->smsService->sendSms($to, 'Test SMS from Jinx');

        if ($sent) {
            $this->info('SMS SENT');

            return self::SUCCESS;
        }

        $this->error('SMS FAILED');

        $detail = $this->smsService->lastError();
        if ($detail !== null && $detail !== '') {
            $this->line('Detail: ' . $detail);
        }

        return self::FAILURE;
    }
}
