<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use App\Models\Lead;
use App\Services\IpCreditorVotingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadIpVotingController extends Controller
{
    public function show(Lead $lead, IpCreditorVotingService $service): JsonResponse
    {
        return response()->json($service->analyseLead($lead));
    }

    public function updateIp(Request $request, Lead $lead, IpCreditorVotingService $service): JsonResponse
    {
        $validated = $request->validate([
            'iva_ip_key' => ['nullable', 'string', Rule::in(array_keys(Lead::IVA_IPS))],
        ]);

        $lead->iva_ip_key = filled($validated['iva_ip_key'] ?? null)
            ? (string) $validated['iva_ip_key']
            : null;
        $lead->save();

        return response()->json($service->analyseLead($lead->fresh()));
    }

    public function storeOverride(Request $request, Lead $lead, IpCreditorVotingService $service): JsonResponse
    {
        $validated = $request->validate([
            'creditor_id' => ['required', 'integer', 'exists:creditors,id'],
            'status_text' => ['required', 'string', Rule::in(IpCreditorVotingService::MANUAL_STATUS_OPTIONS)],
            'voting_house' => ['nullable', 'string', 'max:120'],
            'condition_text' => ['nullable', 'string', 'max:4000'],
        ]);

        if (!$lead->iva_ip_key || !array_key_exists($lead->iva_ip_key, Lead::IVA_IPS)) {
            return response()->json([
                'success' => false,
                'message' => 'Select an insolvency practitioner before adding voting criteria.',
            ], 422);
        }

        $creditorId = (int) $validated['creditor_id'];

        if (!$lead->debts()->where('creditor_id', $creditorId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'That creditor is not on this case.',
            ], 422);
        }

        $creditor = Creditor::findOrFail($creditorId);
        if (strcasecmp(trim((string) $creditor->name), 'Could Not Match') === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Match the debt to the real creditor before saving a reusable voting rule.',
            ], 422);
        }

        $statusText = trim((string) $validated['status_text']);
        $outcome = $service->interpretStatus($statusText);
        $votingHouse = filled($validated['voting_house'] ?? null)
            ? trim((string) $validated['voting_house'])
            : null;

        if ($statusText === 'Accept - via house vote' && !$votingHouse) {
            return response()->json([
                'success' => false,
                'message' => 'Enter the voting house for an Accept - via house vote rule.',
            ], 422);
        }

        DB::table('ip_creditor_voting_overrides')->updateOrInsert(
            [
                'creditor_id' => $creditorId,
                'ip_key' => $lead->iva_ip_key,
            ],
            [
                'status_text' => $statusText,
                'outcome' => $outcome,
                'voting_house' => $votingHouse,
                'condition_text' => filled($validated['condition_text'] ?? null)
                    ? trim((string) $validated['condition_text'])
                    : null,
                'created_by' => auth()->id(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json($service->analyseLead($lead->fresh()));
    }
}
