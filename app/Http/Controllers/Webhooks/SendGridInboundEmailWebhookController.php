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

class SendGridInboundEmailWebhookController extends Controller
{
    public function __construct(
        private readonly RemarketingResponseEventService $responseEventService,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => ['required', 'string'],
            'subject' => ['nullable', 'string'],
            'text' => ['nullable', 'string'],
            'html' => ['nullable', 'string'],
            'headers' => ['nullable'],
            'message-id' => ['nullable', 'string'],
        ]);

        $text = trim((string) $request->input('text', ''));
        $html = trim((string) $request->input('html', ''));
        if ($text === '' && $html === '') {
            $validator->errors()->add('content', 'At least one of text or html is required.');
        }

        if ($validator->fails()) {
            Log::warning('[sendgrid-inbound-email] invalid payload', [
                'errors' => $validator->errors()->toArray(),
                'from' => $request->input('from'),
                'message_id' => $request->input('message-id'),
            ]);

            return response()->json([
                'ok' => false,
                'status' => 'invalid_payload',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $fromRaw = trim((string) $request->input('from'));
        $fromEmail = $this->extractEmail($fromRaw);
        if ($fromEmail === null) {
            Log::warning('[sendgrid-inbound-email] invalid payload', [
                'from' => $fromRaw,
                'message_id' => $request->input('message-id'),
            ]);

            return response()->json([
                'ok' => false,
                'status' => 'invalid_payload',
            ], 422);
        }

        $subject = trim((string) $request->input('subject', ''));
        $message = $text !== '' ? $text : trim(strip_tags($html));
        $messageId = trim((string) $request->input('message-id', ''));

        $sourceEventId = $messageId !== ''
            ? $messageId
            : hash('sha256', strtolower($fromEmail).'|'.$subject.'|'.($message !== '' ? $message : $html));
        $dedupeKey = 'email:'.$sourceEventId;

        $lead = Lead::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($fromEmail)])
            ->first();

        if (! $lead || ! is_numeric($lead->vicidial_lead_id) || (int) $lead->vicidial_lead_id <= 0) {
            Log::info('[sendgrid-inbound-email] skipped no matched lead', [
                'source_event_id' => $sourceEventId,
                'from' => $fromEmail,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'skipped',
                'reason' => 'lead_not_found',
            ]);
        }

        $vicidialLeadId = (int) $lead->vicidial_lead_id;
        $progress = LeadRemarketingProgress::query()
            ->where('lead_id', $vicidialLeadId)
            ->orderByDesc('id')
            ->first();

        $event = $this->responseEventService->createNeedsReviewEventIfEligible([
            'lead_id' => $vicidialLeadId,
            'jinx_lead_id' => $lead->id,
            'remarketing_progress_id' => $progress?->id,
            'source_event_id' => $sourceEventId,
            'dedupe_key' => $dedupeKey,
            'channel' => 'email',
            'direction' => 'inbound',
            'status' => 'needs_review',
            'matched_phone' => null,
            'matched_email' => $fromEmail,
            'message_preview' => $message !== '' ? $message : null,
            'raw_payload_json' => [
                'source' => 'sendgrid_inbound',
                'source_event_id' => $sourceEventId,
                'from_email' => $fromEmail,
                'subject' => $subject !== '' ? $subject : null,
                'message' => $message !== '' ? $message : null,
                'payload' => $request->all(),
            ],
            'detected_at' => now(),
        ], $lead);

        if ($event === null) {
            Log::info('[sendgrid-inbound-email] skipped lead not eligible', [
                'source_event_id' => $sourceEventId,
                'from' => $fromEmail,
                'lead_id' => $lead->id,
                'vicidial_lead_id' => $vicidialLeadId,
                'wip_status' => $lead->wip_status,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'skipped',
                'reason' => 'lead_not_eligible',
            ]);
        }

        if (! $event->wasRecentlyCreated) {
            Log::info('[sendgrid-inbound-email] duplicate ignored', [
                'source_event_id' => $sourceEventId,
                'from' => $fromEmail,
                'response_event_id' => $event->id,
            ]);

            return response()->json([
                'ok' => true,
                'status' => 'duplicate',
            ]);
        }

        Log::info('[sendgrid-inbound-email] response event created', [
            'source_event_id' => $sourceEventId,
            'from' => $fromEmail,
            'response_event_id' => $event->id,
            'lead_id' => $lead->id,
            'vicidial_lead_id' => $vicidialLeadId,
        ]);

        return response()->json([
            'ok' => true,
            'status' => 'created',
        ]);
    }

    private function extractEmail(string $from): ?string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches) === 1) {
            $email = trim((string) $matches[1]);
        } else {
            $email = trim($from);
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return strtolower($email);
    }
}
