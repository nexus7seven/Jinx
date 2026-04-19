<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use App\Models\Debt;
use App\Models\Lead;
use App\Models\Partner;
use App\Services\FinancialStatementService;
use App\Services\VicidialLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartnerLeadController extends Controller
{
    public function __construct(
        private VicidialLeadService $vicidialLeadService,
        private FinancialStatementService $financialStatementService
    ) {
    }

    public function create(string $token): View
    {
        $partner = Partner::query()
            ->where('token', $token)
            ->where('active', true)
            ->firstOrFail();

        return view('partner.submit-lead', [
            'partner' => $partner,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $partner = Partner::query()
            ->where('token', $token)
            ->where('active', true)
            ->firstOrFail();

        $request->merge([
            'title' => $request->filled('title') ? $request->input('title') : null,
        ]);

        $validated = $request->validate([
            'title'      => ['nullable', 'string', 'max:10', Rule::in(Lead::TITLES)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'phone'      => ['required', 'string', 'max:30'],
            'email'      => ['nullable', 'email', 'max:255'],
            'address1'   => ['nullable', 'string', 'max:255'],
            'postcode'   => ['nullable', 'string', 'max:20'],
            'notes'      => ['nullable', 'string'],
            'website'    => ['nullable', 'max:0'],
        ]);

        $normalisedPhone = preg_replace('/\D+/', '', $validated['phone']);

        $title = $validated['title'] ?? null;

        $existing = Lead::query()
            ->whereRaw("REGEXP_REPLACE(phone_number, '[^0-9]', '') = ?", [$normalisedPhone])
            ->first();

        if ($existing) {
            return redirect()->route('partner.lead.debts', [
                'token' => $partner->token,
                'lead' => $existing->id,
            ])->with('message', 'Lead already exists. You can add or review debts below.');
        }

        DB::beginTransaction();

        try {
            $lead = Lead::create([
                'title'            => $title,
                'first_name'       => $validated['first_name'],
                'last_name'        => $validated['last_name'],
                'phone_number'     => $validated['phone'],
                'email'            => $validated['email'] ?? null,
                'address_line_1'   => $validated['address1'] ?? null,
                'postcode'         => $validated['postcode'] ?? null,
                'source'           => $partner->name,
                'wip_status'       => Lead::defaultWipStatusForPartnerIntake(),
                'case_notes'       => $validated['notes'] ?? null,
                'vicidial_lead_id' => null,
            ]);

            $vicidialLeadId = $this->vicidialLeadService->createPartnerLead($partner, [
                'first_name'     => $validated['first_name'],
                'last_name'      => $validated['last_name'],
                'phone_number'   => $validated['phone'],
                'email'          => $validated['email'] ?? '',
                'address_line_1' => $validated['address1'] ?? '',
                'postcode'       => $validated['postcode'] ?? '',
                'comments'       => $validated['notes'] ?? '',
                'source_id'      => $partner->name,
            ]);

            $lead->update([
                'vicidial_lead_id' => $vicidialLeadId,
            ]);

            DB::commit();

            return redirect()->route('partner.lead.debts', [
                'token' => $partner->token,
                'lead' => $lead->id,
            ])->with('message', 'Lead created. Add debts below, then click Finish Submission.');
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return back()
                ->withErrors(['submit' => 'Lead could not be submitted.'])
                ->withInput();
        }
    }

    public function debts(string $token, Lead $lead): View
    {
        $partner = $this->resolvePartnerLead($token, $lead);

        $lead->load([
            'debts.creditor',
        ]);

        $creditors = Creditor::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $financialStatement = $this->financialStatementService->mergeForLead($lead);
        $fsClientPayload = $this->financialStatementService->clientViewPayload();

        return view('partner.lead-debts', [
            'partner' => $partner,
            'lead' => $lead,
            'creditors' => $creditors,
            'financialStatement' => $financialStatement,
            'fsClientPayload' => $fsClientPayload,
        ]);
    }

    public function updateFinancialStatement(Request $request, string $token, Lead $lead): JsonResponse
    {
        $this->resolvePartnerLead($token, $lead);

        $payload = $this->financialStatementService->persistForLead($lead, $request->all());

        return response()->json([
            'success' => true,
            'financial_statement' => $payload,
        ]);
    }

    public function storeDebt(Request $request, string $token, Lead $lead): JsonResponse
    {
        $this->resolvePartnerLead($token, $lead);

        $validated = $request->validate([
            'creditor_id' => ['required', 'exists:creditors,id'],
            'balance' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $debt = Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $validated['creditor_id'],
            'balance' => $validated['balance'],
            'source_expected' => 'other',
            'reference' => $validated['reference'] ?? null,
        ]);

        $debt->load('creditor');

        return response()->json([
            'success' => true,
            'debt' => $this->transformDebt($debt),
        ]);
    }

    public function updateDebt(Request $request, string $token, Lead $lead, Debt $debt): JsonResponse
    {
        $this->resolvePartnerLead($token, $lead);
        $this->ensureDebtBelongsToLead($lead, $debt);

        $validated = $request->validate([
            'creditor_id' => ['required', 'exists:creditors,id'],
            'balance' => ['required', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $debt->update([
            'creditor_id' => $validated['creditor_id'],
            'balance' => $validated['balance'],
            'source_expected' => 'other',
            'reference' => $validated['reference'] ?? null,
        ]);

        $debt->load('creditor');

        return response()->json([
            'success' => true,
            'debt' => $this->transformDebt($debt),
        ]);
    }

    public function deleteDebt(string $token, Lead $lead, Debt $debt): JsonResponse
    {
        $this->resolvePartnerLead($token, $lead);
        $this->ensureDebtBelongsToLead($lead, $debt);

        $debt->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    public function complete(string $token, Lead $lead): View
    {
        $partner = $this->resolvePartnerLead($token, $lead);

        return view('partner.thankyou', [
            'partner' => $partner,
            'lead' => $lead,
        ]);
    }

    public function thankyou(string $token): View
    {
        $partner = Partner::query()
            ->where('token', $token)
            ->where('active', true)
            ->firstOrFail();

        return view('partner.thankyou', [
            'partner' => $partner,
        ]);
    }

    private function resolvePartnerLead(string $token, Lead $lead): Partner
    {
        $partner = Partner::query()
            ->where('token', $token)
            ->where('active', true)
            ->firstOrFail();

        if (($lead->source ?? null) !== $partner->name) {
            abort(404);
        }

        return $partner;
    }

    private function ensureDebtBelongsToLead(Lead $lead, Debt $debt): void
    {
        if ((int) $debt->lead_id !== (int) $lead->id) {
            abort(404);
        }
    }

    private function transformDebt(Debt $debt): array
    {
        return [
            'id' => $debt->id,
            'creditor_id' => $debt->creditor_id,
            'creditor_name' => $debt->creditor?->name ?? 'Unknown Creditor',
            'balance' => (float) $debt->balance,
            'source_expected' => $debt->source_expected,
            'reference' => $debt->reference,
        ];
    }
}