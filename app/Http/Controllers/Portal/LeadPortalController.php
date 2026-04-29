<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadPortalDebt;
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

    public function saveDetails(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return redirect()->route('portal.entry', ['token' => $token]);
        }

        $lead = $portalToken->lead;

        $validated = $request->validate([
            'first_name' => $this->requiredIfMissingRule($lead->first_name),
            'last_name' => $this->requiredIfMissingRule($lead->last_name),
            'dob' => ['nullable', 'date'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'postcode' => array_merge($this->requiredIfMissingRule($lead->postcode), ['string', 'max:20']),
            'house_number' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
        ]);

        $lead->first_name = $this->nullableString($validated['first_name'] ?? null);
        $lead->last_name = $this->nullableString($validated['last_name'] ?? null);
        $lead->dob = $this->nullableString($validated['dob'] ?? null);
        $lead->email = $this->nullableString($validated['email'] ?? null);
        $lead->phone_number = $this->nullableString($validated['phone'] ?? null);
        $lead->postcode = $this->normalizePostcodeForStorage($validated['postcode'] ?? null);
        $lead->house_number = $this->nullableString($validated['house_number'] ?? null);
        $lead->address_line_1 = $this->nullableString($validated['address_line_1'] ?? null);
        $lead->save();

        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $progress->last_completed_step = 'details';
        $progress->current_step = 'debts';
        $progress->last_seen_at = now();
        $progress->save();

        return redirect()->route('portal.entry', ['token' => $token]);
    }

    public function saveDebts(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return redirect()->route('portal.entry', ['token' => $token]);
        }

        $lead = $portalToken->lead;

        $validated = $request->validate([
            'estimated_total_debt' => ['nullable', 'numeric', 'min:0'],
            'creditors' => ['nullable', 'array'],
            'creditors.*.creditor_name' => ['nullable', 'string', 'max:255'],
            'creditors.*.balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lead->estimated_total_debt = array_key_exists('estimated_total_debt', $validated)
            ? $validated['estimated_total_debt']
            : $lead->estimated_total_debt;
        $lead->save();

        LeadPortalDebt::query()
            ->where('lead_id', $lead->id)
            ->where('source', 'portal')
            ->delete();

        foreach ((array) ($validated['creditors'] ?? []) as $row) {
            $name = $this->nullableString($row['creditor_name'] ?? null);
            $balance = $row['balance'] ?? null;
            $balance = $balance === '' ? null : $balance;

            if ($name === null && $balance === null) {
                continue;
            }

            LeadPortalDebt::create([
                'lead_id' => $lead->id,
                'creditor_name' => $name,
                'balance' => $balance,
                'source' => 'portal',
            ]);
        }

        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $progress->last_completed_step = 'debts';
        $progress->current_step = 'income';
        $progress->last_seen_at = now();
        $progress->save();

        return redirect()->route('portal.entry', ['token' => $token]);
    }

    public function saveIncome(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return redirect()->route('portal.entry', ['token' => $token]);
        }

        $validated = $request->validate([
            'employment_status' => ['nullable', 'string', 'max:255'],
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lead = $portalToken->lead;
        $lead->employment_status = $this->nullableString($validated['employment_status'] ?? null);
        $lead->monthly_income = array_key_exists('monthly_income', $validated)
            ? $validated['monthly_income']
            : $lead->monthly_income;
        $lead->save();

        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $progress->last_completed_step = 'income';
        $progress->current_step = 'costs';
        $progress->last_seen_at = now();
        $progress->save();

        return redirect()->route('portal.entry', ['token' => $token]);
    }

    public function saveCosts(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return redirect()->route('portal.entry', ['token' => $token]);
        }

        $validated = $request->validate([
            'monthly_housing_cost' => ['nullable', 'numeric', 'min:0'],
            'monthly_council_tax' => ['nullable', 'numeric', 'min:0'],
            'monthly_utilities_cost' => ['nullable', 'numeric', 'min:0'],
            'monthly_food_travel_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lead = $portalToken->lead;
        $lead->monthly_housing_cost = array_key_exists('monthly_housing_cost', $validated)
            ? $validated['monthly_housing_cost']
            : $lead->monthly_housing_cost;
        $lead->monthly_council_tax = array_key_exists('monthly_council_tax', $validated)
            ? $validated['monthly_council_tax']
            : $lead->monthly_council_tax;
        $lead->monthly_utilities_cost = array_key_exists('monthly_utilities_cost', $validated)
            ? $validated['monthly_utilities_cost']
            : $lead->monthly_utilities_cost;
        $lead->monthly_food_travel_cost = array_key_exists('monthly_food_travel_cost', $validated)
            ? $validated['monthly_food_travel_cost']
            : $lead->monthly_food_travel_cost;
        $lead->save();

        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $progress->last_completed_step = 'costs';
        $progress->current_step = 'credit_check';
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
            'lead' => $lead,
            'portalDebts' => $lead->portalDebts()
                ->where('source', 'portal')
                ->get(),
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
            && $this->normalizePostcodeForMatch($postcodeInput) === $this->normalizePostcodeForMatch($leadPostcode);

        return $dobMatches || $postcodeMatches;
    }

    private function normalizePostcodeForMatch(string $postcode): string
    {
        return strtoupper(str_replace(' ', '', trim($postcode)));
    }

    private function requiredIfMissingRule(?string $existing): array
    {
        return blank($existing)
            ? ['required', 'string', 'max:255']
            : ['nullable', 'string', 'max:255'];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePostcodeForStorage(?string $postcode): ?string
    {
        if ($postcode === null) {
            return null;
        }

        $trimmed = trim($postcode);
        if ($trimmed === '') {
            return null;
        }

        return strtoupper($trimmed);
    }

    private function verificationRateLimitKey(string $rawToken, ?string $ip): string
    {
        return 'portal-verify|'.$this->leadPortalTokenService->hashRawToken($rawToken).'|'.($ip ?? 'unknown');
    }
}
