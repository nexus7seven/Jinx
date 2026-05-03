<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadPortalShortLink;
use App\Models\LeadPortalToken;
use Illuminate\Support\Str;

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
            'portal_url' => $this->buildStaffFacingPortalUrl($issued['portal_token'], $issued['token']),
        ];
    }

    public function buildPortalUrl(string $rawToken): string
    {
        return $this->basePortalUrl().'/portal/'.$rawToken;
    }

    public function buildStaffFacingPortalUrl(LeadPortalToken $portalToken, string $fallbackRawToken): string
    {
        $shortLink = $this->findOrCreateShortLink($portalToken);

        if ($shortLink !== null) {
            return $this->publicPortalBaseUrl().'/p/'.$shortLink->short_code;
        }

        return $this->buildPortalUrl($fallbackRawToken);
    }

    public function findOrCreateShortLink(LeadPortalToken $portalToken): ?LeadPortalShortLink
    {
        $existing = LeadPortalShortLink::query()
            ->where('lead_portal_token_id', $portalToken->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $shortCode = Str::lower(Str::random(10));

            try {
                return LeadPortalShortLink::create([
                    'lead_portal_token_id' => $portalToken->id,
                    'lead_id' => $portalToken->lead_id,
                    'short_code' => $shortCode,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Retry on unique collisions.
            }
        }

        return null;
    }

    public function basePortalUrl(): string
    {
        $configured = (string) config('services.portal.base_url', config('app.url'));

        return rtrim($configured, '/');
    }

    public function publicPortalBaseUrl(): string
    {
        $configured = (string) config('services.portal.public_base_url', '');

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $this->basePortalUrl();
    }
}
