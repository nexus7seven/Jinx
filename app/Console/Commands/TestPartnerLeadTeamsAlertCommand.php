<?php

namespace App\Console\Commands;

use App\Services\PartnerLeadTeamsNotificationService;
use Illuminate\Console\Command;

class TestPartnerLeadTeamsAlertCommand extends Command
{
    protected $signature = 'partner-lead:test-teams-alert';

    protected $description = 'Send a test Microsoft Teams alert for partner lead webhook configuration';

    public function handle(PartnerLeadTeamsNotificationService $service): int
    {
        $this->info('Partner lead Teams webhook configured: '.($service->webhookIsConfigured() ? 'yes' : 'no'));
        $this->info('Partner lead Teams payload key: '.$service->payloadKey());
        $this->info('Partner lead Teams payload mode: '.$service->payloadMode());

        if (! $service->webhookIsConfigured()) {
            $this->warn('Webhook is not configured. Set TEAMS_PARTNER_LEAD_WEBHOOK_URL to run this test.');

            return self::FAILURE;
        }

        $message = "🚨 TEST TRANSFER ALERT FROM JINX\nIf you can see this in Teams, the webhook is working.";
        $adaptiveBody = 'If you can see this in Teams, adaptive cards are working.';

        try {
            $response = $service->payloadMode() === 'adaptive_card'
                ? $service->sendRawTestAdaptiveCard('🚨 TEST TRANSFER ALERT FROM JINX', $adaptiveBody)
                : $service->sendRawTestMessage($message);

            if ($response->successful()) {
                $this->info('Test alert sent successfully. HTTP status: '.$response->status());

                return self::SUCCESS;
            }

            $this->error('Test alert failed. HTTP status: '.$response->status());
            $this->line('Response snippet: '.$this->responseSnippet($response->body()));

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Test alert request failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function responseSnippet(string $body): string
    {
        $snippet = trim($body);

        if ($snippet === '') {
            return '—';
        }

        return mb_substr($snippet, 0, 300);
    }
}
