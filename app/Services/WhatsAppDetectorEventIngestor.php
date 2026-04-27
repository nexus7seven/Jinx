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
use Illuminate\Support\Facades\Log;
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
     *   matched_after_flow_start: int,
     *   matched_not_after_flow_start: int,
     *   matched_no_flow_start: int,
     *   invalid_payload: int,
     *   resolved_by_latest_flow_start: int,
     *   response_events_created: int,
     *   response_events_duplicate: int,
     *   response_events_skipped: int,
     * }
     */
    public function ingest(?string $jsonlPath = null): array
    {
        $path = $jsonlPath ?? config('whatsapp_detector.jsonl_path');

        $stats = [
            'imported' => 0,
            'skipped_existing' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'matched_after_flow_start' => 0,
            'matched_not_after_flow_start' => 0,
            'matched_no_flow_start' => 0,
            'invalid_payload' => 0,
            'resolved_by_latest_flow_start' => 0,
            'response_events_created' => 0,
            'response_events_duplicate' => 0,
            'response_events_skipped' => 0,
        ];

        if (! is_string($path) || $path === '' || ! File::isReadable($path)) {
            return $stats;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $stats;
        }

        try {
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

                $eventId = isset($decoded['event_id']) ? (string) $decoded['event_id'] : '';
                if ($eventId === '') {
                    $stats['invalid_payload']++;
                    continue;
                }

                if (WhatsAppDetectorEvent::query()->where('event_id', $eventId)->exists()) {
                    $stats['skipped_existing']++;
                    continue;
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
                    $stats['unmatched']++;
                } elseif (count($leadIds) > 1) {
                    $resolution = $this->resolveLeadIdFromCandidatesByLatestFlowStart($leadIds);
                    if ($resolution['resolutionStatus'] === 'resolved') {
                        $matchStatus = 'matched';
                        $matchedLeadId = $resolution['matchedLeadId'];
                        $matchNotes = $resolution['resolutionNote'];
                        $prefetchedFlowStartedAt = $resolution['resolvedFlowStartedAt'];
                        $stats['resolved_by_latest_flow_start']++;
                    } else {
                        $matchStatus = 'ambiguous';
                        $matchNotes = $resolution['resolutionNote'];
                        $stats['ambiguous']++;
                    }
                } else {
                    $matchStatus = 'matched';
                    $matchedLeadId = $leadIds[0];
                }

                $flowStartedAt = null;
                if ($matchedLeadId !== null) {
                    $flowStartedAt = $prefetchedFlowStartedAt ?? $this->latestFlowStartedAt((int) $matchedLeadId);
                    if ($flowStartedAt === null) {
                        $matchStatus = 'matched_no_flow_start';
                        $stats['matched_no_flow_start']++;
                    }
                }

                $isAfter = $this->decideIsAfterFlowStart($engagementAt, $flowStartedAt, $matchedLeadId);

                if ($matchedLeadId !== null && $flowStartedAt !== null) {
                    if ($isAfter === true) {
                        $stats['matched_after_flow_start']++;
                    } elseif ($isAfter === false) {
                        $stats['matched_not_after_flow_start']++;
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
                    $stats['imported']++;
                    $this->maybeCreateRemarketingResponseEvent($detectorEvent, $stats);

                    if ($matchStatus === 'matched' && $matchedLeadId !== null && $isAfter === true) {
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
                                    'Closed pending remarketing tasks and set lead to Re-engaged due to WhatsApp reply after flow_start.'
                                );
                            } else {
                                $this->appendNotesToEvent(
                                    $eventId,
                                    'Closed pending remarketing tasks due to WhatsApp reply after flow_start. No Jinx lead matched vicidial_lead_id.'
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
                        $stats['skipped_existing']++;
                    } else {
                        throw $e;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $stats;
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

        if ($matchStatus !== '' && $matchStatus !== 'matched') {
            $stats['response_events_skipped']++;
            Log::info('Remarketing response event skipped (whatsapp)', [
                'detector_event_id' => $event->event_id,
                'lead_id' => (int) $leadId,
                'reason' => 'match_status_not_matched',
                'match_status' => $matchStatus,
            ]);
            return;
        }

        if ($isAfter !== true) {
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

        $responseEvent = $this->remarketingResponseEventService->createNeedsReviewEvent([
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
        ]);

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

    private function latestFlowStartedAt(int $vicidialLeadId): ?Carbon
    {
        $table = (string) config('whatsapp_detector.remarketing_tasks_table');
        $timeCol = (string) config('whatsapp_detector.remarketing_task_time_column');
        if ($table === '' || $timeCol === '') {
            return null;
        }

        $raw = DB::connection('mysql')->table($table)
            ->where('lead_id', $vicidialLeadId)
            ->where('task_type', 'flow_started')
            ->where('reason', 'flow_start')
            ->orderByDesc($timeCol)
            ->value($timeCol);

        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * When several Vicidial leads share the same phone, prefer the one whose latest
     * flow_start anchor in remarketing_tasks is most recent (mysql; task_type/reason as in latestFlowStartedAt).
     *
     * @param  list<int>  $leadIds
     * @return array{
     *   matchedLeadId: ?int,
     *   resolvedFlowStartedAt: ?Carbon,
     *   resolutionStatus: 'resolved'|'ambiguous_no_flow_start'|'ambiguous_tie',
     *   resolutionNote: ?string,
     * }
     */
    private function resolveLeadIdFromCandidatesByLatestFlowStart(array $leadIds): array
    {
        $byLead = [];
        foreach ($leadIds as $id) {
            $id = (int) $id;
            $byLead[$id] = $this->latestFlowStartedAt($id);
        }

        $withFlow = array_filter($byLead, fn (?Carbon $v) => $v !== null);
        $idsStr = implode(',', $leadIds);

        if ($withFlow === []) {
            return [
                'matchedLeadId' => null,
                'resolvedFlowStartedAt' => null,
                'resolutionStatus' => 'ambiguous_no_flow_start',
                'resolutionNote' => "Multiple leads matched phone ({$idsStr}); none had a flow_start anchor in remarketing_tasks.",
            ];
        }

        $maxAt = null;
        foreach ($withFlow as $at) {
            if ($maxAt === null || $at->gt($maxAt)) {
                $maxAt = $at;
            }
        }

        $idsAtMax = [];
        foreach ($withFlow as $leadId => $at) {
            if ($maxAt !== null && $at->eq($maxAt)) {
                $idsAtMax[] = $leadId;
            }
        }

        if (count($idsAtMax) > 1) {
            $tieStr = implode(',', $idsAtMax);

            return [
                'matchedLeadId' => null,
                'resolvedFlowStartedAt' => null,
                'resolutionStatus' => 'ambiguous_tie',
                'resolutionNote' => "Multiple leads matched phone ({$idsStr}); tie on latest flow_start among candidates (lead_ids: {$tieStr}).",
            ];
        }

        $winnerId = $idsAtMax[0];

        return [
            'matchedLeadId' => $winnerId,
            'resolvedFlowStartedAt' => $byLead[$winnerId],
            'resolutionStatus' => 'resolved',
            'resolutionNote' => "Multiple leads matched phone ({$idsStr}); resolved to lead {$winnerId} using latest flow_start among candidates.",
        ];
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
