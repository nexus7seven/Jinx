<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Services\RemarketingResponseEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TwilioInboundSmsWebhookController extends Controller
{
    public function __construct(
        private readonly RemarketingResponseEventService $responseEventService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'From' => ['required', 'string'],
            'MessageSid' => ['required', 'string'],
            'Body' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            Log::warning('[twilio-inbound-sms] invalid payload', [
                'errors' => $validator->errors()->toArray(),
                'from' => $request->input('From'),
                'message_sid' => $request->input('MessageSid'),
            ]);

            return response()->json([
                'ok' => false,
                'status' => 'invalid_payload',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();

        $from = trim((string) $validated['From']);
        $messageSid = trim((string) $validated['MessageSid']);
        $body = isset($validated['Body']) ? trim((string) $validated['Body']) : null;

        if ($from === '' || $messageSid === '') {
            Log::warning('[twilio-inbound-sms] invalid payload', [
                'message_sid' => $messageSid,
                'from' => $from,
            ]);

            return response()->json([
                'ok' => false,
                'status' => 'invalid_payload',
            ], 422);
        }

        $lead = $this->matchLeadByPhone($from);
        if (! $lead) {
            Log::info('[twilio-inbound-sms] skipped no matched lead', [
                'message_sid' => $messageSid,
                'from' => $from,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'skipped',
                'reason' => 'lead_not_found',
            ]);
        }

        $progress = LeadRemarketingProgress::query()
            ->where('lead_id', (int) $lead->vicidial_lead_id)
            ->orderByDesc('id')
            ->first();

        $event = $this->responseEventService->createNeedsReviewEventIfEligible([
            'lead_id' => (int) $lead->vicidial_lead_id,
            'jinx_lead_id' => $lead->id,
            'remarketing_progress_id' => $progress?->id,
            'source_event_id' => $messageSid,
            'dedupe_key' => 'sms:'.$messageSid,
            'channel' => 'sms',
            'direction' => 'inbound',
            'status' => 'needs_review',
            'matched_phone' => $from,
            'matched_email' => null,
            'message_preview' => $body !== '' ? $body : null,
            'raw_payload_json' => [
                'source' => 'twilio_inbound_sms',
                'message_sid' => $messageSid,
                'from' => $from,
                'body' => $body,
                'payload' => $request->all(),
            ],
            'detected_at' => now(),
        ], $lead);

        if ($event === null) {
            Log::info('[twilio-inbound-sms] skipped lead not eligible', [
                'message_sid' => $messageSid,
                'from' => $from,
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $lead->vicidial_lead_id,
                'wip_status' => $lead->wip_status,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'skipped',
                'reason' => 'lead_not_eligible',
            ]);
        }

        if (! $event->wasRecentlyCreated) {
            Log::info('[twilio-inbound-sms] duplicate ignored', [
                'message_sid' => $messageSid,
                'from' => $from,
                'response_event_id' => $event->id,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'duplicate',
            ]);
        }

        Log::info('[twilio-inbound-sms] response event created', [
            'message_sid' => $messageSid,
            'from' => $from,
            'response_event_id' => $event->id,
            'lead_id' => $lead->id,
            'vicidial_lead_id' => $lead->vicidial_lead_id,
        ]);

        return response()->json([
            'ok' => true,
            'status' => 'created',
        ]);
    }

    private function matchLeadByPhone(string $from): ?Lead
    {
        $variants = $this->phoneVariants($from);
        if ($variants === []) {
            return null;
        }

        return Lead::query()
            ->where(function ($query) use ($variants): void {
                foreach ($variants as $variant) {
                    $query->orWhereRaw($this->digitsOnlySql('phone_number').' = ?', [$variant]);
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<string>
     */
    private function phoneVariants(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return [];
        }

        $variants = [$digits];

        if (str_starts_with($digits, '44') && strlen($digits) > 2) {
            $rest = substr($digits, 2);
            $variants[] = $rest;
            $variants[] = '0'.$rest;
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $rest = substr($digits, 1);
            $variants[] = '44'.$rest;
            $variants[] = $rest;
        } elseif (str_starts_with($digits, '7') && strlen($digits) === 10) {
            $variants[] = '44'.$digits;
            $variants[] = '0'.$digits;
        }

        return array_values(array_unique(array_filter($variants, fn (string $value): bool => $value !== '')));
    }

    private function digitsOnlySql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column},''),' ',''),'-',''),'+',''),'(',''),')',''),'.','')";
    }
}
