<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadPortalLinkService;
use Illuminate\Console\Command;

class LeadPortalLinkGenerateCommand extends Command
{
    protected $signature = 'portal:link-generate {leadId : Jinx ID or Vicidial lead ID} {--json}';

    protected $description = 'Admin/internal diagnostic: generate a portal link for one lead without sending or resolving it.';

    public function __construct(
        private readonly LeadPortalLinkService $leadPortalLinkService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $leadId = (string) $this->argument('leadId');
        $lead = Lead::where('vicidial_lead_id', $leadId)
            ->orWhere('id', $leadId)
            ->first();

        if (! $lead) {
            $message = 'Lead not found for given Jinx ID or Vicidial lead ID: '.$leadId;

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
            'jinx_lead_id' => $lead->id,
            'vicidial_lead_id' => $lead->vicidial_lead_id,
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
        $this->line('jinx_lead_id: '.$payload['jinx_lead_id']);
        $this->line('vicidial_lead_id: '.($payload['vicidial_lead_id'] ?? 'null'));
        $this->line('portal_token_id: '.$payload['portal_token_id']);
        $this->line('token_status: '.$payload['token_status']);
        $this->line('activated_at: '.($payload['activated_at'] ?? 'null'));
        $this->line('expires_at: '.($payload['expires_at'] ?? 'null'));
        $this->line('portal_url: '.$payload['portal_url']);

        return self::SUCCESS;
    }
}
