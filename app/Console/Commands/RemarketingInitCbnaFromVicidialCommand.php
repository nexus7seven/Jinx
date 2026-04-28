<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingStep;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemarketingInitCbnaFromVicidialCommand extends Command
{
    protected $signature = 'remarketing:init-cbna
        {--dry-run}
        {--limit=100}
        {--move-list-id=5555555555}
        {--new-vicidial-status=HOLD}';

    protected $description = 'Initialize CBNA leads from VICIdial into remarketing progress.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $moveListId = trim((string) $this->option('move-list-id'));
        $newVicidialStatus = trim((string) $this->option('new-vicidial-status'));
        $now = Carbon::now();

        if ($newVicidialStatus === '') {
            $this->error('Option --new-vicidial-status cannot be empty.');

            return self::FAILURE;
        }

        $preflightError = $this->validateVicidialMoveTarget($moveListId);
        if ($preflightError !== null) {
            $this->error($preflightError);

            return self::FAILURE;
        }

        $firstCbnaStep = RemarketingStep::query()
            ->where('step_key', 'cbna_sms_instant')
            ->first();

        if ($firstCbnaStep === null) {
            $this->error('Missing required CBNA step: cbna_sms_instant');

            return self::FAILURE;
        }

        $candidates = $this->getCbnaCandidates($limit);
        if ($candidates->isEmpty()) {
            $this->line('No CBNA VICIdial leads found for initialization.');

            return self::SUCCESS;
        }

        $summary = [
            'checked' => 0,
            'initialized' => 0,
            'skipped_existing' => 0,
            'would_initialize' => 0,
            'would_create_lead' => 0,
            'vicidial_moved' => 0,
            'would_move_vicidial' => 0,
        ];

        foreach ($candidates as $candidate) {
            $summary['checked']++;
            $vicidialLeadId = (int) ($candidate->lead_id ?? 0);
            $phone = $this->normalizePhone((string) ($candidate->phone_number ?? ''));
            $firstName = trim((string) ($candidate->first_name ?? ''));
            $lastName = trim((string) ($candidate->last_name ?? ''));
            $email = $this->extractCandidateEmail($candidate);

            $lead = $this->findExistingLead($vicidialLeadId, $phone);
            $isNewLead = $lead === null;
            if ($isNewLead && $dryRun) {
                $summary['would_create_lead']++;
            }

            if ($lead !== null && $this->hasActiveOrPendingProgress((int) $lead->id)) {
                $summary['skipped_existing']++;
                $this->line('[CBNA INIT] Lead '.(int) $lead->id.' skipped (existing active/pending progress)');
                continue;
            }

            if ($dryRun) {
                $summary['would_initialize']++;
                $this->line('[CBNA INIT] Lead '.($lead?->id ?? 'new(vicidial '.$vicidialLeadId.')').' would initialize -> step 1');
                $summary['would_move_vicidial']++;
                $this->line('[CBNA INIT] Would move VICIdial lead_id '.$vicidialLeadId.' to status '.$newVicidialStatus.' list_id '.$moveListId);
                continue;
            }

            $initializedInRun = DB::transaction(function () use (
                &$lead,
                $isNewLead,
                $vicidialLeadId,
                $phone,
                $firstName,
                $lastName,
                $email,
                $firstCbnaStep,
                $now
            ): bool {
                if ($isNewLead) {
                    $lead = Lead::query()->create($this->buildNewLeadPayload(
                        vicidialLeadId: $vicidialLeadId,
                        phone: $phone,
                        firstName: $firstName,
                        lastName: $lastName,
                        email: $email
                    ));
                } else {
                    $lead->update($this->buildLeadUpdatePayload(
                        vicidialLeadId: $vicidialLeadId,
                        phone: $phone,
                        firstName: $firstName,
                        lastName: $lastName,
                        email: $email
                    ));
                }

                if (Schema::hasColumn('leads', 'wip_status')) {
                    $lead->wip_status = 'Lost Contact';
                    $lead->save();
                }

                if ($this->hasActiveOrPendingProgress((int) $lead->id)) {
                    return false;
                }

                LeadRemarketingProgress::query()->create($this->buildProgressPayload(
                    leadId: (int) $lead->id,
                    firstStepId: (int) $firstCbnaStep->id,
                    now: $now
                ));

                return true;
            });

            if ($initializedInRun && $lead !== null) {
                $summary['initialized']++;
                $this->line('[CBNA INIT] Lead '.(int) $lead->id.' initialized -> step 1');
                $moved = $this->moveVicidialLeadAfterInit(
                    vicidialLeadId: $vicidialLeadId,
                    newStatus: $newVicidialStatus,
                    moveListId: $moveListId
                );
                if ($moved) {
                    $summary['vicidial_moved']++;
                    $this->line('[CBNA INIT] VICIdial lead_id '.$vicidialLeadId.' moved to status '.$newVicidialStatus.' list_id '.$moveListId);
                } else {
                    $this->warn('[CBNA INIT] VICIdial lead_id '.$vicidialLeadId.' not moved (row not in CBNA state).');
                }
            } else {
                $summary['skipped_existing']++;
                $this->line('[CBNA INIT] Lead '.($lead?->id ?? 'unknown').' skipped (existing active/pending progress)');
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->line('Dry-run mode: no writes were performed.');
        }
        $this->line('Checked: '.$summary['checked']);
        if ($dryRun) {
            $this->line('Would create lead: '.$summary['would_create_lead']);
            $this->line('Would initialize: '.$summary['would_initialize']);
            $this->line('Would move VICIdial: '.$summary['would_move_vicidial']);
        } else {
            $this->line('Initialized: '.$summary['initialized']);
            $this->line('VICIdial moved: '.$summary['vicidial_moved']);
        }
        $this->line('Skipped existing: '.$summary['skipped_existing']);

        return self::SUCCESS;
    }

    private function getCbnaCandidates(int $limit)
    {
        $columns = ['lead_id', 'status'];
        foreach (['last_local_call_time', 'phone_number', 'first_name', 'last_name', 'email', 'email_address'] as $column) {
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

        if (in_array('last_local_call_time', $columns, true)) {
            $query->whereNotNull('last_local_call_time');
        }

        return $query->get();
    }

    private function findExistingLead(int $vicidialLeadId, ?string $phone): ?Lead
    {
        if (Schema::hasColumn('leads', 'vicidial_lead_id')) {
            $lead = Lead::query()
                ->where('vicidial_lead_id', $vicidialLeadId)
                ->first();
            if ($lead !== null) {
                return $lead;
            }
        }

        if ($phone !== null && Schema::hasColumn('leads', 'phone_number')) {
            return Lead::query()
                ->where('phone_number', $phone)
                ->first();
        }

        return null;
    }

    private function extractCandidateEmail(object $candidate): ?string
    {
        $raw = trim((string) ($candidate->email ?? $candidate->email_address ?? ''));
        if ($raw === '' || ! filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $raw;
    }

    private function normalizePhone(string $phone): ?string
    {
        $value = trim($phone);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function hasActiveOrPendingProgress(int $leadId): bool
    {
        return LeadRemarketingProgress::query()
            ->where('lead_id', $leadId)
            ->whereIn('status', ['active', 'pending'])
            ->exists();
    }

    private function buildNewLeadPayload(
        int $vicidialLeadId,
        ?string $phone,
        string $firstName,
        string $lastName,
        ?string $email
    ): array {
        $payload = [];

        if (Schema::hasColumn('leads', 'vicidial_lead_id')) {
            $payload['vicidial_lead_id'] = $vicidialLeadId;
        }
        if (Schema::hasColumn('leads', 'phone_number')) {
            $payload['phone_number'] = $phone;
        }
        if (Schema::hasColumn('leads', 'first_name')) {
            $payload['first_name'] = $firstName !== '' ? $firstName : null;
        }
        if (Schema::hasColumn('leads', 'last_name')) {
            $payload['last_name'] = $lastName !== '' ? $lastName : null;
        }
        if (Schema::hasColumn('leads', 'email')) {
            $payload['email'] = $email;
        }

        return $payload;
    }

    private function buildLeadUpdatePayload(
        int $vicidialLeadId,
        ?string $phone,
        string $firstName,
        string $lastName,
        ?string $email
    ): array {
        $payload = [];

        if (Schema::hasColumn('leads', 'vicidial_lead_id')) {
            $payload['vicidial_lead_id'] = $vicidialLeadId;
        }
        if ($phone !== null && Schema::hasColumn('leads', 'phone_number')) {
            $payload['phone_number'] = $phone;
        }
        if ($firstName !== '' && Schema::hasColumn('leads', 'first_name')) {
            $payload['first_name'] = $firstName;
        }
        if ($lastName !== '' && Schema::hasColumn('leads', 'last_name')) {
            $payload['last_name'] = $lastName;
        }
        if ($email !== null && Schema::hasColumn('leads', 'email')) {
            $payload['email'] = $email;
        }

        return $payload;
    }

    private function buildProgressPayload(int $leadId, int $firstStepId, Carbon $now): array
    {
        $payload = [
            'lead_id' => $leadId,
            'current_step_order' => 1,
            'status' => 'active',
        ];

        if (Schema::hasColumn('lead_remarketing_progress', 'current_step_id')) {
            $payload['current_step_id'] = $firstStepId;
        }
        if (Schema::hasColumn('lead_remarketing_progress', 'entered_step_at')) {
            $payload['entered_step_at'] = $now;
        }
        if (Schema::hasColumn('lead_remarketing_progress', 'next_due_at')) {
            $payload['next_due_at'] = $now;
        }
        if (Schema::hasColumn('lead_remarketing_progress', 'started_at')) {
            $payload['started_at'] = $now;
        }
        if (Schema::hasColumn('lead_remarketing_progress', 'next_step_due_at')) {
            $payload['next_step_due_at'] = $now;
        }

        return $payload;
    }

    private function moveVicidialLeadAfterInit(int $vicidialLeadId, string $newStatus, string $moveListId): bool
    {
        $updatePayload = ['status' => $newStatus];
        if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'list_id')) {
            $updatePayload['list_id'] = $moveListId;
        }

        $updated = DB::connection('asterisk')
            ->table('vicidial_list')
            ->where('lead_id', $vicidialLeadId)
            ->where('status', 'CBNA')
            ->update($updatePayload);

        return $updated > 0;
    }

    private function validateVicidialMoveTarget(string $moveListId): ?string
    {
        if (! Schema::connection('asterisk')->hasColumn('vicidial_list', 'list_id')) {
            return 'Preflight failed: vicidial_list.list_id column not found.';
        }

        $column = DB::connection('asterisk')
            ->select("SHOW COLUMNS FROM vicidial_list LIKE 'list_id'");

        $definition = $column[0] ?? null;
        $type = strtolower((string) ($definition->Type ?? $definition->type ?? ''));
        if ($type === '') {
            return 'Preflight failed: unable to inspect vicidial_list.list_id type.';
        }

        if (str_contains($type, 'int')) {
            if (! preg_match('/^\d+$/', $moveListId)) {
                return 'Preflight failed: --move-list-id must be numeric for vicidial_list.list_id type '.$type.'.';
            }

            $max = $this->maxValueForIntegerType($type);
            if ($max !== null && (float) $moveListId > $max) {
                return 'Preflight failed: --move-list-id '.$moveListId.' exceeds vicidial_list.list_id max '.$max.' for type '.$type.'.';
            }
        }

        return null;
    }

    private function maxValueForIntegerType(string $mysqlType): ?float
    {
        $unsigned = str_contains($mysqlType, 'unsigned');

        if (str_starts_with($mysqlType, 'tinyint')) {
            return $unsigned ? 255.0 : 127.0;
        }
        if (str_starts_with($mysqlType, 'smallint')) {
            return $unsigned ? 65535.0 : 32767.0;
        }
        if (str_starts_with($mysqlType, 'mediumint')) {
            return $unsigned ? 16777215.0 : 8388607.0;
        }
        if (str_starts_with($mysqlType, 'int')) {
            return $unsigned ? 4294967295.0 : 2147483647.0;
        }
        if (str_starts_with($mysqlType, 'bigint')) {
            return $unsigned ? 18446744073709551615.0 : 9223372036854775807.0;
        }

        return null;
    }
}
