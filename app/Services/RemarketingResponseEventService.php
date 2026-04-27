<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\RemarketingResponseEvent;
use InvalidArgumentException;

class RemarketingResponseEventService
{
    public function leadIsEligibleForResponseInbox(?Lead $lead): bool
    {
        if ($lead === null) {
            return false;
        }

        return in_array((string) $lead->wip_status, ['Lost Contact', 'DEAD'], true);
    }

    public function createNeedsReviewEventIfEligible(array $data, ?Lead $lead): ?RemarketingResponseEvent
    {
        if (! $this->leadIsEligibleForResponseInbox($lead)) {
            return null;
        }

        return $this->createNeedsReviewEvent($data);
    }

    public function createNeedsReviewEvent(array $data): RemarketingResponseEvent
    {
        $leadId = $data['lead_id'] ?? null;
        $channel = $data['channel'] ?? null;
        $dedupeKey = $data['dedupe_key'] ?? null;

        if (! is_numeric($leadId) || (int) $leadId <= 0) {
            throw new InvalidArgumentException('createNeedsReviewEvent requires a valid lead_id.');
        }

        if (! is_string($channel) || trim($channel) === '') {
            throw new InvalidArgumentException('createNeedsReviewEvent requires channel.');
        }

        if (! is_string($dedupeKey) || trim($dedupeKey) === '') {
            throw new InvalidArgumentException('createNeedsReviewEvent requires dedupe_key.');
        }

        return RemarketingResponseEvent::query()->firstOrCreate(
            ['dedupe_key' => trim($dedupeKey)],
            [
                'lead_id' => (int) $leadId,
                'jinx_lead_id' => isset($data['jinx_lead_id']) ? (int) $data['jinx_lead_id'] : null,
                'remarketing_progress_id' => isset($data['remarketing_progress_id']) ? (int) $data['remarketing_progress_id'] : null,
                'source_event_id' => $data['source_event_id'] ?? null,
                'channel' => trim((string) $channel),
                'direction' => $data['direction'] ?? 'inbound',
                'status' => $data['status'] ?? RemarketingResponseEvent::STATUS_NEEDS_REVIEW,
                'matched_phone' => $data['matched_phone'] ?? null,
                'matched_email' => $data['matched_email'] ?? null,
                'message_preview' => $data['message_preview'] ?? null,
                'raw_payload_json' => $data['raw_payload_json'] ?? null,
                'detected_at' => $data['detected_at'] ?? now(),
                'handled_at' => $data['handled_at'] ?? null,
                'handled_by' => $data['handled_by'] ?? null,
                'decision' => $data['decision'] ?? null,
            ]
        );
    }
}
