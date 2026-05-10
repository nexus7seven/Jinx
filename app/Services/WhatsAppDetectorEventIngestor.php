<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Models\LeadReengagementEvent;
use App\Models\RemarketingTask;
use App\Models\WhatsAppDetectorEvent;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WhatsAppDetectorEventIngestor
{
    public function __construct(
        private RemarketingResponseEventService $remarketingResponseEventService,
    ) {
    }

    /**
     * @return array{
     *   imported: int,
     *   skipped_existing: int,
     *   unmatched: int,
     *   ambiguous: int,
     *   matched_active_linear_progress: int,
     *   matched_after_flow_start: int,
     *   matched_not_after_flow_start: int,
     *   matched_no_flow_start: int,
     *   invalid_payload: int,
     *   resolved_by_active_linear_progress: int,
     *   response_events_created: int,
     *   response_events_duplicate: int,
     *   response_events_skipped: int,
     *   response_events_skipped_lead_not_eligible_for_response_inbox: int,
     * }
     */
    public function ingest(?string $jsonlPath = null): array
    {
        $path = $jsonlPath ?? config('whatsapp_detector.jsonl_path');
        $eventsUrl = config('whatsapp_detector.events_url');

        $stats = [
            'imported' => 0,
            'skipped_existing' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'matched_active_linear_progress' => 0,
            'matched_after_flow_start' => 0,
            'matched_not_after_flow_start' => 0,
            'matched_no_flow_start' => 0,
            'invalid_payload' => 0,
            'resolved_by_active_linear_progress' => 0,
            'response_events_created' => 0,
            'response_events_duplicate' => 0,
            'response_events_skipped' => 0,
            'response_events_skipped_lead_not_eligible_for_response_inbox' => 0,
        ];

        $handle = null;
        try {
            if (is_string($eventsUrl) && $eventsUrl !== '') {
                $response = Http::timeout(10)->get($eventsUrl);
                if (! $response->successful()) {
                    $message = sprintf('Failed to fetch WhatsApp detector events URL [%s]: HTTP %d', $eventsUrl, $response->status());
                    Log::error($message);
                    throw new \RuntimeException($message);
                }

                foreach (preg_split('/\r\n|\r|\n/', $response->body()) ?: [] as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }

                    $decoded = json_decode($trimmed, true);
                    if (! is_array($decoded)) {
                        $stats['invalid_payload']++;
                        continue;
                    }

                    $this->ingestPayload($decoded, $stats);
                }

                return $stats;
            }

            if (! is_string($path) || $path === '' || ! File::isReadable($path)) {
                return $stats;
            }

            $openedHandle = fopen($path, 'rb');
            if ($openedHandle === false) {
                return $stats;
            }

            $handle = $openedHandle;

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $decoded = json_decode($trimmed, true);
                if (! is_array($decoded)) {
                    $stats['invalid_payload']++;
                    continue;
                }

                $this->ingestPayload($decoded, $stats);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $stats;
    }

    /**
     * Import a single decoded detector event (same processing as one JSONL line).
     *
     * @param  array<string, mixed>  $decoded
     * @param  array<string, int>|null  $stats  When set (JSONL ingest), aggregate counters are updated.
     * @return array{
     *   status: 'imported'|'skipped_existing'|'invalid_payload',
     *   event_id: string,
     *   match_status?: string|null,
     *   is_after_flow_start?: bool|null,
     * }
     */
    public function ingestPayload(array $decoded, ?array &$stats = null): array
    {
        $eventId = isset($decoded['event_id']) ? (string) $decoded['event_id'] : '';
        if ($eventId === '') {
            if ($stats !== null) {
                $stats['invalid_payload']++;
            }

            return [
                'status' => 'invalid_payload',
                'event_id' => '',
                'match_status' => null,
                'is_after_flow_start' => null,
            ];
        }

        if (WhatsAppDetectorEvent::query()->where('event_id', $eventId)->exists()) {
            if ($stats !== null) {
                $stats['skipped_existing']++;
            }

            return $this->skippedExistingResponse($eventId);
        }

        $noteParts = [];

        $phone = isset($decoded['phone']) ? (string) $decoded['phone'] : null;
        if ($phone === null || trim($phone) === '') {
            $noteParts[] = 'Phone missing.';
        }

        $inferredMessageAt = $this->parseIncomingDatetime($decoded['inferred_message_at'] ?? null, 'inferred_message_at', $noteParts);
        $lastInboundAt = $this->parseIncomingDatetime($decoded['last_inbound_at'] ?? null, 'last_inbound_at', $noteParts);
        $detectedAt = $this->parseIncomingDatetime($decoded['detected_at'] ?? null, 'detected_at', $noteParts);
        $batchWrittenAt = $this->parseIncomingDatetime($decoded['batch_written_at'] ?? null, 'batch_written_at', $noteParts);

        $engagementAt = $this->deriveEngagementAt($inferredMessageAt, $detectedAt, $noteParts);

        $leadIds = $this->findMatchingLeadIds($phone);
        $matchStatus = 'unmatched';
        $matchedLeadId = null;
        $matchNotes = null;
        $prefetchedFlowStartedAt = null;

        if (count($leadIds) === 0) {
            $matchStatus = 'unmatched';
            if ($stats !== null) {
                $stats['unmatched']++;
            }
        } else {
            $resolution = $this->resolveLeadFromLinearProgress($leadIds);
            $matchNotes = $resolution['resolutionNote'];
            $prefetchedFlowStartedAt = $resolution['resolvedFlowStartedAt'];
            $matchedLeadId = $resolution['matchedLeadId'];

            if ($resolution['resolutionStatus'] === 'ambiguous_active_progress') {
                $matchStatus = 'ambiguous_active_linear_progress';
                if ($stats !== null) {
                    $stats['ambiguous']++;
                }
            } elseif ($resolution['resolutionStatus'] === 'ambiguous_phone') {
                $matchStatus = 'ambiguous';
                if ($stats !== null) {
                    $stats['ambiguous']++;
                }
            } elseif ($matchedLeadId !== null) {
                if ($resolution['resolvedByActiveProgress'] && $stats !== null) {
                    $stats['resolved_by_active_linear_progress']++;
                    $stats['matched_active_linear_progress']++;
                }
            } else {
                $matchStatus = 'unmatched';
                if ($stats !== null) {
                    $stats['unmatched']++;
                }
            }
        }

        $flowStartedAt = null;
        if ($matchedLeadId !== null) {
            $flowStartedAt = $prefetchedFlowStartedAt;
            if ($resolution['resolvedByActiveProgress']) {
                $matchStatus = 'matched_active_linear_progress';
            } elseif ($flowStartedAt === null) {
                $matchStatus = 'matched_no_flow_start';
                if ($stats !== null) {
                    $stats['matched_no_flow_start']++;
                }
            }
        }

        $isAfter = $this->decideIsAfterFlowStart($engagementAt, $flowStartedAt, $matchedLeadId);

        if ($matchedLeadId !== null && $flowStartedAt !== null) {
            if ($isAfter === true) {
                $matchStatus = 'matched_after_flow_start';
                if ($stats !== null) {
                    $stats['matched_after_flow_start']++;
                }
            } elseif ($isAfter === false) {
                $matchStatus = 'matched_not_after_flow_start';
                if ($stats !== null) {
                    $stats['matched_not_after_flow_start']++;
                }
            }
        }

        $notes = $this->joinNotes(array_merge($noteParts, $matchNotes !== null ? [$matchNotes] : []));

        $row = [
            'event_id' => $eventId,
            'chat_id' => $this->nullableString($decoded, 'chat_id'),
            'chat_name' => $this->nullableString($decoded, 'chat_name'),
            'phone' => $phone !== null && trim($phone) !== '' ? $phone : null,
            'preview_time_text' => $this->nullableString($decoded, 'preview_time_text'),
            'inferred_message_at' => $inferredMessageAt,
            'last_inbound_at' => $lastInboundAt,
            'latest_message' => $this->nullableString($decoded, 'latest_message'),
            'detected_at' => $detectedAt,
            'batch_written_at' => $batchWrittenAt,
            'matched_vicidial_lead_id' => $matchedLeadId,
            'flow_started_at' => $flowStartedAt,
            'engagement_at' => $engagementAt,
            'is_after_flow_start' => $isAfter,
            'match_status' => $matchStatus,
            'notes' => $notes,
            'raw_payload' => $decoded,
        ];

        try {
            $detectorEvent = WhatsAppDetectorEvent::query()->create($row);
            if ($stats !== null) {
                $stats['imported']++;
            }

            $responseStats = $stats ?? [
                'response_events_skipped' => 0,
                'response_events_created' => 0,
                'response_events_duplicate' => 0,
                'response_events_skipped_lead_not_eligible_for_response_inbox' => 0,
            ];
            $this->maybeCreateRemarketingResponseEvent($detectorEvent, $responseStats);

            if (in_array($matchStatus, ['matched_after_flow_start', 'matched_active_linear_progress'], true) && $matchedLeadId !== null && ($isAfter === true || $matchStatus === 'matched_active_linear_progress')) {
                try {
                    $context = $this->snapCurrentCycleRemarketingContext((int) $matchedLeadId);

                    RemarketingTask::query()
                        ->where('lead_id', $matchedLeadId)
                        ->where('status', RemarketingTask::STATUS_PENDING)
                        ->update([
                            'status' => RemarketingTask::STATUS_CLOSED,
                        ]);

                    $lead = Lead::query()
                        ->where('vicidial_lead_id', $matchedLeadId)
                        ->first();

                    if ($lead !== null) {
                        $lead->update(['wip_status' => Lead::WIP_STATUS_REENGAGED]);
                    }

                    LeadReengagementEvent::query()->create([
                        'lead_id' => $lead?->id,
                        'vicidial_lead_id' => $matchedLeadId,
                        'whatsapp_detector_event_id' => $detectorEvent->id,
                        'whatsapp_detector_event_uuid' => $eventId,
                        'channel' => 'whatsapp',
                        'remarketing_stage' => $context['stage'],
                        'remarketing_task_type' => $context['task_type'],
                        'remarketing_reason' => $context['reason'],
                        'flow_started_at' => $flowStartedAt,
                        'engagement_at' => $engagementAt,
                    ]);

                    if ($lead !== null) {
                        $this->appendNotesToEvent(
                            $eventId,
                            'Closed pending remarketing tasks and set lead to Re-engaged due to WhatsApp reply during active remarketing progress.'
                        );
                    } else {
                        $this->appendNotesToEvent(
                            $eventId,
                            'Closed pending remarketing tasks due to WhatsApp reply during active remarketing progress. No Jinx lead matched vicidial_lead_id.'
                        );
                    }
                } catch (Throwable $e) {
                    $this->appendNotesToEvent(
                        $eventId,
                        'Re-engagement handling failed: '.$e->getMessage()
                    );
                }
            }
        } catch (Throwable $e) {
            if (WhatsAppDetectorEvent::query()->where('event_id', $eventId)->exists()) {
                if ($stats !== null) {
                    $stats['skipped_existing']++;
                }

                return $this->skippedExistingResponse($eventId);
            }

            throw $e;
        }

        return [
            'status' => 'imported',
            'event_id' => $eventId,
            'match_status' => $matchStatus,
            'is_after_flow_start' => $isAfter,
        ];
    }

    /**
     * @return array{
     *   status: 'skipped_existing',
     *   event_id: string,
     *   match_status: string|null,
     *   is_after_flow_start: bool|null,
     * }
     */
    private function skippedExistingResponse(string $eventId): array
    {
        $row = WhatsAppDetectorEvent::query()->where('event_id', $eventId)->first();

        return [
            'status' => 'skipped_existing',
            'event_id' => $eventId,
            'match_status' => $row?->match_status,
            'is_after_flow_start' => $row?->is_after_flow_start,
        ];
    }

    /**
     * @param array<string, int> $stats
     */
    private function maybeCreateRemarketingResponseEvent(WhatsAppDetectorEvent $event, array &$stats): void
    {
        $leadId = $event->matched_vicidial_lead_id;
        $matchStatus = strtolower(trim((string) ($event->match_status ?? '')));
        $isAfter = $event->is_after_flow_start;

        if ($leadId === null || (int) $leadId <= 0) {
            $stats['response_events_skipped']++;
            Log::info('Remarketing response event skipped (whatsapp)', [
                'detector_event_id' => $event->event_id,
                'reason' => 'missing_matched_vicidial_lead_id',
            ]);
            return;
        }

        if (! in_array($matchStatus, ['matched_after_flow_start', 'matched_active_linear_progress'], true)) {
            $stats['response_events_skipped']++;
            Log::info('Remarketing response event skipped (whatsapp)', [
                'detector_event_id' => $event->event_id,
                'lead_id' => (int) $leadId,
                'reason' => 'match_status_not_supported_for_response_event',
                'match_status' => $matchStatus,
            ]);
            return;
        }

        if ($matchStatus === 'matched_after_flow_start' && $isAfter !== true) {
            $stats['response_events_skipped']++;
            Log::info('Remarketing response event skipped (whatsapp)', [
                'detector_event_id' => $event->event_id,
                'lead_id' => (int) $leadId,
                'reason' => $isAfter === false ? 'before_flow_start' : 'missing_after_flow_start_flag',
            ]);
            return;
        }

        if (! $this->looksInboundCustomerReply($event)) {
            $stats['response_events_skipped']++;
            Log::info('Remarketing response event skipped (whatsapp)', [
                'detector_event_id' => $event->event_id,
                'lead_id' => (int) $leadId,
                'reason' => 'not_inbound_customer_reply',
            ]);
            return;
        }

        $jinxLead = Lead::query()
            ->where('vicidial_lead_id', (int) $leadId)
            ->first();

        $progress = LeadRemarketingProgress::query()
            ->where('lead_id', (int) $leadId)
            ->orderByDesc('id')
            ->first();

        $detectedAt = $event->last_inbound_at
            ?? $event->inferred_message_at
            ?? $event->engagement_at
            ?? $event->detected_at
            ?? now();

        $sourceEventId = trim((string) ($event->event_id ?? ''));
        $dedupeKey = $sourceEventId !== ''
            ? 'whatsapp:'.$sourceEventId
            : sprintf(
                'whatsapp:%d:%s:%s:%s',
                (int) $leadId,
                $detectedAt->format('Y-m-d H:i:s'),
                (string) ($event->phone ?? ''),
                (string) ($event->preview_time_text ?? '')
            );

        $payload = [
            'chat_id' => $event->chat_id,
            'chat_name' => $event->chat_name,
            'phone' => $event->phone,
            'preview_time_text' => $event->preview_time_text,
            'latest_message' => $event->latest_message,
            'matched_vicidial_lead_id' => $event->matched_vicidial_lead_id,
            'is_after_flow_start' => $event->is_after_flow_start,
            'match_status' => $event->match_status,
        ];

        $responseEvent = $this->remarketingResponseEventService->createNeedsReviewEventIfEligible([
            'lead_id' => (int) $leadId,
            'jinx_lead_id' => $jinxLead?->id,
            'remarketing_progress_id' => $progress?->id,
            'source_event_id' => $sourceEventId !== '' ? $sourceEventId : null,
            'dedupe_key' => $dedupeKey,
            'channel' => 'whatsapp',
            'direction' => 'inbound',
            'status' => 'needs_review',
            'matched_phone' => $event->phone,
            'matched_email' => null,
            'message_preview' => $event->latest_message,
            'raw_payload_json' => $payload,
            'detected_at' => $detectedAt,
        ], $jinxLead);

        if ($responseEvent === null) {
            $stats['response_events_skipped']++;
            $stats['response_events_skipped_lead_not_eligible_for_response_inbox']++;
            Log::info('Remarketing response event skipped (whatsapp)', [
                'detector_event_id' => $event->event_id,
                'lead_id' => (int) $leadId,
                'reason' => 'lead_not_eligible_for_response_inbox',
                'wip_status' => $jinxLead?->wip_status,
            ]);
            return;
        }

        if ($responseEvent->wasRecentlyCreated) {
            $stats['response_events_created']++;
            Log::info('Remarketing response event created (whatsapp)', [
                'response_event_id' => $responseEvent->id,
                'detector_event_id' => $event->event_id,
                'lead_id' => (int) $leadId,
                'dedupe_key' => $dedupeKey,
            ]);
            return;
        }

        $stats['response_events_duplicate']++;
        Log::info('Remarketing response event duplicate exists (whatsapp)', [
            'response_event_id' => $responseEvent->id,
            'detector_event_id' => $event->event_id,
            'lead_id' => (int) $leadId,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    private function looksInboundCustomerReply(WhatsAppDetectorEvent $event): bool
    {
        if ($event->last_inbound_at !== null) {
            return true;
        }

        if ($event->inferred_message_at !== null && trim((string) ($event->latest_message ?? '')) !== '') {
            return true;
        }

        return false;
    }

    private function deriveEngagementAt(?Carbon $inferredMessageAt, ?Carbon $detectedAt, array &$noteParts): ?Carbon
    {
        if ($inferredMessageAt !== null) {
            return $inferredMessageAt;
        }

        if ($detectedAt !== null) {
            return $detectedAt;
        }

        $noteParts[] = 'Engagement time could not be derived from inferred_message_at or detected_at.';

        return null;
    }

    /**
     * @param  array<int, string>  $noteParts
     */
    private function parseIncomingDatetime(mixed $value, string $fieldLabel, array &$noteParts): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $noteParts[] = "Invalid datetime for {$fieldLabel}: non-string value.";
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        try {
            if ($this->hasExplicitZOrOffset($s)) {
                return Carbon::parse($s);
            }

            $tzName = (string) config('whatsapp_detector.detector_local_timezone', 'Europe/London');
            $tz = new DateTimeZone($tzName);

            return Carbon::parse($s, $tz);
        } catch (Throwable) {
            $preview = strlen($s) > 120 ? substr($s, 0, 120) . '…' : $s;
            $noteParts[] = "Invalid datetime for {$fieldLabel}: {$preview}";

            return null;
        }
    }

    private function hasExplicitZOrOffset(string $s): bool
    {
        $t = rtrim($s);
        if ($t === '') {
            return false;
        }

        if (preg_match('/Z$/i', $t)) {
            return true;
        }

        return (bool) preg_match('/[+-]\d{2}(:\d{2}){1,2}$|[+-]\d{4}$/', $t);
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinNotes(array $parts): ?string
    {
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $p) => $p !== ''));
        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function nullableString(array $decoded, string $key): ?string
    {
        if (! array_key_exists($key, $decoded) || $decoded[$key] === null) {
            return null;
        }

        return (string) $decoded[$key];
    }

    /**
     * Strip non-digits; if starts with 44, also add variant without 44. No leading 0 injection.
     *
     * @return list<string>
     */
    public function phoneVariants(?string $phone): array
    {
        if ($phone === null || $phone === '') {
            return [];
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return [];
        }

        $variants = [$digits];
        if (str_starts_with($digits, '44') && strlen($digits) > 2) {
            $variants[] = substr($digits, 2);
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return list<int>
     */
    private function findMatchingLeadIds(?string $phone): array
    {
        $variants = $this->phoneVariants($phone);
        if ($variants === []) {
            return [];
        }

        $table = (string) config('whatsapp_detector.leads_table');
        $idCol = (string) config('whatsapp_detector.lead_id_column');
        $phoneCols = config('whatsapp_detector.lead_phone_columns');
        $columns = is_array($phoneCols) ? $phoneCols : [];

        if ($table === '' || $idCol === '' || $columns === []) {
            return [];
        }

        $ids = [];
        foreach ($variants as $variant) {
            $q = DB::connection('asterisk')->table($table)->select($idCol);
            $q->where(function ($w) use ($columns, $variant) {
                foreach ($columns as $col) {
                    if (! is_string($col) || $col === '') {
                        continue;
                    }
                    $w->orWhereRaw($this->digitsOnlyEqualsSql($col) . ' = ?', [$variant]);
                }
            });
            foreach ($q->pluck($idCol) as $id) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        sort($ids);

        return $ids;
    }

    /**
     * SQL expression: digits-only form of a column (best-effort; extend REPLACE chain if needed).
     */
    private function digitsOnlyEqualsSql(string $column): string
    {
        $c = $column;

        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$c},''),' ',''),'-',''),'+',''),'(',''),')',''),'.','')";
    }

    private function resolveLeadFromLinearProgress(array $leadIds): array
    {
        if ($leadIds === []) {
            return [
                'matchedLeadId' => null,
                'resolvedFlowStartedAt' => null,
                'resolutionStatus' => 'none',
                'resolutionNote' => null,
                'resolvedByActiveProgress' => false,
            ];
        }

        $progressTable = (new LeadRemarketingProgress())->getTable();
        $hasStartedAt = Schema::connection('mysql')->hasColumn($progressTable, 'started_at');

        $query = LeadRemarketingProgress::query()
            ->whereIn('lead_id', $leadIds)
            ->where('progress_status', 'active');
        if ($hasStartedAt) {
            $query->select(['lead_id', 'created_at', 'started_at']);
        } else {
            $query->select(['lead_id', 'created_at']);
        }
        $activeProgressRows = $query->get();

        $timestampsByLead = [];
        foreach ($activeProgressRows as $row) {
            $leadId = (int) $row->lead_id;
            $rawTimestamp = ($hasStartedAt ? $row->started_at : null) ?? $row->created_at;
            $timestampsByLead[$leadId] = $this->parseProgressTimestamp($rawTimestamp);
        }

        $activeLeadIds = array_values(array_unique(array_map(
            fn ($row) => (int) $row->lead_id,
            $activeProgressRows->all()
        )));
        sort($activeLeadIds);
        $idsStr = implode(',', $leadIds);

        if (count($activeLeadIds) > 1) {
            return [
                'matchedLeadId' => null,
                'resolvedFlowStartedAt' => null,
                'resolutionStatus' => 'ambiguous_active_progress',
                'resolutionNote' => "ambiguous_active_linear_progress Multiple leads matched phone ({$idsStr}) with active lead_remarketing_progress.",
                'resolvedByActiveProgress' => false,
            ];
        }

        if (count($activeLeadIds) === 1) {
            $leadId = $activeLeadIds[0];

            return [
                'matchedLeadId' => $leadId,
                'resolvedFlowStartedAt' => $timestampsByLead[$leadId] ?? null,
                'resolutionStatus' => 'resolved_active_progress',
                'resolutionNote' => 'Resolved by active lead_remarketing_progress',
                'resolvedByActiveProgress' => true,
            ];
        }

        if (count($leadIds) === 1) {
            return [
                'matchedLeadId' => (int) $leadIds[0],
                'resolvedFlowStartedAt' => null,
                'resolutionStatus' => 'resolved_phone_only',
                'resolutionNote' => 'no_active_linear_progress',
                'resolvedByActiveProgress' => false,
            ];
        }

        return [
            'matchedLeadId' => null,
            'resolvedFlowStartedAt' => null,
            'resolutionStatus' => 'ambiguous_phone',
            'resolutionNote' => "no_active_linear_progress Multiple leads matched phone ({$idsStr}) and none had active lead_remarketing_progress.",
            'resolvedByActiveProgress' => false,
        ];
    }

    private function parseProgressTimestamp(mixed $raw): ?Carbon
    {
        if ($raw === null) {
            return null;
        }

        try {
            if ($raw instanceof Carbon) {
                return $raw;
            }

            return Carbon::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function decideIsAfterFlowStart(?Carbon $engagementAt, ?Carbon $flowStartedAt, ?int $matchedLeadId): ?bool
    {
        if ($matchedLeadId === null || $flowStartedAt === null) {
            return null;
        }

        if ($engagementAt === null) {
            return null;
        }

        return $engagementAt->gt($flowStartedAt);
    }

    private function appendNotesToEvent(string $eventId, string $append): void
    {
        $event = WhatsAppDetectorEvent::query()->where('event_id', $eventId)->first();
        if ($event === null) {
            return;
        }

        $existing = trim((string) ($event->notes ?? ''));
        $parts = $existing !== '' ? [$existing, $append] : [$append];
        $merged = $this->joinNotes($parts);
        $event->update(['notes' => $merged]);
    }

    /**
     * Best-effort snapshot of the latest pending remarketing task in the current cycle (after latest flow_start).
     *
     * @return array{stage: ?string, task_type: ?string, reason: ?string}
     */
    private function snapCurrentCycleRemarketingContext(int $vicidialLeadId): array
    {
        $flowStart = RemarketingTask::query()
            ->where('lead_id', $vicidialLeadId)
            ->where('task_type', 'flow_started')
            ->where('reason', 'flow_start')
            ->orderByDesc('id')
            ->first();

        if ($flowStart === null) {
            return ['stage' => null, 'task_type' => null, 'reason' => null];
        }

        $task = RemarketingTask::query()
            ->where('lead_id', $vicidialLeadId)
            ->where('status', RemarketingTask::STATUS_PENDING)
            ->where('id', '>', $flowStart->id)
            ->orderByDesc('id')
            ->first();

        if ($task === null) {
            return ['stage' => null, 'task_type' => null, 'reason' => null];
        }

        return [
            'stage' => $task->stage !== null && trim((string) $task->stage) !== '' ? (string) $task->stage : null,
            'task_type' => $task->task_type !== null && trim((string) $task->task_type) !== '' ? (string) $task->task_type : null,
            'reason' => $task->reason !== null && trim((string) $task->reason) !== '' ? (string) $task->reason : null,
        ];
    }
}
