<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingResponseEvent;
use App\Services\RemarketingResponseEventService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RemarketingInboundCallResponseScanCommand extends Command
{
    protected $signature = 'remarketing:scan-inbound-call-responses
        {--limit=100 : Max number of progress rows to scan}
        {--dry-run : Detect only, do not create response events}';

    protected $description = 'Scan VICIdial inbound call_log for inbound responses after linear flow start.';

    public function __construct(
        private readonly RemarketingResponseEventService $responseEventService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $summary = [
            'scanned_progress_rows' => 0,
            'call_candidates_found' => 0,
            'events_created' => 0,
            'duplicates_skipped' => 0,
            'skipped_no_lead' => 0,
            'skipped_ineligible' => 0,
        ];

        $progressRows = LeadRemarketingProgress::query()
            ->whereNotNull('lead_id')
            ->whereNull('stopped_at')
            ->whereNotIn('status', ['completed', 'stopped'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($progressRows as $progress) {
            $summary['scanned_progress_rows']++;

            $vicidialLeadId = (int) $progress->lead_id;
            $lead = Lead::query()
                ->where('vicidial_lead_id', $vicidialLeadId)
                ->first();

            if (! $lead) {
                $summary['skipped_no_lead']++;
                Log::info('[remarketing-inbound-call-response-scan] skipped no lead', [
                    'progress_id' => $progress->id,
                    'lead_id' => $progress->lead_id,
                    'vicidial_lead_id' => $vicidialLeadId,
                ]);
                continue;
            }

            $flowStartAt = $progress->started_at ?? $progress->created_at;
            if (! $flowStartAt) {
                continue;
            }

            $leadPhone44 = $this->normalizeUkTo44Digits((string) ($lead->phone_number ?? ''));
            if ($leadPhone44 === null) {
                continue;
            }

            $callCandidates = DB::connection('asterisk')
                ->table('call_log')
                ->select(['channel_group', 'number_dialled', 'caller_code', 'start_time', 'uniqueid'])
                ->where('channel_group', 'DID_INBOUND')
                ->whereNotNull('uniqueid')
                ->where('start_time', '>=', $flowStartAt)
                ->whereRaw($this->digitsOnlySql('caller_code').' = ?', [$leadPhone44])
                ->orderBy('start_time')
                ->get();

            foreach ($callCandidates as $call) {
                $summary['call_candidates_found']++;

                $uniqueId = trim((string) ($call->uniqueid ?? ''));
                if ($uniqueId === '') {
                    continue;
                }

                $sourceEventId = 'call_log:'.$uniqueId;
                $dedupeKey = 'call:'.$sourceEventId;

                $duplicateExists = RemarketingResponseEvent::query()
                    ->where('dedupe_key', $dedupeKey)
                    ->exists();

                if ($duplicateExists) {
                    $summary['duplicates_skipped']++;
                    Log::info('[remarketing-inbound-call-response-scan] duplicate ignored', [
                        'progress_id' => $progress->id,
                        'lead_id' => $progress->lead_id,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'caller_code' => $call->caller_code,
                        'number_dialled' => $call->number_dialled,
                        'start_time' => (string) $call->start_time,
                        'uniqueid' => $uniqueId,
                        'source_event_id' => $sourceEventId,
                    ]);
                    continue;
                }

                if ($dryRun) {
                    $this->line('DRY-RUN candidate: lead '.$vicidialLeadId.' uniqueid '.$uniqueId);
                    continue;
                }

                $event = $this->responseEventService->createNeedsReviewEventIfEligible([
                    'lead_id' => $vicidialLeadId,
                    'jinx_lead_id' => $lead->id,
                    'remarketing_progress_id' => $progress->id,
                    'source_event_id' => $sourceEventId,
                    'dedupe_key' => $dedupeKey,
                    'channel' => 'call',
                    'direction' => 'inbound',
                    'status' => RemarketingResponseEvent::STATUS_NEEDS_REVIEW,
                    'matched_phone' => (string) ($call->caller_code ?? ''),
                    'matched_email' => null,
                    'message_preview' => 'Inbound call detected after remarketing flow started',
                    'raw_payload_json' => [
                        'source' => 'vicidial_call_log',
                        'channel_group' => $call->channel_group,
                        'number_dialled' => $call->number_dialled,
                        'caller_code' => $call->caller_code,
                        'start_time' => (string) $call->start_time,
                        'uniqueid' => $uniqueId,
                    ],
                    'detected_at' => $this->normalizeDetectedAt((string) $call->start_time),
                ], $lead);

                if ($event === null) {
                    $summary['skipped_ineligible']++;
                    Log::info('[remarketing-inbound-call-response-scan] skipped lead not eligible', [
                        'progress_id' => $progress->id,
                        'lead_id' => $progress->lead_id,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'caller_code' => $call->caller_code,
                        'number_dialled' => $call->number_dialled,
                        'start_time' => (string) $call->start_time,
                        'uniqueid' => $uniqueId,
                        'source_event_id' => $sourceEventId,
                    ]);
                    continue;
                }

                if (! $event->wasRecentlyCreated) {
                    $summary['duplicates_skipped']++;
                    Log::info('[remarketing-inbound-call-response-scan] duplicate ignored', [
                        'progress_id' => $progress->id,
                        'lead_id' => $progress->lead_id,
                        'vicidial_lead_id' => $vicidialLeadId,
                        'caller_code' => $call->caller_code,
                        'number_dialled' => $call->number_dialled,
                        'start_time' => (string) $call->start_time,
                        'uniqueid' => $uniqueId,
                        'source_event_id' => $sourceEventId,
                    ]);
                    continue;
                }

                $summary['events_created']++;
                Log::info('[remarketing-inbound-call-response-scan] response event created', [
                    'progress_id' => $progress->id,
                    'lead_id' => $progress->lead_id,
                    'vicidial_lead_id' => $vicidialLeadId,
                    'caller_code' => $call->caller_code,
                    'number_dialled' => $call->number_dialled,
                    'start_time' => (string) $call->start_time,
                    'uniqueid' => $uniqueId,
                    'source_event_id' => $sourceEventId,
                ]);
            }
        }

        Log::info('[remarketing-inbound-call-response-scan] scan complete', $summary);

        $this->line('Scanned progress rows: '.$summary['scanned_progress_rows']);
        $this->line('Call candidates found: '.$summary['call_candidates_found']);
        $this->line('Events created: '.$summary['events_created']);
        $this->line('Duplicates skipped: '.$summary['duplicates_skipped']);
        $this->line('Skipped no lead: '.$summary['skipped_no_lead']);
        $this->line('Skipped ineligible: '.$summary['skipped_ineligible']);

        return self::SUCCESS;
    }

    private function normalizeUkTo44Digits(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '44') && strlen($digits) >= 12) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '44'.substr($digits, 1);
        }

        if (str_starts_with($digits, '7') && strlen($digits) === 10) {
            return '44'.$digits;
        }

        return null;
    }

    private function normalizeDetectedAt(string $startTime): Carbon
    {
        $trimmed = trim($startTime);
        if ($trimmed === '') {
            return now();
        }

        try {
            return Carbon::parse($trimmed);
        } catch (\Throwable) {
            return now();
        }
    }

    private function digitsOnlySql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column},''),' ',''),'-',''),'+',''),'(',''),')',''),'.','')";
    }
}
