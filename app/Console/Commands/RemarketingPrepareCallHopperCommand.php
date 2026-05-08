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
        {--commit : Persist VICIdial changes (default dry-run)}
        {--limit=50 : Max progress rows to inspect}
        {--window=auto : morning|evening|auto}
        {--json : Output JSON payload}
        {--lead_id= : Optional VICIdial lead_id filter}';

    protected $description = 'Prepare due remarketing call steps for VICIdial remarketing call windows.';

    public function handle(): int
    {
        if (! config('remarketing.call_hopper.enabled', false)) {
            $this->warn('remarketing.call_hopper.enabled is false; exiting.');
            return self::SUCCESS;
        }

        $commit = (bool) $this->option('commit');
        $limit = max(1, (int) $this->option('limit'));
        $leadId = $this->option('lead_id');
        $asJson = (bool) $this->option('json');
        $windowLabel = $this->resolveWindowLabel((string) $this->option('window'), now());
        $connection = config('services.vicidial.db_connection', 'asterisk');

        $query = LeadRemarketingProgress::query()
            ->with('currentStep')
            ->whereIn('status', ['active', 'pending_manual_task'])
            ->whereNull('stopped_at')
            ->whereNotNull('next_step_due_at')
            ->where('next_step_due_at', '<=', now())
            ->orderBy('next_step_due_at')
            ->limit($limit);

        if ($leadId !== null && $leadId !== '') {
            $query->where('lead_id', (int) $leadId);
        }

        $rows = [];
        $leadIdsToPrepare = [];

        foreach ($query->get() as $progress) {
            $step = $progress->currentStep;
            if (! $step instanceof RemarketingStep || ! $this->isCallStep($step)) {
                continue;
            }
            $leadIdsToPrepare[] = (int) $progress->lead_id;
            $rows[] = [
                'lead_id' => (int) $progress->lead_id,
                'step_key' => (string) $step->step_key,
                'step_order' => (int) $step->step_order,
                'progress_status' => (string) $progress->status,
            ];
        }

        $leadIdsToPrepare = array_values(array_unique($leadIdsToPrepare));

        $deactivatedLists = [];
        $activatedLists = [];
        $leadUpdates = 0;

        if ($commit && $leadIdsToPrepare !== []) {
            if (config('remarketing.call_hopper.disable_other_lists', true)) {
                $deactivatedLists = DB::connection($connection)->table('vicidial_lists')->where('active', 'Y')->pluck('list_id')->map(fn ($id) => (string) $id)->all();
                DB::connection($connection)->table('vicidial_lists')->where('active', 'Y')->update(['active' => 'N']);
            }

            DB::connection($connection)
                ->table('vicidial_lists')
                ->where('list_id', (string) config('remarketing.call_hopper.holding_list_id'))
                ->update(['active' => 'Y']);
            $activatedLists[] = (string) config('remarketing.call_hopper.holding_list_id');

            $updateData = ['status' => (string) config('remarketing.call_hopper.ready_status')];
            if (config('remarketing.call_hopper.reset_dial_flag', true)) {
                $updateData['called_since_last_reset'] = 'N';
            }

            $leadUpdates = DB::connection($connection)
                ->table('vicidial_list')
                ->whereIn('lead_id', $leadIdsToPrepare)
                ->where('list_id', (string) config('remarketing.call_hopper.holding_list_id'))
                ->update($updateData);
        } elseif ($leadIdsToPrepare !== []) {
            if (config('remarketing.call_hopper.disable_other_lists', true)) {
                $deactivatedLists = DB::connection($connection)->table('vicidial_lists')->where('active', 'Y')->pluck('list_id')->map(fn ($id) => (string) $id)->all();
            }
            $activatedLists[] = (string) config('remarketing.call_hopper.holding_list_id');
        }

        $hopperAction = $this->handleHopperReset($commit);

        $payload = [
            'mode' => $commit ? 'commit' : 'dry-run',
            'window' => $windowLabel,
            'lead_count' => count($leadIdsToPrepare),
            'lead_updates' => $leadUpdates,
            'deactivate_lists' => $deactivatedLists,
            'activate_list' => $activatedLists,
            'hopper_reset' => $hopperAction,
            'rows' => $rows,
        ];

        $this->line($asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : json_encode($payload));

        return self::SUCCESS;
    }

    private function handleHopperReset(bool $commit): array
    {
        if (! config('remarketing.call_hopper.reset_hopper_enabled', false)) {
            return ['implemented' => false, 'action' => 'disabled_by_config'];
        }

        Log::warning('[remarketing-call-hopper] TODO: safe VICIdial hopper reset method not implemented');

        return ['implemented' => false, 'action' => $commit ? 'todo_commit_logged' : 'todo_dry_run_logged'];
    }

    private function isCallStep(RemarketingStep $step): bool
    {
        return strtolower((string) $step->medium) === 'call' || strtolower((string) $step->primary_medium) === 'call';
    }

    private function resolveWindowLabel(string $window, Carbon $now): string
    {
        if ($window === 'morning' || $window === 'evening') {
            return $window;
        }

        $morningStart = Carbon::createFromFormat('H:i', (string) config('remarketing.call_hopper.morning_start', '09:00'), $now->timezone)->setDateFrom($now);
        $eveningStart = Carbon::createFromFormat('H:i', (string) config('remarketing.call_hopper.evening_start', '17:45'), $now->timezone)->setDateFrom($now);

        return $now->lt($eveningStart) && $now->gte($morningStart) ? 'morning' : 'evening';
    }
}
