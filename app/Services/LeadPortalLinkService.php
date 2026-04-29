<?php

namespace App\Services;

use App\Models\Lead;

class LeadPortalLinkService
{
    public function __construct(
        private LeadPortalTokenService $leadPortalTokenService
    ) {
    }

    /**
     * @return array{token: string, portal_token: \App\Models\LeadPortalToken, portal_url: string}
     */
    public function generateForLead(Lead $lead, ?string $createdIp = null): array
    {
        $issued = $this->leadPortalTokenService->issueForLead($lead, $createdIp);

        return [
            'token' => $issued['token'],
            'portal_token' => $issued['portal_token'],
            'portal_url' => $this->buildPortalUrl($issued['token']),
        ];
    }

    public function buildPortalUrl(string $rawToken): string
    {
        return $this->basePortalUrl().'/portal/'.$rawToken;
    }

    public function basePortalUrl(): string
    {
        $configured = (string) config('services.portal.base_url', config('app.url'));

        return rtrim($configured, '/');
    }
}
