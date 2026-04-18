<?php

namespace App\Services;

use App\Models\WhatsAppDetectorEvent;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class WhatsAppDetectorEventIngestor
{
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
                    WhatsAppDetectorEvent::query()->create($row);
                    $stats['imported']++;
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
}
