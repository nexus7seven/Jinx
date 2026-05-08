<?php

namespace App\Console\Commands;

use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingStep;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RemarketingPrepareCallHopperCommand extends Command
{
    protected $signature = 'remarketing:prepare-call-hopper
        {--commit : Persist VICIdial status changes (default dry-run)}
        {--limit=50 : Max progress rows to inspect}
        {--window=auto : morning|evening|auto}
        {--json : Output JSON payload}
        {--lead_id= : Optional VICIdial lead_id filter}';

    protected $description = 'Prepare due remarketing call steps for VICIdial by moving HOLD -> NEW within configured call windows.';

    public function handle(): int
    {
        if (! config('remarketing.call_hopper.enabled', false)) {
            $this->warn('remarketing.call_hopper.enabled is false; exiting.');
            return self::SUCCESS;
        }

        $commit = (bool) $this->option('commit');
        $window = (string) $this->option('window');
        $limit = max(1, (int) $this->option('limit'));
        $leadId = $this->option('lead_id');
        $asJson = (bool) $this->option('json');

        $now = now();
        $windowLabel = $this->resolveWindowLabel($window, $now);
        $inWindow = $windowLabel !== null;

        $query = LeadRemarketingProgress::query()
            ->with('currentStep')
            ->whereIn('status', ['active', 'pending_manual_task'])
            ->whereNull('stopped_at')
            ->whereNotNull('next_step_due_at')
            ->where('next_step_due_at', '<=', $now)
            ->orderBy('next_step_due_at')
            ->limit($limit);

        if ($leadId !== null && $leadId !== '') {
            $query->where('lead_id', (int) $leadId);
        }

        $rows = [];
        foreach ($query->get() as $progress) {
            $step = $progress->currentStep;
            if (! $step instanceof RemarketingStep || ! $this->isCallStep($step)) {
                continue;
            }

            $vicidial = DB::connection(config('services.vicidial.db_connection', 'asterisk'))
                ->table('vicidial_list')
                ->where('lead_id', (int) $progress->lead_id)
                ->first(['lead_id', 'status', 'list_id', 'campaign_id']);

            $skip = null;
            $wouldUpdate = false;
            if (! $inWindow) {
                $skip = 'outside_call_window';
            } elseif (! $vicidial) {
                $skip = 'vicidial_row_missing';
            } elseif ((string) $vicidial->list_id !== (string) config('remarketing.call_hopper.holding_list_id')) {
                $skip = 'wrong_list';
            } elseif (strtoupper((string) $vicidial->status) !== strtoupper((string) config('remarketing.call_hopper.hold_status'))) {
                $skip = 'status_not_hold';
            } else {
                $wouldUpdate = true;
            }

            if ($wouldUpdate && $commit) {
                DB::connection(config('services.vicidial.db_connection', 'asterisk'))
                    ->table('vicidial_list')
                    ->where('lead_id', (int) $progress->lead_id)
                    ->where('status', (string) config('remarketing.call_hopper.hold_status'))
                    ->update(['status' => (string) config('remarketing.call_hopper.ready_status')]);

                Log::info('[remarketing-call-hopper] moved lead to ready status', [
                    'lead_id' => (int) $progress->lead_id,
                    'from_status' => (string) config('remarketing.call_hopper.hold_status'),
                    'to_status' => (string) config('remarketing.call_hopper.ready_status'),
                    'step_key' => $step->step_key,
                    'step_order' => $step->step_order,
                    'window' => $windowLabel,
                ]);
            }

            $rows[] = [
                'lead_id' => (int) $progress->lead_id,
                'vicidial_lead_id' => (int) $progress->lead_id,
                'current_progress_status' => (string) $progress->status,
                'step_key' => (string) $step->step_key,
                'step_order' => (int) $step->step_order,
                'call_window_label' => $windowLabel,
                'current_vicidial_status' => $vicidial ? (string) $vicidial->status : null,
                'proposed_vicidial_status' => (string) config('remarketing.call_hopper.ready_status'),
                'would_update' => $wouldUpdate,
                'updated' => $wouldUpdate && $commit,
                'skip_reason' => $skip,
            ];
        }

        $payload = ['mode' => $commit ? 'commit' : 'dry-run', 'rows' => $rows];
        if ($asJson) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }

    private function isCallStep(RemarketingStep $step): bool
    {
        return strtolower((string) $step->medium) === 'call' || strtolower((string) $step->primary_medium) === 'call';
    }

    private function resolveWindowLabel(string $window, Carbon $now): ?string
    {
        if ($window === 'morning') {
            return $this->inWindow($now, config('remarketing.call_hopper.morning_start'), config('remarketing.call_hopper.morning_end')) ? 'morning' : null;
        }
        if ($window === 'evening') {
            return $this->inWindow($now, config('remarketing.call_hopper.evening_start'), config('remarketing.call_hopper.evening_end')) ? 'evening' : null;
        }

        if ($this->inWindow($now, config('remarketing.call_hopper.morning_start'), config('remarketing.call_hopper.morning_end'))) {
            return 'morning';
        }
        if ($this->inWindow($now, config('remarketing.call_hopper.evening_start'), config('remarketing.call_hopper.evening_end'))) {
            return 'evening';
        }

        return null;
    }

    private function inWindow(Carbon $now, ?string $start, ?string $end): bool
    {
        if (! $start || ! $end) {
            return false;
        }
        $s = Carbon::createFromFormat('H:i', $start, $now->timezone)->setDateFrom($now);
        $e = Carbon::createFromFormat('H:i', $end, $now->timezone)->setDateFrom($now);

        return $now->betweenIncluded($s, $e);
    }
}
