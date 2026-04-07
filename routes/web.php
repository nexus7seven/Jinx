<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Models\Lead;
use App\Models\Debt;
use App\Models\Creditor;
use App\Models\DebtDocument;
use App\Models\VotingPractice;
use App\Models\CreditReport;
use App\Http\Controllers\TempMailController;
use App\Http\Controllers\CreditReportController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\WipController;
use App\Services\LeadChecklistService;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/wip', [WipController::class, 'index'])->name('wip.index');
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
    ])->findOrFail($id);

    $creditors = Creditor::orderBy('name')->get();
    $practices = VotingPractice::orderBy('id')->get();
    $creditReports = CreditReport::with('files')
        ->where('lead_id', $lead->id)
        ->latest()
        ->get();

    return view('leads.show', compact('lead', 'creditors', 'practices', 'creditReports'));
});

Route::get('/leads/{id}/credit-check-helper', function ($id) {
    $lead = Lead::findOrFail($id);

    return view('leads.credit-check-helper', compact('lead'));
});

Route::post('/lead/{id}/autosave', function ($id, Request $request) {
    $lead = Lead::findOrFail($id);

    $allowedFields = [
        'first_name',
        'last_name',
        'dob',
        'phone_number',
        'email',
        'house_number',
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

    $lead->{$field} = $value;
    $lead->save();

    return response()->json([
        'success' => true,
        'message' => 'Saved',
        'field' => $field,
        'value' => $value,
    ]);
});

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
    $vicidialLeadId = $request->query('lead_id');
    $phone = $request->query('phone_number');

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
        ]);
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