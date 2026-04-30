<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\CreditCheckJobLog;
use App\Models\LeadPortalToken;
use App\Services\CreditCheckV3FlowService;
use App\Services\LeadPortalCompletionService;
use App\Services\LeadPortalProgressService;
use App\Services\LeadPortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
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

    private const CREDIT_CHECK_REQUIRED_FIELDS = [
        'title',
        'first_name',
        'last_name',
        'phone_number',
        'postcode',
        'house_number',
    ];
    private const PORTAL_RUNNING_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly CreditCheckV3FlowService $creditCheckV3FlowService,
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
            'title' => array_merge($this->requiredIfMissingRule($lead->title), ['string', Rule::in($this->portalTitleOptions())]),
            'first_name' => $this->requiredIfMissingRule($lead->first_name),
            'last_name' => $this->requiredIfMissingRule($lead->last_name),
            'dob' => ['nullable', 'date'],
            'email' => ['nullable', 'email'],
            'phone' => array_merge($this->requiredIfMissingRule($lead->phone_number), ['string', 'max:50']),
            'postcode' => array_merge($this->requiredIfMissingRule($lead->postcode), ['string', 'max:20']),
            'house_number' => array_merge($this->requiredIfMissingRule($lead->house_number), ['string', 'max:255']),
        ]);

        $lead->title = $this->nullableString($validated['title'] ?? null);
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
        // Legacy route kept for compatibility; delegate to real V3 start path.
        return $this->startCreditCheckFlow($request, $token);
    }

    public function startCreditCheckFlow(Request $request, string $token): JsonResponse|RedirectResponse
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return $this->portalStartCheckResponse($request, $token, [
                'ok' => false,
                'message' => 'This link is no longer active.',
                'running' => false,
            ], 410);
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return $this->portalStartCheckResponse($request, $token, [
                'ok' => false,
                'message' => 'Verification required.',
                'running' => false,
            ], 403);
        }

        $lead = $portalToken->lead;
        $routeName = $request->route()?->getName();
        $missingRequired = $this->missingCreditCheckRequiredFields($lead);

        if ($missingRequired !== []) {
            Log::info('Portal credit-check start blocked by missing details', [
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $lead->vicidial_lead_id,
                'missing_fields' => $missingRequired,
            ]);

            $progress = $this->leadPortalProgressService->ensureForLead($lead);
            $progress->current_step = 'details';
            $progress->last_seen_at = now();
            $progress->save();

            return $this->portalStartCheckResponse(
                $request,
                $token,
                [
                    'ok' => false,
                    'message' => 'We need a few details before we can start the check.',
                    'running' => false,
                ],
                422,
                'We need a few details before we can start the check.'
            );
        }

        Log::info('Portal credit-check start attempt', [
            'lead_id' => $lead->id,
            'vicidial_lead_id' => $lead->vicidial_lead_id,
            'route' => $routeName,
            'method' => $request->method(),
        ]);

        $latestLog = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->latest('id')
            ->first();

        if ($latestLog && $latestLog->isActive()) {
            $progress = $this->leadPortalProgressService->ensureForLead($lead);
            $progress->last_completed_step = 'credit_check';
            $progress->current_step = 'credit_check_running';
            $progress->last_seen_at = now();
            $progress->save();

            Log::info('Portal credit-check start decision', [
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $lead->vicidial_lead_id,
                'latest_job_id' => $latestLog->id,
                'latest_status' => $latestLog->status,
                'decision' => 'active_block',
            ]);

            return $this->portalStartCheckResponse($request, $token, [
                'ok' => true,
                'message' => 'Credit check already running.',
                'running' => true,
            ], 200);
        }

        if (
            $latestLog
            && $latestLog->status === CreditCheckJobLog::STATUS_SUCCESS
            && $lead->portal_credit_check_completed_at !== null
        ) {
            $progress = $this->leadPortalProgressService->ensureForLead($lead);
            $progress->last_completed_step = 'credit_check';
            $progress->current_step = 'review';
            $progress->last_seen_at = now();
            $progress->save();

            Log::info('Portal credit-check start decision', [
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $lead->vicidial_lead_id,
                'latest_job_id' => $latestLog->id,
                'latest_status' => $latestLog->status,
                'decision' => 'completed_block',
            ]);

            return $this->portalStartCheckResponse($request, $token, [
                'ok' => true,
                'message' => 'Credit check already completed.',
                'running' => false,
            ], 200);
        }

        if ($latestLog) {
            Log::info('Portal credit-check start decision', [
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $lead->vicidial_lead_id,
                'latest_job_id' => $latestLog->id,
                'latest_status' => $latestLog->status,
                'decision' => in_array($latestLog->status, [
                    CreditCheckJobLog::STATUS_FAILED,
                    CreditCheckJobLog::STATUS_TIMEOUT,
                    CreditCheckJobLog::STATUS_CANCELLED,
                ], true) ? 'retry_allowed' : 'fresh_start',
            ]);
        } else {
            Log::info('Portal credit-check start decision', [
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $lead->vicidial_lead_id,
                'latest_job_id' => null,
                'latest_status' => null,
                'decision' => 'fresh_start',
            ]);
        }

        $beforeLogId = (int) (CreditCheckJobLog::query()->max('id') ?? 0);
        $result = $this->creditCheckV3FlowService->startForLead($lead);
        $afterLogId = (int) (CreditCheckJobLog::query()->max('id') ?? 0);
        $jobLogCreated = $afterLogId > $beforeLogId;

        $latestLeadLog = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->latest('id')
            ->first();
        $payloadOk = (bool) ($result['payload']['ok'] ?? false);
        $externalJobPresent = $latestLeadLog && ! blank($latestLeadLog->external_job_id);
        $latestLogActive = $latestLeadLog && $latestLeadLog->isActive();
        $serviceHttp = (int) ($result['http'] ?? 500);
        $startSucceeded = $serviceHttp >= 200
            && $serviceHttp < 300
            && $payloadOk
            && $externalJobPresent
            && $latestLogActive;

        Log::info('Portal credit-check start result', [
            'lead_id' => $lead->id,
            'vicidial_lead_id' => $lead->vicidial_lead_id,
            'route' => $routeName,
            'method' => $request->method(),
            'service_http' => $serviceHttp,
            'payload_ok' => $payloadOk,
            'latest_log_id' => $latestLeadLog?->id,
            'external_job_id_present' => $externalJobPresent,
            'job_log_created' => $jobLogCreated,
            'decision' => $startSucceeded
                ? 'started'
                : ($serviceHttp >= 500
                    ? 'listener_unavailable'
                    : ($externalJobPresent ? 'start_failed_no_job' : 'start_failed_no_external_id')),
        ]);

        if ($startSucceeded) {
            $lead->portal_credit_check_started_at = $lead->portal_credit_check_started_at ?? now();
            $lead->portal_credit_check_last_run_at = now();
            $lead->save();

            $progress = $this->leadPortalProgressService->ensureForLead($lead);
            $progress->last_completed_step = 'credit_check';
            $progress->current_step = 'credit_check_running';
            $progress->last_seen_at = now();
            $progress->save();

            return $this->portalStartCheckResponse($request, $token, [
                'ok' => true,
                'message' => (string) ($result['payload']['message'] ?? ''),
                'running' => true,
            ], 200);
        }

        if ($latestLeadLog && ! $latestLeadLog->isTerminal()) {
            $latestLeadLog->status = CreditCheckJobLog::STATUS_FAILED;
            $latestLeadLog->friendly_status = $latestLeadLog->friendly_status ?: 'Failed';
            $latestLeadLog->error_message = 'We couldn’t start the check just now. Please try again.';
            $latestLeadLog->ended_at = $latestLeadLog->ended_at ?? now();
            $latestLeadLog->save();
        }

        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $progress->current_step = 'credit_check_failed';
        $progress->last_seen_at = now();
        $progress->save();

        return $this->portalStartCheckResponse(
            $request,
            $token,
            [
                'ok' => false,
                'status' => 'failed',
                'message' => 'We couldn’t start the check just now. Please try again.',
                'running' => false,
            ],
            409,
            'We couldn’t start the check just now. Please try again.'
        );
    }

    public function pollCreditCheckFlow(Request $request, string $token): JsonResponse
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return response()->json([
                'ok' => false,
                'message' => 'This link is no longer active.',
            ], 410);
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return response()->json([
                'ok' => false,
                'message' => 'Verification required.',
            ], 403);
        }

        $lead = $portalToken->lead;
        $result = $this->creditCheckV3FlowService->panelPollForLead($lead);
        $payload = $result['payload'];
        $activeLog = $result['log'];
        $bundle = $result['bundle'];
        $questionsRequired = ! empty($payload['security_questions'] ?? []);

        $progress = $this->leadPortalProgressService->ensureForLead($lead);
        $timeoutSeconds = (int) config('services.credit_check_v3_listener.portal_running_timeout_seconds', self::PORTAL_RUNNING_TIMEOUT_SECONDS);

        if ($activeLog && $activeLog->isActive()) {
            $listenerUnavailable = in_array((string) ($payload['listener_status'] ?? ''), ['Listener offline', 'Listener unavailable'], true);
            $bundleInvalid = ! is_array($bundle);
            $timedOut = (int) ($payload['elapsed_seconds'] ?? 0) > $timeoutSeconds;

            if ($listenerUnavailable || $bundleInvalid || $timedOut) {
                $activeLog->status = $timedOut ? CreditCheckJobLog::STATUS_TIMEOUT : CreditCheckJobLog::STATUS_FAILED;
                $activeLog->friendly_status = $timedOut ? 'Credit check timed out' : 'Failed';
                $activeLog->error_message = 'Something went wrong while checking your information. You can try again now.';
                $activeLog->ended_at = $activeLog->ended_at ?? now();
                $activeLog->save();

                $progress->current_step = 'credit_check_failed';
                $progress->last_seen_at = now();
                $progress->save();

                return response()->json([
                    'ok' => false,
                    'status' => 'failed',
                    'message' => 'Something went wrong while checking your information. You can try again now.',
                    'running' => false,
                    'redirect_url' => route('portal.entry', ['token' => $token]),
                ]);
            }
        }

        if (($payload['job_status'] ?? null) === CreditCheckJobLog::STATUS_FAILED) {
            $progress->current_step = 'credit_check_failed';
            $progress->last_seen_at = now();
            $progress->save();

            return response()->json([
                'ok' => false,
                'status' => 'failed',
                'message' => 'Something went wrong while checking your information. You can try again now.',
                'running' => false,
                'redirect_url' => route('portal.entry', ['token' => $token]),
            ]);
        }

        if ($activeLog && is_array($bundle) && $this->creditCheckV3FlowService->shouldAttemptImportFromBundle($activeLog, $bundle)) {
            $importResult = $this->creditCheckV3FlowService->importReportDataForLead($lead, (string) $activeLog->external_job_id);
            if (($importResult['payload']['ok'] ?? false) === true) {
                $lead->portal_credit_check_completed_at = $lead->portal_credit_check_completed_at ?? now();
                $lead->save();

                $progress->last_completed_step = 'credit_check';
                $progress->current_step = 'review';
                $progress->last_seen_at = now();
                $progress->save();

                return response()->json([
                    'ok' => true,
                    'status' => 'complete',
                    'next_step' => 'review',
                    'redirect_url' => route('portal.entry', ['token' => $token]),
                ]);
            }

            $progress->current_step = 'credit_check_failed';
            $progress->last_seen_at = now();
            $progress->save();

            return response()->json([
                'ok' => false,
                'status' => 'failed',
                'message' => 'Something went wrong while checking your information. You can try again now.',
                'running' => false,
                'redirect_url' => route('portal.entry', ['token' => $token]),
            ], $importResult['http'] >= 400 ? $importResult['http'] : 409);
        }

        if (($payload['job_status'] ?? null) === 'success') {
            $progress->last_completed_step = 'credit_check';
            $progress->current_step = 'review';
            $progress->last_seen_at = now();
            $progress->save();
        } elseif ($questionsRequired) {
            $progress->current_step = 'credit_check_questions';
            $progress->last_seen_at = now();
            $progress->save();
            $request->session()->put($this->creditCheckQuestionsSessionKey($portalToken), $payload['security_questions'] ?? []);
        } elseif (($payload['running'] ?? false) === true) {
            $progress->current_step = 'credit_check_running';
            $progress->last_seen_at = now();
            $progress->save();
        }

        return response()->json([
            'ok' => true,
            'status' => $questionsRequired ? 'questions' : (($payload['running'] ?? false) ? 'running' : 'idle'),
            'running' => (bool) ($payload['running'] ?? false),
            'job_status' => $payload['job_status'] ?? null,
            'friendly_status' => $payload['friendly_status'] ?? null,
            'listener_status' => $payload['listener_status'] ?? null,
            'started_at' => $payload['started_at'] ?? null,
            'elapsed_seconds' => $payload['elapsed_seconds'] ?? null,
            'questions_required' => $questionsRequired,
            'security_questions' => $questionsRequired ? $payload['security_questions'] : [],
        ]);
    }

    public function submitCreditCheckAnswers(Request $request, string $token): JsonResponse
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($token, $request->ip());

        if (! $portalToken) {
            return response()->json([
                'ok' => false,
                'message' => 'This link is no longer active.',
            ], 410);
        }

        if (! $request->session()->get($this->verificationSessionKey($portalToken), false)) {
            return response()->json([
                'ok' => false,
                'message' => 'Verification required.',
            ], 403);
        }

        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.id' => ['nullable', 'string', 'max:255'],
            'answers.*.index' => ['nullable', 'integer', 'min:0'],
            'answers.*.value' => ['required'],
            'answers.*.label' => ['nullable', 'string', 'max:255'],
        ]);

        $lead = $portalToken->lead;
        Log::info('Portal credit-check v3 answers payload sample', [
            'lead_id' => $lead->id,
            'vicidial_lead_id' => $lead->vicidial_lead_id,
            'answers' => $validated['answers'],
        ]);

        $activeLog = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->whereIn('status', [CreditCheckJobLog::STATUS_PENDING, CreditCheckJobLog::STATUS_RUNNING])
            ->whereNotNull('external_job_id')
            ->orderByDesc('id')
            ->first();

        if (! $activeLog || blank($activeLog->external_job_id)) {
            return response()->json([
                'ok' => false,
                'message' => 'No active credit check is available right now.',
            ], 409);
        }

        $result = $this->creditCheckV3FlowService->submitAnswersForJob((string) $activeLog->external_job_id, $validated['answers']);

        if (($result['payload']['ok'] ?? false) === true) {
            $progress = $this->leadPortalProgressService->ensureForLead($lead);
            $progress->current_step = 'credit_check_running';
            $progress->last_seen_at = now();
            $progress->save();
        }

        return response()->json([
            'ok' => (bool) ($result['payload']['ok'] ?? false),
            'message' => (string) ($result['payload']['message'] ?? ''),
            'running' => true,
        ], $result['http']);
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
            'creditCheckQuestions' => $this->resolveCreditCheckQuestions($lead, $rawToken),
            'creditCheckDebts' => $lead->debts()
                ->with('creditor')
                ->where('source_expected', 'credit_check')
                ->orderByDesc('id')
                ->get(),
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
            'titleOptions' => $this->portalTitleOptions(),
            'rawToken' => $rawToken,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function portalStartCheckResponse(
        Request $request,
        string $token,
        array $payload,
        int $status,
        ?string $flashError = null
    ): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->isXmlHttpRequest()) {
            return response()->json($payload, $status);
        }

        $redirect = redirect()->route('portal.entry', ['token' => $token]);
        if ($flashError) {
            return $redirect->withErrors(['credit_check' => $flashError]);
        }

        return $redirect;
    }

    /**
     * @return array<int, string>
     */
    private function portalTitleOptions(): array
    {
        return array_values(array_unique(array_merge(Lead::TITLES, ['Other'])));
    }

    /**
     * @return array<int, string>
     */
    private function missingCreditCheckRequiredFields(Lead $lead): array
    {
        $missing = [];
        foreach (self::CREDIT_CHECK_REQUIRED_FIELDS as $field) {
            $value = $lead->{$field};
            if ($value === null || trim((string) $value) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function expiredResponse()
    {
        return response()->view('portal.expired', [], 410);
    }

    private function verificationSessionKey(LeadPortalToken $portalToken): string
    {
        return 'portal_verified_'.$portalToken->lead_id.'_'.$portalToken->id;
    }

    private function creditCheckQuestionsSessionKey(LeadPortalToken $portalToken): string
    {
        return 'portal_credit_check_questions_'.$portalToken->lead_id.'_'.$portalToken->id;
    }

    /**
     * @return array<int, mixed>
     */
    private function resolveCreditCheckQuestions(Lead $lead, string $rawToken): array
    {
        $portalToken = $this->leadPortalTokenService->resolveRawToken($rawToken, request()->ip());
        if (! $portalToken) {
            return [];
        }

        $sessionQuestions = request()->session()->get($this->creditCheckQuestionsSessionKey($portalToken), []);
        if (is_array($sessionQuestions) && count($sessionQuestions) > 0) {
            return $sessionQuestions;
        }

        $activeLog = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->whereIn('status', [CreditCheckJobLog::STATUS_PENDING, CreditCheckJobLog::STATUS_RUNNING])
            ->whereNotNull('external_job_id')
            ->orderByDesc('id')
            ->first();
        if (! $activeLog || blank($activeLog->external_job_id)) {
            return [];
        }

        $status = $this->creditCheckV3FlowService->statusForJob((string) $activeLog->external_job_id);
        $qRoot = (array) (($status['payload']['questions'] ?? []) ?: []);
        $qPayload = $qRoot['payload'] ?? null;
        $questions = is_array($qPayload) && is_array($qPayload['questions'] ?? null)
            ? $qPayload['questions']
            : (is_array($qRoot['questions'] ?? null) ? $qRoot['questions'] : []);

        return is_array($questions) ? $questions : [];
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
