<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\LeadPortalProgressService;
use App\Services\LeadPortalTokenService;

class LeadPortalController extends Controller
{
    public function __construct(
        private readonly LeadPortalTokenService $leadPortalTokenService,
        private readonly LeadPortalProgressService $leadPortalProgressService
    ) {
    }

    public function show(string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, request()->ip());

        if (! $portalToken) {
            return response()
                ->view('portal.expired', [], 410);
        }

        $lead = $portalToken->lead;
        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $progress->last_seen_at = now();
        $progress->save();

        return view('portal.entry', [
            'progress' => $progress,
        ]);
    }
}
