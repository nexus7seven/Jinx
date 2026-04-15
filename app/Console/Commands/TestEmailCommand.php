<?php

namespace App\Console\Commands;

use App\Services\EmailService;
use Illuminate\Console\Command;

class TestEmailCommand extends Command
{
    protected $signature = 'email:test {to}';

    protected $description = 'Temporary email test command.';

    public function __construct(
        private EmailService $emailService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        $subject = 'Test Email from Jinx';
        $html = '<h1>Clear My Credit</h1><p>This is a test email from your remarketing system.</p><a href="https://clearmycredit.co.uk/iva">Check your options</a>';

        $sent = $this->emailService->sendEmail($to, $subject, $html);

        if ($sent) {
            $this->info('EMAIL SENT');

            return self::SUCCESS;
        }

        $this->error('EMAIL FAILED');

        $detail = $this->emailService->lastError();
        if ($detail !== null && $detail !== '') {
            $this->line('Detail: ' . $detail);
        }

        return self::FAILURE;
    }
}
