<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemarketingPollCbholdCommand extends Command
{
    private const TARGET_LIST_ID = '5555555555';

    private const TARGET_STATUS = 'CBHOLD';

    protected $signature = 'remarketing:poll-cbhold
        {--commit : Persist writes (default is dry-run)}
        {--limit=100 : Max VICIdial CBHOLD rows to scan}
        {--lead_id= : Optional single VICIdial lead_id}
        {--json : Output JSON rows}';

    protected $description = 'Poll VICIdial CBHOLD leads and halt Jinx remarketing for those leads (dry-run by default).';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $dryRun = ! $commit;
        $asJson = (bool) $this->option('json');
        $limit = max(1, (int) $this->option('limit'));
        $leadIdFilter = $this->option('lead_id');

        $rows = [];
        $candidates = $this->getCandidates($limit, $leadIdFilter);

        foreach ($candidates as $candidate) {
            $rows[] = $this->processCandidate((int) $candidate->lead_id, $dryRun);
        }

        if ($asJson) {
            $this->line(json_encode(['rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    private function processCandidate(int $leadId, bool $dryRun): array
    {
        $progress = LeadRemarketingProgress::query()->where('lead_id', $leadId)->first();

        $row = [
            'lead_id' => $leadId,
            'vicidial_status' => self::TARGET_STATUS,
            'progress_id' => $progress?->id,
            'previous_progress_status' => $progress?->status,
            'action' => 'stop_remarketing_cbhold',
            'pending_tasks_closed' => 0,
            'updated' => false,
            'skip_reason' => null,
        ];

        if ($progress === null) {
            $row['skip_reason'] = 'no_progress_row';

            return $row;
        }

        if (in_array($progress->status, [LeadRemarketingProgress::STATUS_COMPLETED, 'stopped'], true)) {
            $row['skip_reason'] = 'progress_already_'.$progress->status;

            return $row;
        }

        $pendingTaskCount = RemarketingTask::query()
            ->where('lead_id', $leadId)
            ->where('task_type', 'call')
            ->where('status', RemarketingTask::STATUS_PENDING)
            ->count();

        $row['pending_tasks_closed'] = $pendingTaskCount;

        if ($dryRun) {
            return $row;
        }

        DB::transaction(function () use ($progress, $leadId, &$row): void {
            $now = now();

            $progress->update([
                'status' => 'stopped',
                'stopped_at' => $now,
                'stop_reason' => 'vicidial_cbhold',
                'stop_context_json' => [
                    'source' => 'remarketing:poll-cbhold',
                    'vicidial_status' => self::TARGET_STATUS,
                    'list_id' => self::TARGET_LIST_ID,
                    'reason' => 'callback_hold_requested',
                    'detected_at' => $now->toIso8601String(),
                ],
            ]);

            $closed = RemarketingTask::query()
                ->where('lead_id', $leadId)
                ->where('task_type', 'call')
                ->where('status', RemarketingTask::STATUS_PENDING)
                ->update([
                    'status' => RemarketingTask::STATUS_COMPLETED,
                    'updated_at' => $now,
                ]);

            $row['pending_tasks_closed'] = $closed;
            $row['updated'] = true;
        });

        return $row;
    }

    private function getCandidates(int $limit, mixed $leadIdFilter)
    {
        $query = DB::connection('asterisk')
            ->table('vicidial_list')
            ->select(['lead_id', 'status'])
            ->where('list_id', self::TARGET_LIST_ID)
            ->where('status', self::TARGET_STATUS)
            ->orderBy('lead_id')
            ->limit($limit);

        if ($leadIdFilter !== null && $leadIdFilter !== '') {
            $query->where('lead_id', (int) $leadIdFilter);
        }

        return $query->get();
    }
}
