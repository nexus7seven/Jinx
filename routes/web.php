<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Lead;
use App\Models\Debt;
use App\Models\Creditor;
use App\Models\VotingPractice;
use App\Models\LeadPortalShortLink;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CreditCheckWorkerController;
use App\Http\Controllers\CreditCheckV2Controller;
use App\Http\Controllers\CreditCheckV3Controller;
use App\Http\Controllers\TempMailController;
use App\Http\Controllers\CreditReportController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\WipController;
use App\Http\Controllers\RemarketingController;
use App\Http\Controllers\LeadCaseController;
use App\Services\LeadChecklistService;
use App\Services\LeadPortalLinkService;
use App\Services\LeadPortalTokenService;
use App\Http\Controllers\PartnerLeadController;
use App\Http\Controllers\PartnerPortalAuthController;
use App\Http\Controllers\LeadFinancialStatementController;
use App\Http\Controllers\WebsiteLeadController;
use App\Http\Controllers\Portal\LeadPortalEmailClickController;
use App\Http\Controllers\Portal\LeadPortalController;
use App\Services\FinancialStatementService;
use App\Services\VicidialDialActivityService;
use App\Http\Controllers\ClickToCallController;
use App\Http\Controllers\LocalWorkerJobsPageController;
use App\Http\Controllers\LeadSearchController;
use App\Http\Controllers\Webhooks\TwilioInboundSmsWebhookController;
use App\Http\Controllers\Webhooks\SendGridInboundEmailWebhookController;
use App\Services\LeadDebtService;
use App\Http\Controllers\DataDiallingDashboardController;

Route::post('/webhooks/twilio/inbound-sms', TwilioInboundSmsWebhookController::class);
Route::post('/webhooks/sendgrid/inbound-email', SendGridInboundEmailWebhookController::class);

Route::get('/p/{shortCode}', function (string $shortCode, Request $request, LeadPortalTokenService $leadPortalTokenService) {
    $shortLink = LeadPortalShortLink::query()
        ->where('short_code', $shortCode)
        ->with('portalToken')
        ->first();

    if (! $shortLink || ! $shortLink->portalToken) {
        abort(404);
    }

    $portalToken = $shortLink->portalToken;

    if (! $leadPortalTokenService->isTokenCurrentlyUsable($portalToken)) {
        abort(410);
    }

    $rawToken = $leadPortalTokenService->getCachedRawToken($portalToken);

    if (! is_string($rawToken) || $rawToken === '') {
        abort(410);
    }

    $shortLink->increment('click_count');
    $shortLink->last_clicked_at = now();
    $shortLink->save();

    return redirect()->route('portal.entry', ['token' => $rawToken]);
})->name('portal.short-link');

Route::get('/portal/{token}', [LeadPortalController::class, 'show'])
    ->name('portal.entry');
Route::post('/portal/{token}/verify', [LeadPortalController::class, 'verify'])
    ->name('portal.verify');
Route::post('/portal/{token}/welcome', [LeadPortalController::class, 'completeWelcome'])
    ->name('portal.welcome.complete');
Route::post('/portal/{token}/details', [LeadPortalController::class, 'saveDetails'])
    ->name('portal.details.save');
Route::post('/portal/{token}/debts', [LeadPortalController::class, 'saveDebts'])
    ->name('portal.debts.save');
Route::post('/portal/{token}/income', [LeadPortalController::class, 'saveIncome'])
    ->name('portal.income.save');
Route::post('/portal/{token}/costs', [LeadPortalController::class, 'saveCosts'])
    ->name('portal.costs.save');
Route::post('/portal/{token}/credit-check/start', [LeadPortalController::class, 'startCreditCheck'])
    ->name('portal.credit-check.start');
Route::post('/portal/{token}/credit-check/v3/start', [LeadPortalController::class, 'startCreditCheckFlow'])
    ->name('portal.credit-check.v3.start');
Route::get('/portal/{token}/credit-check/v3/poll', [LeadPortalController::class, 'pollCreditCheckFlow'])
    ->name('portal.credit-check.poll');
Route::post('/portal/{token}/credit-check/v3/answers', [LeadPortalController::class, 'submitCreditCheckAnswers'])
    ->name('portal.credit-check.answers');
Route::post('/portal/{token}/credit-report-debts/continue', [LeadPortalController::class, 'continueCreditReportDebts'])
    ->name('portal.credit-report-debts.continue');
Route::post('/portal/{token}/missing-debts', [LeadPortalController::class, 'saveMissingDebts'])
    ->name('portal.missing-debts.save');
Route::post('/portal/{token}/iva-results/continue', [LeadPortalController::class, 'continueIvaResults'])
    ->name('portal.iva-results.continue');
Route::post('/portal/{token}/review/finish', [LeadPortalController::class, 'finishReview'])
    ->name('portal.review.finish');
Route::post('/portal/{token}/complete', [LeadPortalController::class, 'complete'])
    ->name('portal.complete');
Route::get('/portal-summary/click/{snapshot}/{type}', [LeadPortalEmailClickController::class, 'redirect'])
    ->name('portal.summary.click');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

/*
|--------------------------------------------------------------------------
| Public Partner Routes
|--------------------------------------------------------------------------
*/

Route::get('/partner/{token}/submit-lead', [PartnerLeadController::class, 'create'])
    ->name('partner.lead.create');

Route::post('/partner/{token}/submit-lead', [PartnerLeadController::class, 'store'])
    ->name('partner.lead.store');

Route::get('/partner/{token}/lead/{lead}/debts', [PartnerLeadController::class, 'debts'])
    ->name('partner.lead.debts');

Route::post('/partner/{token}/lead/{lead}/debts', [PartnerLeadController::class, 'storeDebt'])
    ->name('partner.lead.debts.store');

Route::put('/partner/{token}/lead/{lead}/debts/{debt}', [PartnerLeadController::class, 'updateDebt'])
    ->name('partner.lead.debts.update');

Route::delete('/partner/{token}/lead/{lead}/debts/{debt}', [PartnerLeadController::class, 'deleteDebt'])
    ->name('partner.lead.debts.delete');

Route::get('/partner/{token}/lead/{lead}/complete', [PartnerLeadController::class, 'complete'])
    ->name('partner.lead.complete');

Route::get('/partner/{token}/thank-you', [PartnerLeadController::class, 'thankyou'])
    ->name('partner.lead.thankyou');

Route::patch('/partner/{token}/lead/{lead}/financial-statement', [PartnerLeadController::class, 'updateFinancialStatement'])
    ->name('partner.lead.financial-statement.update');

Route::options('/partner-lead-submit', function () {
    return response()->noContent();
})->name('partner.lead.submit.options');

Route::post('/partner-lead-submit', [WebsiteLeadController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('partner.lead.submit');

Route::middleware('guest')->group(function () {
    Route::get('/partner-portal/login', [PartnerPortalAuthController::class, 'showLogin'])->name('partner-portal.login');
    Route::post('/partner-portal/login', [PartnerPortalAuthController::class, 'login'])->name('partner-portal.login.attempt');
});

Route::middleware('partner.portal.auth')->group(function () {
    Route::post('/partner-portal/logout', [PartnerPortalAuthController::class, 'logout'])->name('partner-portal.logout');
    Route::get('/partner-portal/leads', [PartnerPortalAuthController::class, 'leads'])->name('partner-portal.leads');
    Route::get('/partner-portal/leads/export', [PartnerPortalAuthController::class, 'export'])->name('partner-portal.leads.export');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::redirect('/', '/wip');

    Route::get('/wip', [WipController::class, 'index'])->name('wip.index');
    Route::get('/wip/reengagement-poll', [WipController::class, 'pollReengagement'])->name('wip.reengagement-poll');
    Route::post('/wip/reengagement-acknowledge', [WipController::class, 'acknowledgeReengagement'])->name('wip.reengagement-acknowledge');
    Route::post('/remarketing/response/{id}/handle', [WipController::class, 'handleResponseEvent'])->name('remarketing.response.handle');
    Route::get('/remarketing', [RemarketingController::class, 'index'])->name('remarketing.index');
    Route::get('/data-dialling-dashboard', [DataDiallingDashboardController::class, 'index'])->name('reports.data-dialling-dashboard');
    Route::post('/remarketing/tasks', [RemarketingController::class, 'store'])->name('remarketing.tasks.store');
    Route::post('/remarketing/call', [RemarketingController::class, 'call'])->name('remarketing.call');
    Route::post('/remarketing/complete', [RemarketingController::class, 'complete'])->name('remarketing.complete');
    Route::post('/remarketing/linear/{leadId}/complete-manual-step', [RemarketingController::class, 'completeLinearManualStep'])->name('remarketing.linear.complete-manual-step');
    Route::post('/api/click-to-call', [ClickToCallController::class, 'store'])->name('api.click-to-call');
    Route::get('/lead-search', LeadSearchController::class)->name('lead.search');
    Route::patch('/lead/{lead}/wip-status', [WipController::class, 'updateStatus'])->name('lead.wip-status');

    Route::get('/lead/{lead}/checklist', [WipController::class, 'checklist'])->name('lead.checklist.index');
    Route::post('/lead/{lead}/checklist', [WipController::class, 'storeChecklistItem'])->name('lead.checklist.store');
    Route::patch('/lead/{lead}/checklist/{item}', [WipController::class, 'updateChecklistItem'])->name('lead.checklist.update');
    Route::patch('/lead/{lead}/checklist/{item}/toggle', [WipController::class, 'toggleChecklistItem'])->name('lead.checklist.toggle');
    Route::delete('/lead/{lead}/checklist/{item}', [WipController::class, 'destroyChecklistItem'])->name('lead.checklist.destroy');

    Route::get('/lead/{id}', function ($id) {
        $lead = Lead::with([
            'debts.creditor',
            'debts.document',
            'actionPoints',
        ])->findOrFail($id);

        $creditors = Creditor::orderBy('name')->get();
        $practices = VotingPractice::orderBy('id')->get();
        $financialStatementService = app(FinancialStatementService::class);
        $financialStatement = $financialStatementService->uiStateForLead($lead);
        $fsClientPayload = $financialStatementService->clientViewPayload();

        $lastDialledAt = app(VicidialDialActivityService::class)->lastDialledAtForLead($lead);

        return view('leads.show', compact('lead', 'creditors', 'practices', 'financialStatement', 'fsClientPayload', 'lastDialledAt'));
    });

    Route::get('/leads/{id}/credit-check-helper', function ($id) {
        $lead = Lead::findOrFail($id);

        return view('leads.credit-check-helper', compact('lead'));
    });

    Route::get('/leads/{id}/credit-check-v2', function ($id) {
        $lead = Lead::findOrFail($id);

        return view('leads.credit-check-v2', compact('lead'));
    });

    Route::post('/leads/{lead}/credit-check-v2/run', [CreditCheckV2Controller::class, 'run'])
        ->name('leads.credit-check-v2.run');

    Route::post('/leads/{lead}/credit-check-v2/sessions/{sessionId}/answers', [CreditCheckV2Controller::class, 'submitAnswers'])
        ->name('leads.credit-check-v2.answers');

    Route::get('/leads/{lead}/credit-check-v3', [CreditCheckV3Controller::class, 'page'])
        ->name('leads.credit-check-v3.page');
    Route::post('/leads/{lead}/credit-check-v3/run', [CreditCheckV3Controller::class, 'run'])
        ->name('leads.credit-check-v3.run');
    Route::get('/leads/credit-check-v3/jobs/{jobId}/status', [CreditCheckV3Controller::class, 'status'])
        ->name('leads.credit-check-v3.status');
    Route::post('/leads/credit-check-v3/jobs/{jobId}/answers', [CreditCheckV3Controller::class, 'submitAnswers'])
        ->name('leads.credit-check-v3.answers');
    Route::post('/leads/{lead}/credit-check-v3/jobs/{jobId}/import', [CreditCheckV3Controller::class, 'importReportData'])
        ->name('leads.credit-check-v3.import');
    Route::get('/lead/{lead}/credit-check-v3/panel-poll', [CreditCheckV3Controller::class, 'panelPoll'])
        ->name('leads.credit-check-v3.panel-poll');
    Route::get('/lead/{lead}/debts-section', [CreditCheckV3Controller::class, 'debtsSectionHtml'])
        ->name('leads.debts-section-html');

    Route::post('/credit-check-worker/start', [CreditCheckWorkerController::class, 'start'])
        ->name('credit-check-worker.start');
    Route::get('/credit-check-worker/{jobId}/status', [CreditCheckWorkerController::class, 'status'])
        ->name('credit-check-worker.status');
    Route::get('/credit-check-worker/{jobId}/questions', [CreditCheckWorkerController::class, 'questions']);
    Route::post('/credit-check-worker/{jobId}/answers', [CreditCheckWorkerController::class, 'submitAnswers']);

    Route::get('/local-worker/jobs', [LocalWorkerJobsPageController::class, 'index'])
        ->name('local-worker.jobs.index');
    Route::get('/local-worker/jobs/{job}', [LocalWorkerJobsPageController::class, 'show'])
        ->name('local-worker.jobs.show');
    Route::post('/local-worker/jobs/{job}/provide-input', [LocalWorkerJobsPageController::class, 'submitInput'])
        ->name('local-worker.jobs.provide-input');

    Route::post('/lead/{id}/autosave', function ($id, Request $request) {
        $lead = Lead::findOrFail($id);

        $allowedFields = [
            'title',
            'first_name',
            'middle_name',
            'last_name',
            'dob',
            'phone_number',
            'email',
            'house_number',
            'house_name',
            'building_number',
            'postcode',
            'address_line_1',
        ];

        $field = $request->input('field');
        $value = $request->input('value');

        if (!in_array($field, $allowedFields, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid field',
            ], 422);
        }

        if ($field === 'title') {
            $value = ($value === '' || $value === null) ? null : $value;
            Validator::make(
                ['value' => $value],
                ['value' => ['nullable', 'string', 'max:10', Rule::in(Lead::TITLES)]]
            )->validate();
        }

        $lead->{$field} = $value;
        $lead->save();

        return response()->json([
            'success' => true,
            'message' => 'Saved',
            'field' => $field,
            'value' => $value,
        ]);
    });

    Route::patch('/lead/{lead}/financial-statement', [LeadFinancialStatementController::class, 'update'])
        ->name('lead.financial-statement.update');


    Route::post('/lead/{lead}/portal-link', function (Lead $lead, Request $request, LeadPortalLinkService $leadPortalLinkService) {
        $issued = $leadPortalLinkService->generateForLead($lead, $request->ip());
        $progress = app(\App\Services\LeadPortalProgressService::class)->ensureForLead($lead);
        $progress->is_demo_mode = false;
        $progress->demo_payload = null;
        $progress->save();

        $portalToken = $issued['portal_token'];
        $portalUrl = (string) $issued['portal_url'];

        $normalizeUkWhatsAppPhone = static function (?string $phone): ?string {
            $rawPhone = trim((string) ($phone ?? ''));
            if ($rawPhone === '') {
                return null;
            }

            $digitsOnlyPhone = preg_replace('/\D+/', '', $rawPhone) ?? '';
            if ($digitsOnlyPhone === '') {
                return null;
            }

            if (str_starts_with($digitsOnlyPhone, '0044')) {
                $normalizedPhone = '44'.substr($digitsOnlyPhone, 4);
            } elseif (str_starts_with($digitsOnlyPhone, '44')) {
                $normalizedPhone = $digitsOnlyPhone;
            } elseif (str_starts_with($digitsOnlyPhone, '07')) {
                $normalizedPhone = '44'.substr($digitsOnlyPhone, 1);
            } elseif (str_starts_with($digitsOnlyPhone, '7')) {
                $normalizedPhone = '44'.$digitsOnlyPhone;
            } else {
                $normalizedPhone = $digitsOnlyPhone;
            }

            if (! str_starts_with($normalizedPhone, '44')) {
                return null;
            }

            $normalizedLength = strlen($normalizedPhone);

            return ($normalizedLength >= 12 && $normalizedLength <= 13) ? $normalizedPhone : null;
        };

        $normalizedPhone = $normalizeUkWhatsAppPhone($lead->phone_number);
        $whatsappUrl = null;

        if ($normalizedPhone !== null) {
            $message = "Here's a link to your personalised portal where you can run a free credit check and get a review on your situation: {$portalUrl}";
            $whatsappUrl = 'https://wa.me/'.$normalizedPhone.'?text='.rawurlencode($message);
        }

        return response()->json([
            'portal_url' => $portalUrl,
            'whatsapp_url' => $whatsappUrl,
            'expires_at' => optional($portalToken->expires_at)?->toIso8601String(),
        ]);
    })->name('lead.portal-link');

    Route::patch('/lead/{lead}/case-notes', [LeadCaseController::class, 'updateCaseNotes'])->name('lead.case-notes.update');
    Route::patch('/lead/{lead}/lead-feedback', [LeadCaseController::class, 'updateLeadFeedback'])->name('lead.lead-feedback.update');
    Route::post('/lead/{lead}/action-points', [LeadCaseController::class, 'storeActionPoint'])->name('lead.action-points.store');
    Route::delete('/lead/{lead}/action-points/{item}', [LeadCaseController::class, 'destroyActionPoint'])->name('lead.action-points.destroy');

    Route::post('/lead/{id}/debts', function ($id, Request $request, LeadDebtService $leadDebtService) {
        $lead = Lead::findOrFail($id);

        $validated = $request->validate([
            'creditor_id' => ['required', 'integer', 'exists:creditors,id'],
            'balance' => ['required', 'numeric', 'min:0'],
            'source_expected' => ['required', 'in:credit_check,3wc,screenshot_pdf,live_chat,other'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $debt = $leadDebtService->createForLead($lead, $validated);
        $debt->load(['creditor', 'document']);

        return response()->json([
            'success' => true,
            'debt' => [
                'id' => $debt->id,
                'creditor_name' => $debt->creditor->name,
                'creditor_id' => $debt->creditor_id,
                'balance' => number_format((float) $debt->balance, 2, '.', ''),
                'source_expected' => $debt->source_expected,
                'reference' => $debt->reference,
                'voting_house' => $debt->creditor->voting_house,
                'voting_practice1' => $debt->creditor->voting_practice1,
                'voting_practice2' => $debt->creditor->voting_practice2,
                'voting_practice3' => $debt->creditor->voting_practice3,
                'document_complete' => (bool) $debt->document?->is_complete,
            ],
        ]);
    });

    Route::post('/lead/{lead}/portal-link/demo', function (Lead $lead, Request $request, LeadPortalLinkService $leadPortalLinkService) {
        $issued = $leadPortalLinkService->generateForLead($lead, $request->ip());
        $progress = app(\App\Services\LeadPortalProgressService::class)->ensureForLead($lead);
        $progress->is_demo_mode = true;
        $progress->demo_payload = null;
        $progress->save();

        return response()->json([
            'portal_url' => (string) $issued['portal_url'],
            'expires_at' => optional($issued['portal_token']->expires_at)->toDateTimeString(),
            'demo_mode' => true,
        ]);
    })->name('lead.portal-link.demo');

    Route::get('/debt/{debt}', [DebtController::class, 'show']);
    Route::put('/debt/{debt}', [DebtController::class, 'update']);

    Route::post('/lead/{id}/credit-reports', [CreditReportController::class, 'store']);
    Route::delete('/credit-reports/{id}', [CreditReportController::class, 'destroy']);

    Route::delete('/debt/{id}', function ($id, LeadChecklistService $checklistService) {
        $debt = Debt::with('document')->findOrFail($id);
        $lead = Lead::findOrFail($debt->lead_id);

        if ($debt->document) {
            $checklistItem = \App\Models\LeadChecklistItem::where('lead_id', $lead->id)
                ->where('source_type', 'debt_document')
                ->where('source_id', $debt->document->id)
                ->first();

            if ($checklistItem) {
                $checklistItem->delete();
            }
        }

        $debt->delete();

        $checklistService->syncForLead($lead);

        return response()->json([
            'success' => true,
        ]);
    });

    Route::get('/vicidial/open', function (Request $request) {
        $request->validate([
            'source_id' => ['nullable', 'string', 'max:255'],
        ]);

        $vicidialLeadId = $request->query('lead_id');
        $phone = $request->query('phone_number');

        $rawSourceId = $request->query('source_id');
        $sourceId = is_string($rawSourceId) ? trim($rawSourceId) : null;
        if ($sourceId === '') {
            $sourceId = null;
        }

        if (!$vicidialLeadId) {
            abort(400, 'Missing lead_id');
        }

        $lead = Lead::where('vicidial_lead_id', $vicidialLeadId)->first();

        if (!$lead && $phone) {
            $lead = Lead::where('phone_number', $phone)->first();
        }

        if (!$lead) {
            $lead = Lead::create([
                'vicidial_lead_id' => $vicidialLeadId,
                'phone_number'     => $phone,
                'first_name'       => $request->query('first_name'),
                'last_name'        => $request->query('last_name'),
                'dob'              => $request->query('dob'),
                'email'            => $request->query('email'),
                'house_number'     => $request->query('house_number'),
                'postcode'         => $request->query('postal_code'),
                'address_line_1'   => $request->query('address1'),
                'source'           => $sourceId,
                'from_vicidial_webform' => true,
            ]);
        } elseif ($sourceId !== null && blank($lead->source)) {
            $lead->source = $sourceId;
            $lead->save();
        }

        return redirect('/lead/' . $lead->id);
    });

    /*
    |--------------------------------------------------------------------------
    | Temp Mail Routes
    |--------------------------------------------------------------------------
    */

    Route::prefix('/leads/{lead}/temp-mail')->group(function () {
        Route::post('/generate', [TempMailController::class, 'generate'])
            ->name('leads.temp-mail.generate');

        Route::get('/inbox', [TempMailController::class, 'inbox'])
            ->name('leads.temp-mail.inbox');

        Route::get('/latest-code', [TempMailController::class, 'latestCode'])
            ->name('leads.temp-mail.latest-code');

        Route::delete('/reset', [TempMailController::class, 'reset'])
            ->name('leads.temp-mail.reset');
    });
});
