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

    protected $description = 'Safely scan VICIdial CBNA leads and initialize linear remarketing progress (dry-run by default).';

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
            'created_lead' => 0,
            'matched_existing_lead' => 0,
            'remarketing_started' => 0,
            'remarketing_already_active' => 0,
            'vicidial_moved' => 0,
            'vicidial_would_move' => 0,
            'errors' => 0,
        ];

        foreach ($candidates as $candidate) {
            $summary['processed']++;
            $rows[] = $this->processCandidate($candidate, $dryRun, $summary);
        }

        if ($asJson) {
            $this->line(json_encode([
                'summary' => $summary,
                'rows' => $rows,
                'checklist' => $this->testingChecklist(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Mode: '.$summary['mode']);
        $this->line('Found CBNA count: '.$summary['found_cbna_count']);
        foreach ($rows as $row) {
            $this->line(str_repeat('-', 80));
            $this->line('VICIdial lead_id: '.$row['vicidial_lead_id']);
            $this->line('Result: '.$row['state']);
            $this->line('Lead action: '.$row['lead_action']);
            $this->line('Remarketing action: '.$row['remarketing_action']);
            $this->line('VICIdial move action: '.$row['vicidial_move_action']);
            if ($row['note'] !== null) {
                $this->line('Note: '.$row['note']);
            }
            if ($row['error'] !== null) {
                $this->error('Error: '.$row['error']);
            }
        }

        $this->newLine();
        $this->line('Processed: '.$summary['processed']);
        $this->line('Created lead: '.$summary['created_lead']);
        $this->line('Matched existing lead: '.$summary['matched_existing_lead']);
        $this->line('Remarketing started: '.$summary['remarketing_started']);
        $this->line('Remarketing already active/stopped/completed: '.$summary['remarketing_already_active']);
        $this->line('VICIdial moved to HOLD/list 5555555555: '.$summary['vicidial_moved']);
        $this->line('VICIdial would move (dry-run): '.$summary['vicidial_would_move']);
        if ($summary['errors'] > 0) {
            $this->warn('Errors: '.$summary['errors']);
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
            'lead_action' => 'none',
            'remarketing_action' => 'none',
            'vicidial_move_action' => 'none',
            'note' => null,
            'error' => null,
        ];

        try {
            $phone = $this->normalizeUkTo44Digits((string) ($candidate->phone_number ?? ''));
            $lead = $this->findExistingLead($vicidialLeadId, $phone);
            $hadProgress = $this->hasProgressForVicidialLeadId($vicidialLeadId);

            if ($lead === null) {
                $summary['created_lead']++;
                $row['lead_action'] = $dryRun ? 'would_create' : 'created';
            } else {
                $summary['matched_existing_lead']++;
                $row['lead_action'] = 'matched_existing#'.$lead->id;
            }

            if ($hadProgress) {
                $summary['remarketing_already_active']++;
                $row['state'] = $dryRun ? 'would_already_progress_but_move' : 'already_progress_but_moved';
                $row['remarketing_action'] = 'matched_existing_with_progress';

                if ($dryRun) {
                    $summary['vicidial_would_move']++;
                    $row['vicidial_move_action'] = 'would_move_to_HOLD_list_5555555555';
                    return $row;
                }

                $moved = $this->moveVicidialRow($vicidialLeadId);
                if ($moved) {
                    $summary['vicidial_moved']++;
                    $row['vicidial_move_action'] = 'moved_to_HOLD_list_5555555555';
                } else {
                    $row['vicidial_move_action'] = 'not_moved_row_changed_or_missing';
                }

                return $row;
            }

            if ($dryRun) {
                $summary['remarketing_started']++;
                $summary['vicidial_would_move']++;
                $row['state'] = $lead === null ? 'created_and_started' : 'updated_and_started';
                $row['remarketing_action'] = 'would_start';
                $row['vicidial_move_action'] = 'would_move_to_HOLD_list_5555555555';
                return $row;
            }

            if ($lead === null) {
                $lead = Lead::query()->create($this->buildLeadPayload($candidate, $vicidialLeadId, false));
                $row['lead_action'] = 'created#'.$lead->id;
            } else {
                $lead->update($this->buildLeadPayload($candidate, $vicidialLeadId, true));
                $row['lead_action'] = 'updated#'.$lead->id;
            }

            if (! $this->hasProgressForVicidialLeadId($vicidialLeadId)) {
                LeadRemarketingProgress::query()->create($this->buildProgressPayload($vicidialLeadId));
            }

            $summary['remarketing_started']++;
            $moved = $this->moveVicidialRow($vicidialLeadId);
            if ($moved) {
                $summary['vicidial_moved']++;
                $row['vicidial_move_action'] = 'moved_to_HOLD_list_5555555555';
            } else {
                $row['vicidial_move_action'] = 'not_moved_row_changed_or_missing';
            }

            $row['state'] = $row['lead_action'] !== '' && str_starts_with($row['lead_action'], 'created#')
                ? 'created_and_started'
                : 'updated_and_started';
            $row['remarketing_action'] = 'started';

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

    private function findExistingLead(int $vicidialLeadId, ?string $phone44Digits): ?Lead
    {
        $lead = Lead::query()->where('vicidial_lead_id', $vicidialLeadId)->first();
        if ($lead !== null) {
            return $lead;
        }

        if ($phone44Digits !== null) {
            $byPhone = Lead::query()
                ->whereNotNull('phone_number')
                ->get(['id', 'phone_number'])
                ->first(fn (Lead $row): bool => $this->normalizeUkTo44Digits((string) $row->phone_number) === $phone44Digits);

            if ($byPhone !== null) {
                return Lead::query()->find($byPhone->id);
            }
        }

        return null;
    }

    private function buildLeadPayload(object $candidate, int $vicidialLeadId, bool $updateOnly): array
    {
        $payload = [
            'vicidial_lead_id' => (string) $vicidialLeadId,
        ];

        $phone44Digits = $this->normalizeUkTo44Digits((string) ($candidate->phone_number ?? ''));
        $firstName = trim((string) ($candidate->first_name ?? ''));
        $lastName = trim((string) ($candidate->last_name ?? ''));
        $email = $this->extractCandidateEmail($candidate);

        if (! $updateOnly || $phone44Digits !== null) {
            $payload['phone_number'] = $phone44Digits;
        }
        if (! $updateOnly || $firstName !== '') {
            $payload['first_name'] = $firstName !== '' ? $firstName : null;
        }
        if (! $updateOnly || $lastName !== '') {
            $payload['last_name'] = $lastName !== '' ? $lastName : null;
        }
        if (! $updateOnly || $email !== null) {
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
            'Run dry-run first (no --commit) and verify found/match/start counts.',
            'Run with --lead_id=<id> to validate one lead safely.',
            'Run with --commit and confirm VICIdial row moved to HOLD/list 5555555555.',
            'Run command again for same lead and verify no duplicate active progress is created.',
            'Run remarketing:linear-execute separately to send due steps.',
        ];
    }
}
