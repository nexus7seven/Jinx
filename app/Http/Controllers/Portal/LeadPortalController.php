<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadPortalToken;
use App\Services\LeadPortalProgressService;
use App\Services\LeadPortalTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class LeadPortalController extends Controller
{
    private const VERIFY_MAX_ATTEMPTS = 5;
    private const VERIFY_DECAY_SECONDS = 600;
    private const VERIFY_GENERIC_ERROR = 'That doesn’t look quite right. Please check and try again.';
    private const VERIFY_RATE_LIMIT_ERROR = 'Too many attempts. Please wait a little while and try again.';

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

        return $this->entryResponse($portalToken->lead, $token);
    }

    public function completeWelcome(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return redirect()->route('portal.entry', ['token' => $token]);
        }

        $progress = $this->leadPortalProgressService->ensureForLead($portalToken->lead);
        $progress->last_completed_step = 'welcome';
        $progress->current_step = 'details';
        $progress->last_seen_at = now();
        $progress->save();

        return redirect()->route('portal.entry', ['token' => $token]);
    }

    public function verify(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        $rateLimitKey = $this->verificationRateLimitKey($token, $request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::VERIFY_MAX_ATTEMPTS)) {
            return back()
                ->withInput()
                ->withErrors([
                    'verification' => self::VERIFY_RATE_LIMIT_ERROR,
                ]);
        }

        $dobInput = trim((string) $request->input('dob', ''));
        $postcodeInput = trim((string) $request->input('postcode', ''));

        if ($dobInput === '' && $postcodeInput === '') {
            RateLimiter::hit($rateLimitKey, self::VERIFY_DECAY_SECONDS);

            return back()
                ->withInput()
                ->withErrors([
                    'verification' => self::VERIFY_GENERIC_ERROR,
                ]);
        }

        if (! $this->passesSoftVerification($portalToken->lead, $dobInput, $postcodeInput)) {
            RateLimiter::hit($rateLimitKey, self::VERIFY_DECAY_SECONDS);

            return back()
                ->withInput()
                ->withErrors([
                    'verification' => self::VERIFY_GENERIC_ERROR,
                ]);
        }

        RateLimiter::clear($rateLimitKey);
        $request->session()->put($this->verificationSessionKey($portalToken), true);

        return redirect()->route('portal.entry', ['token' => $token]);
    }

    private function entryResponse(Lead $lead, string $rawToken)
    {
        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        if (blank($progress->current_step)) {
            $progress->current_step = 'welcome';
        }
        $progress->last_seen_at = now();
        $progress->save();

        return view('portal.entry', [
            'progress' => $progress,
            'rawToken' => $rawToken,
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

    private function verificationRateLimitKey(string $rawToken, ?string $ip): string
    {
        return 'portal-verify|'.$this->leadPortalTokenService->hashRawToken($rawToken).'|'.($ip ?? 'unknown');
    }
}
