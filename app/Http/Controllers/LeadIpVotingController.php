<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use App\Models\Lead;
use App\Services\IpCreditorVotingService;
use App\Services\IpCreditorVotingOverrideService;
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

    public function knownCreditors(Request $request, Lead $lead, IpCreditorVotingService $service): JsonResponse
    {
        if (!$lead->iva_ip_key || !array_key_exists($lead->iva_ip_key, Lead::IVA_IPS)) {
            return response()->json([
                'success' => false,
                'message' => 'Select an insolvency practitioner first.',
            ], 422);
        }

        $sourceCreditorId = $request->integer('source_creditor_id') ?: null;

        return response()->json([
            'success' => true,
            'ip_key' => $lead->iva_ip_key,
            'ip_label' => Lead::IVA_IPS[$lead->iva_ip_key] ?? $lead->iva_ip_key,
            'creditors' => $service->knownCreditorOptions($lead->iva_ip_key, $sourceCreditorId),
        ]);
    }

    public function previewMatch(Request $request, Lead $lead, IpCreditorVotingService $service): JsonResponse
    {
        $validated = $request->validate([
            'creditor_id' => ['required', 'integer', 'exists:creditors,id'],
        ]);

        if (!$lead->iva_ip_key || !array_key_exists($lead->iva_ip_key, Lead::IVA_IPS)) {
            return response()->json([
                'success' => false,
                'message' => 'Select an insolvency practitioner first.',
            ], 422);
        }

        $creditorId = (int) $validated['creditor_id'];
        if (!$service->isKnownCreditorForIp($creditorId, $lead->iva_ip_key)) {
            return response()->json([
                'success' => false,
                'message' => 'That creditor does not have a usable rule in the selected IP criteria.',
            ], 422);
        }

        $creditor = Creditor::findOrFail($creditorId);
        $assessment = $service->resolveCreditor($creditor, $lead->iva_ip_key, false);

        return response()->json([
            'success' => true,
            'creditor' => ['id' => (int) $creditor->id, 'name' => (string) $creditor->name],
            'rule' => [
                'status_text' => $assessment['status_raw'] ?? null,
                'outcome' => $assessment['outcome'] ?? 'unknown',
                'voting_house' => $assessment['voting_house'] ?? null,
                'notes' => $assessment['notes'] ?? null,
                'source_label' => $assessment['source_label'] ?? null,
                'can_match' => ($assessment['outcome'] ?? 'unknown') !== 'unknown' && !($assessment['needs_review'] ?? false),
            ],
        ]);
    }

    public function storeMatch(Request $request, Lead $lead, IpCreditorVotingService $service): JsonResponse
    {
        $validated = $request->validate([
            'source_creditor_id' => ['required', 'integer', 'exists:creditors,id'],
            'target_creditor_id' => ['required', 'integer', 'exists:creditors,id'],
        ]);

        if (!$lead->iva_ip_key || !array_key_exists($lead->iva_ip_key, Lead::IVA_IPS)) {
            return response()->json([
                'success' => false,
                'message' => 'Select an insolvency practitioner before saving a creditor match.',
            ], 422);
        }

        $sourceCreditorId = (int) $validated['source_creditor_id'];
        $targetCreditorId = (int) $validated['target_creditor_id'];

        if ($sourceCreditorId === $targetCreditorId) {
            return response()->json([
                'success' => false,
                'message' => 'Choose a different existing creditor to match to.',
            ], 422);
        }

        if (!$lead->debts()->where('creditor_id', $sourceCreditorId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'That unresolved creditor is not on this case.',
            ], 422);
        }

        $source = Creditor::findOrFail($sourceCreditorId);
        if (strcasecmp(trim((string) $source->name), 'Could Not Match') === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Match this debt to its real creditor first. Jinx will not learn a global rule for “Could Not Match”.',
            ], 422);
        }

        if (!$service->isKnownCreditorForIp($targetCreditorId, $lead->iva_ip_key)) {
            return response()->json([
                'success' => false,
                'message' => 'The selected match does not have a usable rule in this IP criteria.',
            ], 422);
        }

        $target = Creditor::findOrFail($targetCreditorId);
        $targetAssessment = $service->resolveCreditor($target, $lead->iva_ip_key, false);
        if (($targetAssessment['outcome'] ?? 'unknown') === 'unknown' || ($targetAssessment['needs_review'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'The selected creditor rule still needs review, so it cannot be used as a learned match.',
            ], 422);
        }

        $existing = DB::table('ip_creditor_voting_matches')
            ->where('source_creditor_id', $sourceCreditorId)
            ->where('ip_key', $lead->iva_ip_key)
            ->first();

        $values = [
            'target_creditor_id' => $targetCreditorId,
            'created_by' => $request->user()?->id,
            'created_from_lead_id' => $lead->id,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('ip_creditor_voting_matches')->where('id', $existing->id)->update($values);
        } else {
            DB::table('ip_creditor_voting_matches')->insert(array_merge($values, [
                'source_creditor_id' => $sourceCreditorId,
                'ip_key' => $lead->iva_ip_key,
                'created_at' => now(),
            ]));
        }

        $analysis = $service->analyseLead($lead->fresh());
        $analysis['match_saved'] = [
            'source_creditor_id' => $sourceCreditorId,
            'source_creditor_name' => (string) $source->name,
            'target_creditor_id' => $targetCreditorId,
            'target_creditor_name' => (string) $target->name,
            'message' => 'Remembered '.$source->name.' as '.$target->name.' for '.(Lead::IVA_IPS[$lead->iva_ip_key] ?? $lead->iva_ip_key).' voting on all cases.',
        ];

        return response()->json($analysis);
    }

    public function storeOverride(Request $request, Lead $lead, IpCreditorVotingService $service, IpCreditorVotingOverrideService $overrides): JsonResponse
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
        $votingHouse = filled($validated['voting_house'] ?? null)
            ? trim((string) $validated['voting_house'])
            : null;

        if ($statusText === 'Accept - via house vote' && !$votingHouse) {
            return response()->json([
                'success' => false,
                'message' => 'Enter the voting house for an Accept - via house vote rule.',
            ], 422);
        }

        $overrides->setOverride(
            $creditor,
            $lead->iva_ip_key,
            $statusText,
            $votingHouse,
            filled($validated['condition_text'] ?? null) ? trim((string) $validated['condition_text']) : null,
            $request->user()?->id,
            $lead->id,
            null,
            'Structured unresolved-creditor voting alert',
            'assistant_alert'
        );

        return response()->json($service->analyseLead($lead->fresh()));
    }
}
