<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemarketingScanCbnaCommand extends Command
{
    private const TARGET_LIST_ID = '5555555555';

    private const TARGET_STATUS = 'HOLD';

    protected $signature = 'remarketing:scan-cbna
        {--commit : Persist writes (default is dry-run)}
        {--limit=100 : Max VICIdial CBNA rows to scan}
        {--lead_id= : Optional single VICIdial lead_id}
        {--json : Output JSON rows instead of line-by-line summary}';

    protected $description = 'Safely scan VICIdial CBNA leads, set Lost Contact, and initialize linear remarketing progress when needed (dry-run by default).';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $dryRun = ! $commit;
        $asJson = (bool) $this->option('json');
        $limit = max(1, (int) $this->option('limit'));
        $leadIdFilter = $this->option('lead_id');

        $rows = [];
        $candidates = $this->getCandidates($limit, $leadIdFilter);
        $summary = [
            'mode' => $dryRun ? 'dry-run' : 'commit',
            'found_cbna_count' => $candidates->count(),
            'processed' => 0,
            'created_jinx_lead' => 0,
            'matched_existing_jinx_lead' => 0,
            'updated_status_to_lost_contact' => 0,
            'remarketing_started_directly' => 0,
            'remarketing_deferred_to_lost_contact_trigger' => 0,
            'vicidial_moved_to_HOLD_list_5555555555' => 0,
            'vicidial_would_move_to_HOLD_list_5555555555' => 0,
            'errors' => 0,
        ];

        foreach ($candidates as $candidate) {
            $summary['processed']++;
            $rows[] = $this->processCandidate($candidate, $dryRun, $summary);
        }

        $payload = [
            'summary' => $summary,
            'rows' => $rows,
            'checklist' => $this->testingChecklist(),
        ];

        if ($asJson) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Mode: '.$summary['mode']);
        $this->line('Found CBNA count: '.$summary['found_cbna_count']);
        foreach ($rows as $row) {
            $this->line(str_repeat('-', 80));
            $this->line('VICIdial lead_id: '.$row['vicidial_lead_id']);
            $this->line('Result: '.$row['state']);
            $this->line('created_jinx_lead: '.($row['created_jinx_lead'] ? 'true' : 'false'));
            $this->line('matched_existing_jinx_lead: '.($row['matched_existing_jinx_lead'] ? 'true' : 'false'));
            $this->line('updated_status_to_lost_contact: '.($row['updated_status_to_lost_contact'] ? 'true' : 'false'));
            $this->line('remarketing_started_directly: '.($row['remarketing_started_directly'] ? 'true' : 'false'));
            $this->line('remarketing_deferred_to_lost_contact_trigger: '.($row['remarketing_deferred_to_lost_contact_trigger'] ? 'true' : 'false'));
            $this->line('vicidial_moved_to_HOLD_list_5555555555: '.($row['vicidial_moved_to_HOLD_list_5555555555'] ? 'true' : 'false'));
            if ($row['note'] !== null) {
                $this->line('Note: '.$row['note']);
            }
            if ($row['error'] !== null) {
                $this->error('Error: '.$row['error']);
            }
        }

        $this->newLine();
        foreach (array_diff_key($summary, array_flip(['mode', 'found_cbna_count'])) as $key => $value) {
            $this->line($key.': '.$value);
        }

        $this->newLine();
        $this->line('Testing checklist:');
        foreach ($this->testingChecklist() as $item) {
            $this->line('- '.$item);
        }

        return self::SUCCESS;
    }

    private function processCandidate(object $candidate, bool $dryRun, array &$summary): array
    {
        $vicidialLeadId = (int) ($candidate->lead_id ?? 0);
        $row = [
            'vicidial_lead_id' => $vicidialLeadId,
            'state' => 'unknown',
            'created_jinx_lead' => false,
            'matched_existing_jinx_lead' => false,
            'updated_status_to_lost_contact' => false,
            'remarketing_started_directly' => false,
            'remarketing_deferred_to_lost_contact_trigger' => false,
            'vicidial_moved_to_HOLD_list_5555555555' => false,
            'note' => null,
            'error' => null,
            'source_phone' => trim((string) ($candidate->phone_number ?? '')) ?: null,
            'stored_phone' => null,
        ];

        try {
            $sourcePhone = trim((string) ($candidate->phone_number ?? ''));
            $normalizedPhone = $this->normalizeUkTo44Digits($sourcePhone);
            [$lead, $matchedByPhone] = $this->findExistingLead($vicidialLeadId, $normalizedPhone);
            $hadProgress = $this->hasProgressForVicidialLeadId($vicidialLeadId);
            $row['stored_phone'] = trim((string) ($lead?->phone_number ?? '')) ?: null;

            if ($lead === null) {
                $summary['created_jinx_lead']++;
                $row['created_jinx_lead'] = true;
            } else {
                $summary['matched_existing_jinx_lead']++;
                $row['matched_existing_jinx_lead'] = true;
            }

            if ($dryRun) {
                $summary['updated_status_to_lost_contact']++;
                $row['updated_status_to_lost_contact'] = true;

                if (! $hadProgress) {
                    $summary['remarketing_started_directly']++;
                    $row['remarketing_started_directly'] = true;
                }

                $summary['vicidial_would_move_to_HOLD_list_5555555555']++;
                $row['vicidial_moved_to_HOLD_list_5555555555'] = true;
                $row['state'] = 'would_apply';
                $row['note'] = $matchedByPhone ? 'matched_by_phone_would_backfill_vicidial_lead_id_if_safe' : null;

                return $row;
            }

            if ($lead === null) {
                $lead = Lead::query()->create($this->buildLeadPayload($candidate, $vicidialLeadId, false, null));
            } else {
                if ((string) $lead->wip_status === Lead::WIP_STATUS_DEAD) {
                    $row['state'] = 'skipped_dead_lead';
                    $row['note'] = 'existing_jinx_lead_dead_no_lost_contact_or_progress_reset';

                    return $row;
                }
                $lead->update($this->buildLeadPayload($candidate, $vicidialLeadId, true, $lead));
            }
            $row['stored_phone'] = trim((string) ($lead->fresh()?->phone_number ?? $lead->phone_number ?? '')) ?: null;

            $summary['updated_status_to_lost_contact']++;
            $row['updated_status_to_lost_contact'] = true;

            if (! $hadProgress && ! $this->hasProgressForVicidialLeadId($vicidialLeadId)) {
                LeadRemarketingProgress::query()->create($this->buildProgressPayload($vicidialLeadId));
                $summary['remarketing_started_directly']++;
                $row['remarketing_started_directly'] = true;
            }

            $moved = $this->moveVicidialRow($vicidialLeadId);
            if ($moved) {
                $summary['vicidial_moved_to_HOLD_list_5555555555']++;
                $row['vicidial_moved_to_HOLD_list_5555555555'] = true;
            }

            $row['state'] = 'applied';
            $row['note'] = $matchedByPhone ? 'matched_by_phone_backfilled_vicidial_lead_id_if_safe' : null;

            return $row;
        } catch (\Throwable $e) {
            $summary['errors']++;
            $row['state'] = 'error';
            $row['error'] = $e->getMessage();

            return $row;
        }
    }

    private function getCandidates(int $limit, mixed $leadIdFilter)
    {
        $columns = ['lead_id', 'status'];
        foreach (['phone_number', 'first_name', 'last_name', 'email', 'email_address'] as $column) {
            if (Schema::connection('asterisk')->hasColumn('vicidial_list', $column)) {
                $columns[] = $column;
            }
        }

        $query = DB::connection('asterisk')
            ->table('vicidial_list')
            ->select($columns)
            ->where('status', 'CBNA')
            ->orderBy('lead_id')
            ->limit($limit);

        if ($leadIdFilter !== null && $leadIdFilter !== '') {
            $query->where('lead_id', (int) $leadIdFilter);
        }

        return $query->get();
    }

    private function hasProgressForVicidialLeadId(int $vicidialLeadId): bool
    {
        return LeadRemarketingProgress::query()->where('lead_id', $vicidialLeadId)->exists();
    }

    private function findExistingLead(int $vicidialLeadId, ?string $phone44Digits): array
    {
        $lead = Lead::query()->where('vicidial_lead_id', $vicidialLeadId)->first();
        if ($lead !== null) {
            return [$lead, false];
        }

        if ($phone44Digits !== null) {
            $byPhone = Lead::query()
                ->whereNotNull('phone_number')
                ->get(['id', 'phone_number'])
                ->first(fn (Lead $row): bool => $this->normalizeUkTo44Digits((string) $row->phone_number) === $phone44Digits);

            if ($byPhone !== null) {
                return [Lead::query()->find($byPhone->id), true];
            }
        }

        return [null, false];
    }

    private function buildLeadPayload(object $candidate, int $vicidialLeadId, bool $updateOnly, ?Lead $existingLead): array
    {
        $payload = ['wip_status' => 'Lost Contact'];

        if (! $updateOnly || $existingLead?->vicidial_lead_id === null || $existingLead?->vicidial_lead_id === '') {
            $payload['vicidial_lead_id'] = (string) $vicidialLeadId;
        }

        $sourcePhone = trim((string) ($candidate->phone_number ?? ''));
        $firstName = trim((string) ($candidate->first_name ?? ''));
        $lastName = trim((string) ($candidate->last_name ?? ''));
        $email = $this->extractCandidateEmail($candidate);

        if ($sourcePhone !== '') {
            $payload['phone_number'] = $sourcePhone;
        }
        if ($firstName !== '') {
            $payload['first_name'] = $firstName;
        }
        if ($lastName !== '') {
            $payload['last_name'] = $lastName;
        }
        if ($email !== null) {
            $payload['email'] = $email;
        }

        return $payload;
    }

    private function buildProgressPayload(int $vicidialLeadId): array
    {
        $now = Carbon::now();

        return [
            'lead_id' => $vicidialLeadId,
            'current_step_id' => null,
            'current_step_order' => null,
            'status' => 'active',
            'started_at' => $now,
            'last_step_completed_at' => null,
            'next_step_due_at' => $now,
            'stopped_at' => null,
            'stop_reason' => null,
            'stop_context_json' => null,
        ];
    }

    private function moveVicidialRow(int $vicidialLeadId): bool
    {
        $payload = ['status' => self::TARGET_STATUS];
        if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'list_id')) {
            $payload['list_id'] = self::TARGET_LIST_ID;
        }

        $updated = DB::connection('asterisk')
            ->table('vicidial_list')
            ->where('lead_id', $vicidialLeadId)
            ->where('status', 'CBNA')
            ->update($payload);

        return $updated > 0;
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

    private function extractCandidateEmail(object $candidate): ?string
    {
        $raw = trim((string) ($candidate->email ?? $candidate->email_address ?? ''));

        return filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : null;
    }

    private function testingChecklist(): array
    {
        return [
            'Run dry-run first (no --commit) and verify Lost Contact + move flags.',
            'Run with --lead_id=<id> to validate one lead safely.',
            'Run with --commit and confirm VICIdial row moved to HOLD/list 5555555555.',
            'Run command again for same lead and verify no duplicate active progress is created.',
            'Run remarketing:linear-execute separately to process due steps.',
        ];
    }
}
