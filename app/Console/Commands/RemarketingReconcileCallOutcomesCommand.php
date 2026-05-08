<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\RemarketingProgressionService;

class RemarketingReconcileCallOutcomesCommand extends Command
{
    public function __construct(private RemarketingProgressionService $remarketingProgressionService)
    {
        parent::__construct();
    }

    protected $signature = 'remarketing:reconcile-call-outcomes
        {--commit : Persist remarketing/VICIdial changes (default dry-run)}
        {--limit=100 : Max progress rows to inspect}
        {--json : Output JSON payload}
        {--lead_id= : Optional VICIdial lead_id filter}
        {--since-minutes=240 : Lookback window for latest dial statuses}';

    protected $description = 'Reconcile VICIdial call outcomes back into the remarketing linear progress flow.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $limit = max(1, (int) $this->option('limit'));
        $sinceMinutes = max(1, (int) $this->option('since-minutes'));
        $leadId = $this->option('lead_id');
        $asJson = (bool) $this->option('json');
        $connection = config('services.vicidial.db_connection', 'asterisk');

        $query = LeadRemarketingProgress::query()
            ->with('currentStep')
            ->whereIn('status', ['active', 'pending_manual_task'])
            ->whereNull('stopped_at')
            ->orderBy('updated_at', 'desc')
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

            $latestLog = DB::connection($connection)->table('vicidial_log')
                ->where('lead_id', (int) $progress->lead_id)
                ->where('call_date', '>=', now()->subMinutes($sinceMinutes))
                ->orderByDesc('call_date')
                ->first(['status', 'call_date']);
            $listStatus = DB::connection($connection)->table('vicidial_list')
                ->where('lead_id', (int) $progress->lead_id)
                ->value('status');

            $status = strtoupper(trim((string) ($latestLog->status ?? $listStatus ?? '')));
            if ($status === '' || in_array($status, ['HOLD', 'NEW'], true)) {
                continue;
            }

            $action = $this->actionForStatus($status);
            $updated = false;
            if ($action !== null && $commit) {
                $updated = $this->applyAction($progress, $status, $action, $connection, ['call_date' => $latestLog?->call_date]);
            }

            $rows[] = [
                'lead_id' => (int) $progress->lead_id,
                'step_key' => $step->step_key,
                'step_order' => $step->step_order,
                'progress_status' => $progress->status,
                'latest_disposition' => $status,
                'action' => $action,
                'would_update' => $action !== null,
                'updated' => $updated,
                'call_date' => $latestLog?->call_date,
            ];
        }

        $payload = ['mode' => $commit ? 'commit' : 'dry-run', 'rows' => $rows];
        $this->line($asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : json_encode($payload));

        return self::SUCCESS;
    }

    private function isCallStep(RemarketingStep $step): bool { return strtolower((string) $step->medium) === 'call' || strtolower((string) $step->primary_medium) === 'call'; }

    private function actionForStatus(string $status): ?string
    {
        return match ($status) {
            'NA', 'AA', 'AIS', 'CHUP' => 'continue',
            'CALLBK', 'CBHOLD' => 'pause',
            'WIP' => 'convert_wip',
            'NI', 'NODEBT' => 'stop_dead',
            'DNC', 'REM' => 'suppress_stop',
            default => null,
        };
    }

    private function applyAction(LeadRemarketingProgress $progress, string $status, string $action, string $connection, array $meta = []): bool
    {
        return (bool) DB::transaction(function () use ($progress, $status, $action, $connection, $meta) {
            $progress = LeadRemarketingProgress::query()->lockForUpdate()->find($progress->id);
            if (! $progress) {
                return false;
            }
            $lead = Lead::query()->where('vicidial_lead_id', (int) $progress->lead_id)->first();

            if ($action === 'continue') {
                DB::connection($connection)->table('vicidial_list')->where('lead_id', (int) $progress->lead_id)->where('status', $status)->update(['status' => config('remarketing.call_hopper.hold_status')]);
                $result = $this->remarketingProgressionService->completeManualStepForLead(
                    leadId: (int) $progress->lead_id,
                    expectedStepKey: null,
                    metadata: [
                        'source' => 'vicidial_call_reconcile',
                        'call_outcome' => $status,
                        'command' => 'remarketing:reconcile-call-outcomes',
                        'call_date' => $meta['call_date'] ?? null,
                        'reconciled_at' => now()->toIso8601String(),
                    ]
                );

                if (! ($result['ok'] ?? false)) {
                    return false;
                }
            } elseif ($action === 'pause') {
                $progress->status = 'pending_manual_task';
                $progress->stop_context_json = ['pause_reason' => 'callback_requested', 'call_outcome' => $status];
                $progress->save();
            } else {
                $progress->status = 'stopped';
                $progress->stopped_at = now();
                $progress->stop_reason = 'vicidial_disposition:'.$status;
                $progress->stop_context_json = ['call_outcome' => $status, 'action' => $action];
                $progress->save();
                if ($lead) {
                    $lead->wip_status = in_array($action, ['convert_wip'], true) ? 'WIP' : 'DEAD';
                    $lead->save();
                }
            }

            Log::info('[remarketing-call-reconcile] outcome reconciled', ['lead_id' => (int) $progress->lead_id, 'status' => $status, 'action' => $action]);
            return true;
        });
    }
}
