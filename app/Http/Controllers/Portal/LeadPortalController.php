<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadPortalToken;
use App\Services\LeadPortalProgressService;
use App\Services\LeadPortalTokenService;
use Illuminate\Http\Request;

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
            return $this->expiredResponse();
        }

        if (! request()->session()->get($this->verificationSessionKey($portalToken), false)) {
            return view('portal.verify');
        }

        return $this->entryResponse($portalToken->lead);
    }

    public function verify(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        $dobInput = trim((string) $request->input('dob', ''));
        $postcodeInput = trim((string) $request->input('postcode', ''));

        if ($dobInput === '' && $postcodeInput === '') {
            return back()
                ->withInput()
                ->withErrors([
                    'verification' => 'That doesn’t look quite right. Please check and try again.',
                ]);
        }

        if (! $this->passesSoftVerification($portalToken->lead, $dobInput, $postcodeInput)) {
            return back()
                ->withInput()
                ->withErrors([
                    'verification' => 'That doesn’t look quite right. Please check and try again.',
                ]);
        }

        $request->session()->put($this->verificationSessionKey($portalToken), true);

        return redirect()->route('portal.entry', ['token' => $token]);
    }

    private function entryResponse(Lead $lead)
    {
        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $progress->last_seen_at = now();
        $progress->save();

        return view('portal.entry', [
            'progress' => $progress,
        ]);
    }

    private function expiredResponse()
    {
        return response()->view('portal.expired', [], 410);
    }

    private function verificationSessionKey(LeadPortalToken $portalToken): string
    {
        return 'portal_verified_'.$portalToken->lead_id.'_'.$portalToken->id;
    }

    private function passesSoftVerification(Lead $lead, string $dobInput, string $postcodeInput): bool
    {
        $leadDob = trim((string) ($lead->dob ?? ''));
        $leadPostcode = trim((string) ($lead->postcode ?? ''));

        $hasDobOnLead = $leadDob !== '';
        $hasPostcodeOnLead = $leadPostcode !== '';

        if (! $hasDobOnLead && ! $hasPostcodeOnLead) {
            return true;
        }

        $dobMatches = $dobInput !== '' && $hasDobOnLead && $dobInput === $leadDob;
        $postcodeMatches = $postcodeInput !== ''
            && $hasPostcodeOnLead
            && $this->normalizePostcode($postcodeInput) === $this->normalizePostcode($leadPostcode);

        return $dobMatches || $postcodeMatches;
    }

    private function normalizePostcode(string $postcode): string
    {
        return strtoupper(str_replace(' ', '', trim($postcode)));
    }
}
