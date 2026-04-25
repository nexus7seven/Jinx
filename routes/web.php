<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Lead;
use App\Models\Debt;
use App\Models\Creditor;
use App\Models\DebtDocument;
use App\Models\VotingPractice;
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
use App\Http\Controllers\PartnerLeadController;
use App\Http\Controllers\LeadFinancialStatementController;
use App\Http\Controllers\WebsiteLeadController;
use App\Services\FinancialStatementService;
use App\Services\VicidialDialActivityService;
use App\Http\Controllers\ClickToCallController;
use App\Http\Controllers\LocalWorkerJobsPageController;

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

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::redirect('/', '/wip');

    Route::get('/wip', [WipController::class, 'index'])->name('wip.index');
    Route::get('/wip/reengagement-poll', [WipController::class, 'pollReengagement'])->name('wip.reengagement-poll');
    Route::post('/wip/reengagement-acknowledge', [WipController::class, 'acknowledgeReengagement'])->name('wip.reengagement-acknowledge');
    Route::get('/remarketing', [RemarketingController::class, 'index'])->name('remarketing.index');
    Route::post('/remarketing/tasks', [RemarketingController::class, 'store'])->name('remarketing.tasks.store');
    Route::post('/remarketing/call', [RemarketingController::class, 'call'])->name('remarketing.call');
    Route::post('/remarketing/complete', [RemarketingController::class, 'complete'])->name('remarketing.complete');
    Route::post('/api/click-to-call', [ClickToCallController::class, 'store'])->name('api.click-to-call');
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
        $financialStatement = $financialStatementService->mergeForLead($lead);
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

    Route::patch('/lead/{lead}/case-notes', [LeadCaseController::class, 'updateCaseNotes'])->name('lead.case-notes.update');
    Route::post('/lead/{lead}/action-points', [LeadCaseController::class, 'storeActionPoint'])->name('lead.action-points.store');
    Route::delete('/lead/{lead}/action-points/{item}', [LeadCaseController::class, 'destroyActionPoint'])->name('lead.action-points.destroy');

    Route::post('/lead/{id}/debts', function ($id, Request $request, LeadChecklistService $checklistService) {
        $lead = Lead::findOrFail($id);

        $validated = $request->validate([
            'creditor_id' => ['required', 'integer', 'exists:creditors,id'],
            'balance' => ['required', 'numeric', 'min:0'],
            'source_expected' => ['required', 'in:credit_check,3wc,screenshot_pdf,live_chat,other'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $debt = Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $validated['creditor_id'],
            'balance' => $validated['balance'],
            'source_expected' => $validated['source_expected'],
            'reference' => $validated['reference'] ?? null,
        ]);

        $isComplete = $validated['source_expected'] === 'credit_check';

        $document = DebtDocument::create([
            'debt_id' => $debt->id,
            'proof_type' => $validated['source_expected'],
            'is_complete' => $isComplete,
        ]);

        $debt->load(['creditor', 'document']);

        $checklistService->syncForLead($lead);

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
                'document_complete' => (bool) $document->is_complete,
            ],
        ]);
    });

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