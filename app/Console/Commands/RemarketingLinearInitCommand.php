<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingProgress;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemarketingLinearInitCommand extends Command
{
    protected $signature = 'remarketing:linear-init
        {--lead_id= : Optional single vicidial lead_id}
        {--limit=100 : Max number of leads to process}
        {--dry-run : Do not write anything, only preview}
        {--json : Output JSON instead of human report}';

    protected $description = 'Initialize leads into lead_remarketing_progress for the new linear brain.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $asJson = (bool) $this->option('json');
        $limit = max(1, (int) $this->option('limit'));

        $candidateLeads = $this->getCandidateLeads(
            leadId: $this->option('lead_id'),
            limit: $limit
        );

        $rows = [];
        $summary = [
            'checked' => 0,
            'eligible' => 0,
            'created' => 0,
            'skipped_existing' => 0,
        ];

        foreach ($candidateLeads as $candidateLead) {
            $leadId = (int) $candidateLead->lead_id;
            $summary['checked']++;

            if ($this->progressExists($leadId)) {
                $summary['skipped_existing']++;
                $rows[] = [
                    'lead_id' => $leadId,
                    'status' => 'skipped_existing',
                    'action' => 'skipped',
                    'reason' => 'already_exists',
                ];
                continue;
            }

            $summary['eligible']++;
            $payload = $this->buildProgressPayload($leadId);

            if ($dryRun) {
                $rows[] = [
                    'lead_id' => $leadId,
                    'status' => 'eligible',
                    'action' => 'would_create',
                    'reason' => 'new_entry',
                ];
                continue;
            }

            LeadRemarketingProgress::query()->create($payload);
            $summary['created']++;
            $rows[] = [
                'lead_id' => $leadId,
                'status' => 'eligible',
                'action' => 'created',
                'reason' => 'new_entry',
            ];
        }

        if ($asJson) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $this->line('Lead ID: '.$row['lead_id']);
                $this->line('Status: '.$row['status']);
                $this->line('Action: '.$row['action']);
                $this->line('Reason: '.$row['reason']);
                $this->line(str_repeat('-', 50));
            }
        }

        $this->printSummary($summary);

        return self::SUCCESS;
    }

    protected function getCandidateLeads(mixed $leadId, int $limit)
    {
        $query = DB::connection('asterisk')
            ->table('vicidial_list')
            ->select(['lead_id', 'status'])
            ->whereNotIn('status', ['SALE', 'DNQ'])
            ->orderBy('lead_id')
            ->limit($limit);

        if ($leadId !== null && $leadId !== '') {
            $query->where('lead_id', (int) $leadId);
        }

        return $query->get();
    }

    protected function progressExists(int $leadId): bool
    {
        return LeadRemarketingProgress::query()
            ->where('lead_id', $leadId)
            ->exists();
    }

    protected function buildProgressPayload(int $leadId): array
    {
        $now = Carbon::now();

        return [
            'lead_id' => $leadId,
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

    protected function printSummary(array $summary): void
    {
        $this->newLine();
        $this->line('Total checked: '.$summary['checked']);
        $this->line('Eligible: '.$summary['eligible']);
        $this->line('Created: '.$summary['created']);
        $this->line('Skipped existing: '.$summary['skipped_existing']);
    }
}
