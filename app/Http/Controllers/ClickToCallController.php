<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\RemarketingCallbackService;
use App\Services\VicidialLeadLookupService;
use App\Support\VicidialDialPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClickToCallController extends Controller
{
    public function __construct(
        private RemarketingCallbackService $vicidialDialer,
        private VicidialLeadLookupService $vicidialLeadLookup,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
        ]);

        $lead = Lead::query()->findOrFail($validated['lead_id']);

        $national = VicidialDialPhone::nationalDigits($lead->phone_number);
        if ($national === null || $national === '') {
            return response()->json($this->errorPayload(
                'no_phone_number',
                'Lead has no usable phone number.'
            ), 422);
        }

        try {
            $dialContext = $this->vicidialLeadLookup->resolveDialContext($lead, $national);
        } catch (Throwable $e) {
            Log::error('Click-to-call: dial context lookup failed', [
                'lead_id' => $lead->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json($this->errorPayload(
                'dial_context_lookup_failed',
                'Could not resolve VICIdial campaign for this lead.'
            ), 502);
        }

        $resolvedLeadId = (int) ($dialContext['lead_id'] ?? 0);
        $campaignId = is_string($dialContext['campaign_id'] ?? null) ? trim((string) $dialContext['campaign_id']) : '';

        if ($campaignId === '') {
            Log::warning('Click-to-call: campaign could not be resolved', [
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $lead->vicidial_lead_id,
                'phone_national' => $national,
            ]);

            return response()->json($this->errorPayload(
                'no_campaign_for_direct_dial',
                'No VICIdial campaign found for this lead/phone.'
            ), 422);
        }

        try {
            $result = $this->vicidialDialer->dialLead([
                'lead_id' => $resolvedLeadId,
                'phone_number' => $national,
                'campaign_id' => $campaignId,
            ]);
        } catch (Throwable $e) {
            Log::error('Click-to-call: VICIdial dial request exception', [
                'lead_id' => $lead->id,
                'resolved_vicidial_lead_id' => $resolvedLeadId,
                'campaign_id' => $campaignId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json($this->errorPayload(
                'vicidial_request_failed',
                'Could not reach VICIdial dial endpoint.'
            ), 502);
        }

        if (! ($result['ok'] ?? false)) {
            Log::warning('Click-to-call: VICIdial reported dial failure', [
                'lead_id' => $lead->id,
                'resolved_vicidial_lead_id' => $resolvedLeadId,
                'campaign_id' => $campaignId,
                'result' => $result,
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'external_dial_failed',
                'message' => (string) ($result['message'] ?? 'VICIdial call failed.'),
            ], 502);
        }

        if (($result['popup_confirmed'] ?? false) === false) {
            Log::info('Click-to-call: dial started but popup not confirmed', [
                'lead_id' => $lead->id,
                'resolved_vicidial_lead_id' => $resolvedLeadId,
                'campaign_id' => $campaignId,
            ]);
        }

        return response()->json([
            'ok' => true,
            'dialled' => true,
            'popup_confirmed' => (bool) ($result['popup_confirmed'] ?? false),
            'lead_id' => $resolvedLeadId,
            'phone' => $national,
            'message' => (string) ($result['message'] ?? 'Call started successfully.'),
        ]);
    }

    /**
     * @return array{ok: false, error: string, message: string}
     */
    private function errorPayload(string $error, string $message): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'message' => $message,
        ];
    }
}
