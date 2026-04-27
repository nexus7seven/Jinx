<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingProgress;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemarketingLinearInitCommand extends Command
{
    protected $signature = 'remarketing:linear-init
        {--lead_id= : Optional single vicidial lead_id}
        {--status= : Optional comma-separated VICIdial statuses to include}
        {--campaign_id= : Optional comma-separated campaign IDs to include}
        {--list_id= : Optional comma-separated VICIdial list IDs to include}
        {--exclude-status= : Optional comma-separated VICIdial statuses to exclude}
        {--limit=100 : Max number of leads to process}
        {--dry-run : Do not write anything, only preview}
        {--json : Output JSON instead of human report}';

    protected $description = 'Initialize leads into lead_remarketing_progress for the new linear brain.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $asJson = (bool) $this->option('json');
        $limit = max(1, (int) $this->option('limit'));
        $leadId = $this->option('lead_id');
        $statusInclude = $this->parseCsvOption($this->option('status'));
        $campaignIds = $this->parseCsvOption($this->option('campaign_id'));
        $listIds = $this->parseCsvOption($this->option('list_id'));
        $statusExclude = $this->parseCsvOption($this->option('exclude-status'));
        if ($statusExclude === []) {
            $statusExclude = ['SALE', 'DNQ'];
        }

        $availableColumns = $this->getAvailableVicidialColumns();
        $hasCampaignId = in_array('campaign_id', $availableColumns, true);
        $hasListId = in_array('list_id', $availableColumns, true);

        if ($leadId === null && $statusInclude === [] && $campaignIds === [] && $listIds === []) {
            $this->warn('WARNING: No narrowing filters supplied. This may inspect broad vicidial_list rows. Use --dry-run first and prefer --status, --campaign_id, or --list_id.');
        }

        if ($campaignIds !== [] && ! $hasCampaignId) {
            $this->warn('campaign_id column not found on asterisk.vicidial_list. --campaign_id filter will be ignored.');
        }

        if ($listIds !== [] && ! $hasListId) {
            $this->warn('list_id column not found on asterisk.vicidial_list. --list_id filter will be ignored.');
        }

        $candidateLeads = $this->getCandidateLeads(
            leadId: $leadId,
            statusInclude: $statusInclude,
            statusExclude: $statusExclude,
            campaignIds: $campaignIds,
            listIds: $listIds,
            limit: $limit,
            hasCampaignId: $hasCampaignId,
            hasListId: $hasListId
        );

        if (! $asJson) {
            $this->printFilterSummary([
                'lead_id' => $leadId === null || $leadId === '' ? 'any' : (string) $leadId,
                'status_include' => $statusInclude === [] ? 'any' : implode(',', $statusInclude),
                'status_exclude' => $statusExclude === [] ? 'none' : implode(',', $statusExclude),
                'campaign_id' => $campaignIds === []
                    ? 'any'
                    : ($hasCampaignId ? implode(',', $campaignIds) : 'unavailable'),
                'list_id' => $listIds === []
                    ? 'any'
                    : ($hasListId ? implode(',', $listIds) : 'unavailable'),
                'limit' => (string) $limit,
                'dry_run' => $dryRun ? 'yes' : 'no',
            ]);
        }

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
                    'vicidial_status' => (string) $candidateLead->status,
                    'campaign_id' => property_exists($candidateLead, 'campaign_id') ? $candidateLead->campaign_id : null,
                    'list_id' => property_exists($candidateLead, 'list_id') ? $candidateLead->list_id : null,
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
                    'vicidial_status' => (string) $candidateLead->status,
                    'campaign_id' => property_exists($candidateLead, 'campaign_id') ? $candidateLead->campaign_id : null,
                    'list_id' => property_exists($candidateLead, 'list_id') ? $candidateLead->list_id : null,
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
                'vicidial_status' => (string) $candidateLead->status,
                'campaign_id' => property_exists($candidateLead, 'campaign_id') ? $candidateLead->campaign_id : null,
                'list_id' => property_exists($candidateLead, 'list_id') ? $candidateLead->list_id : null,
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
                $this->line('VICIdial status: '.$row['vicidial_status']);
                $this->line('Campaign ID: '.($row['campaign_id'] ?? 'unavailable'));
                $this->line('List ID: '.($row['list_id'] ?? 'unavailable'));
                $this->line('Status: '.$row['status']);
                $this->line('Action: '.$row['action']);
                $this->line('Reason: '.$row['reason']);
                $this->line(str_repeat('-', 50));
            }
        }

        $this->printSummary($summary);

        return self::SUCCESS;
    }

    protected function getCandidateLeads(
        mixed $leadId,
        array $statusInclude,
        array $statusExclude,
        array $campaignIds,
        array $listIds,
        int $limit,
        bool $hasCampaignId,
        bool $hasListId
    )
    {
        $selectColumns = ['lead_id', 'status'];
        if ($hasCampaignId) {
            $selectColumns[] = 'campaign_id';
        }
        if ($hasListId) {
            $selectColumns[] = 'list_id';
        }

        $query = DB::connection('asterisk')
            ->table('vicidial_list')
            ->select($selectColumns)
            ->orderBy('lead_id')
            ->limit($limit);

        if ($leadId !== null && $leadId !== '') {
            $query->where('lead_id', (int) $leadId);
        }

        if ($statusInclude !== []) {
            $query->whereIn('status', $statusInclude);
        } elseif ($statusExclude !== []) {
            $query->whereNotIn('status', $statusExclude);
        }

        if ($campaignIds !== [] && $hasCampaignId) {
            $query->whereIn('campaign_id', $campaignIds);
        }

        if ($listIds !== [] && $hasListId) {
            $query->whereIn('list_id', $listIds);
        }

        return $query->get();
    }

    protected function parseCsvOption(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = array_map('trim', explode(',', $value));
        $items = array_filter($items, static fn (string $item): bool => $item !== '');

        return array_values(array_unique($items));
    }

    protected function getAvailableVicidialColumns(): array
    {
        $columns = ['lead_id', 'status'];

        if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'campaign_id')) {
            $columns[] = 'campaign_id';
        }

        if (Schema::connection('asterisk')->hasColumn('vicidial_list', 'list_id')) {
            $columns[] = 'list_id';
        }

        return $columns;
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

    protected function printFilterSummary(array $filters): void
    {
        $this->line('Filters:');
        $this->line('lead_id: '.$filters['lead_id']);
        $this->line('status include: '.$filters['status_include']);
        $this->line('status exclude: '.$filters['status_exclude']);
        $this->line('campaign_id: '.$filters['campaign_id']);
        $this->line('list_id: '.$filters['list_id']);
        $this->line('limit: '.$filters['limit']);
        $this->line('dry_run: '.$filters['dry_run']);
        $this->newLine();
    }
}
