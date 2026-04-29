<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadPortalToken;
use App\Services\LeadPortalCompletionService;
use App\Services\LeadPortalProgressService;
use App\Services\LeadPortalTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class LeadPortalController extends Controller
{
    private const VERIFY_MAX_ATTEMPTS = 5;
    private const VERIFY_DECAY_SECONDS = 600;
    private const VERIFY_GENERIC_ERROR = 'That doesn’t look quite right. Please check and try again.';
    private const VERIFY_RATE_LIMIT_ERROR = 'Too many attempts. Please wait a little while and try again.';
    private const EMPLOYMENT_STATUS_OPTIONS = [
        'Employed full-time',
        'Employed part-time',
        'Self-employed',
        'Benefits',
        'Pension',
        'Student',
        'Unemployed',
        'Homemaker / caring responsibilities',
        'Other',
    ];

    public function __construct(
        private readonly LeadPortalTokenService $leadPortalTokenService,
        private readonly LeadPortalProgressService $leadPortalProgressService,
        private readonly LeadPortalCompletionService $leadPortalCompletionService
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
        ]);

        $lead->first_name = $this->nullableString($validated['first_name'] ?? null);
        $lead->last_name = $this->nullableString($validated['last_name'] ?? null);
        $lead->dob = $this->nullableString($validated['dob'] ?? null);
        $lead->email = $this->nullableString($validated['email'] ?? null);
        $lead->phone_number = $this->nullableString($validated['phone'] ?? null);
        $lead->postcode = $this->normalizePostcodeForStorage($validated['postcode'] ?? null);
        $lead->house_number = $this->nullableString($validated['house_number'] ?? null);
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

        $request->merge([
            'estimated_total_debt' => $this->normalizeMoneyInput($request->input('estimated_total_debt')),
        ]);

        $lead = $portalToken->lead;

        $validated = $request->validate([
            'estimated_total_debt' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lead->estimated_total_debt = array_key_exists('estimated_total_debt', $validated)
            ? $validated['estimated_total_debt']
            : $lead->estimated_total_debt;
        $lead->save();

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

        $request->merge([
            'monthly_income' => $this->normalizeMoneyInput($request->input('monthly_income')),
        ]);

        $validated = $request->validate([
            'employment_status' => ['nullable', 'string', Rule::in(self::EMPLOYMENT_STATUS_OPTIONS)],
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

        $request->merge([
            'monthly_housing_cost' => $this->normalizeMoneyInput($request->input('monthly_housing_cost')),
            'monthly_council_tax' => $this->normalizeMoneyInput($request->input('monthly_council_tax')),
            'monthly_utilities_cost' => $this->normalizeMoneyInput($request->input('monthly_utilities_cost')),
            'monthly_food_travel_cost' => $this->normalizeMoneyInput($request->input('monthly_food_travel_cost')),
        ]);

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

    public function startCreditCheck(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return redirect()->route('portal.entry', ['token' => $token]);
        }

        $lead = $portalToken->lead;

        // This is currently a placeholder. Real credit-check job dispatch will be wired in a later step.
        if ($lead->portal_credit_check_last_run_at === null) {
            $lead->portal_credit_check_started_at = now();
            $lead->portal_credit_check_last_run_at = now();
            $lead->save();
        }

        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $progress->last_completed_step = 'credit_check';
        $progress->current_step = 'review';
        $progress->last_seen_at = now();
        $progress->save();

        return redirect()->route('portal.entry', ['token' => $token]);
    }

    public function finishReview(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return redirect()->route('portal.entry', ['token' => $token]);
        }

        $progress = $this->leadPortalProgressService->ensureForLead($portalToken->lead);
        $progress->last_completed_step = 'review';
        $progress->current_step = 'complete_pending';
        $progress->last_seen_at = now();
        $progress->save();

        return redirect()->route('portal.entry', ['token' => $token]);
    }

    public function complete(Request $request, string $token)
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->expiredResponse();
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return redirect()->route('portal.entry', ['token' => $token]);
        }

        $snapshot = $this->leadPortalCompletionService->complete($portalToken);

        return view('portal.completed', [
            'emailedAt' => $snapshot->emailed_at,
            'hadEmailAddress' => filled($portalToken->lead->email),
        ]);
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
            'reviewMoney' => [
                'estimated_total_debt' => $this->formatMoney($lead->estimated_total_debt),
                'monthly_income' => $this->formatMoney($lead->monthly_income),
                'monthly_housing_cost' => $this->formatMoney($lead->monthly_housing_cost),
                'monthly_council_tax' => $this->formatMoney($lead->monthly_council_tax),
                'monthly_utilities_cost' => $this->formatMoney($lead->monthly_utilities_cost),
                'monthly_food_travel_cost' => $this->formatMoney($lead->monthly_food_travel_cost),
            ],
            'maskedName' => $this->maskName($lead->first_name, $lead->last_name),
            'maskedDob' => $this->maskDob($lead->dob),
            'maskedPostcode' => $this->maskPostcode($lead->postcode),
            'maskedAddress' => $this->maskAddress($lead->house_number, $lead->address_line_1),
            'employmentStatusOptions' => self::EMPLOYMENT_STATUS_OPTIONS,
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

    private function normalizeMoneyInput(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $normalized = str_replace([',', ' '], '', trim((string) $value));
        $normalized = str_replace(['£', '$'], '', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    private function verificationRateLimitKey(string $rawToken, ?string $ip): string
    {
        return 'portal-verify|'.$this->leadPortalTokenService->hashRawToken($rawToken).'|'.($ip ?? 'unknown');
    }

    private function maskName(?string $firstName, ?string $lastName): string
    {
        $mask = function (?string $part): string {
            $part = trim((string) $part);
            if ($part === '') {
                return '';
            }
            if (strlen($part) === 1) {
                return '*';
            }

            return substr($part, 0, 1).str_repeat('*', max(strlen($part) - 1, 1));
        };

        $masked = trim($mask($firstName).' '.$mask($lastName));

        return $masked !== '' ? $masked : 'Not provided';
    }

    private function maskDob(?string $dob): string
    {
        $dob = trim((string) $dob);
        if ($dob === '') {
            return 'Not provided';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) === 1) {
            return '**/**/'.substr($dob, 0, 4);
        }

        return 'Provided';
    }

    private function maskPostcode(?string $postcode): string
    {
        $postcode = trim((string) $postcode);
        if ($postcode === '') {
            return 'Not provided';
        }

        $normalized = strtoupper(str_replace(' ', '', $postcode));
        if (strlen($normalized) <= 3) {
            return str_repeat('*', strlen($normalized));
        }

        return substr($normalized, 0, 3).str_repeat('*', max(strlen($normalized) - 3, 1));
    }

    private function maskAddress(?string $houseNumber, ?string $addressLine1): string
    {
        $houseNumber = trim((string) $houseNumber);
        $addressLine1 = trim((string) $addressLine1);

        if ($houseNumber === '' && $addressLine1 === '') {
            return 'Not provided';
        }

        return ($houseNumber !== '' ? $houseNumber.' ' : '').'********';
    }

    private function formatMoney(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return 'Not provided';
        }

        return '£'.number_format((float) $amount, 2);
    }
}
