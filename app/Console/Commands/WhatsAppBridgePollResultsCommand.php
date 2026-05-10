<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class WhatsAppBridgePollResultsCommand extends Command
{
    protected $signature = 'whatsapp-bridge:poll-results {--limit=50 : Max records to inspect in future implementation}';

    protected $description = 'Diagnostic placeholder for polling WhatsApp bridge send results.';

    public function handle(): int
    {
        $this->info('whatsapp-bridge:poll-results is currently a diagnostic placeholder.');
        $this->line('TODO: implement safe result mapping before enabling auto-completion/advance.');

        return self::SUCCESS;
    }
}
