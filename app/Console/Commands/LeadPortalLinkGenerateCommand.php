<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadPortalLinkService;
use Illuminate\Console\Command;

class LeadPortalLinkGenerateCommand extends Command
{
    protected $signature = 'portal:link-generate {leadId} {--json}';

    protected $description = 'Admin/internal diagnostic: generate a portal link for one lead without sending or resolving it.';

    public function __construct(
        private readonly LeadPortalLinkService $leadPortalLinkService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lead = Lead::find($this->argument('leadId'));

        if (! $lead) {
            $message = 'Lead not found for ID '.$this->argument('leadId').'.';

            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'message' => $message,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $generated = $this->leadPortalLinkService->generateForLead($lead);
        $portalToken = $generated['portal_token']->fresh();

        $payload = [
            'ok' => true,
            'message' => 'Admin/internal diagnostic only. Token issued but not resolved, activated, or sent.',
            'lead_id' => $lead->id,
            'portal_token_id' => $portalToken->id,
            'token_status' => $portalToken->status,
            'activated_at' => $portalToken->activated_at?->toDateTimeString(),
            'expires_at' => $portalToken->expires_at?->toDateTimeString(),
            'portal_url' => $generated['portal_url'],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->warn('Admin/internal diagnostic only. Do not treat this as a public portal flow.');
        $this->line('lead_id: '.$payload['lead_id']);
        $this->line('portal_token_id: '.$payload['portal_token_id']);
        $this->line('token_status: '.$payload['token_status']);
        $this->line('activated_at: '.($payload['activated_at'] ?? 'null'));
        $this->line('expires_at: '.($payload['expires_at'] ?? 'null'));
        $this->line('portal_url: '.$payload['portal_url']);

        return self::SUCCESS;
    }
}
