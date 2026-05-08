<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingStep;
use App\Services\RemarketingProgressionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        {--window=auto : morning|evening|auto}';

    protected $description = 'Reconcile VICIdial call outcomes back into remarketing linear progress flow for today windows only.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $limit = max(1, (int) $this->option('limit'));
        $leadId = $this->option('lead_id');
        $asJson = (bool) $this->option('json');
        $connection = config('services.vicidial.db_connection', 'asterisk');

        [$windowLabel, $start, $end] = $this->resolveWindow((string) $this->option('window'));

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
        $manualCallStepLeadIds = [];
        foreach ($query->get() as $progress) {
            $step = $progress->currentStep;
            if (! $step instanceof RemarketingStep || ! $this->isCallStep($step)) {
                continue;
            }
            $manualCallStepLeadIds[] = (int) $progress->lead_id;

            $latestLog = DB::connection($connection)->table('vicidial_log')
                ->where('lead_id', (int) $progress->lead_id)
                ->where('list_id', (string) config('remarketing.call_hopper.holding_list_id'))
                ->where('call_date', '>=', $start)
                ->where('call_date', '<', $end)
                ->orderByDesc('call_date')
                ->first(['status', 'call_date', 'list_id']);

            if (! $latestLog) {
                continue;
            }

            $status = strtoupper(trim((string) ($latestLog->status ?? '')));
            if ($status === '' || in_array($status, ['HOLD', 'NEW'], true)) {
                continue;
            }

            $action = $this->actionForStatus($status);
            $updated = false;
            if ($action !== null && $commit) {
                $updated = $this->applyAction($progress, $status, $action, $connection, ['call_date' => $latestLog->call_date, 'window' => $windowLabel]);
            }

            $rows[] = [
                'lead_id' => (int) $progress->lead_id,
                'step_key' => $step->step_key,
                'step_order' => $step->step_order,
                'progress_status' => $progress->status,
                'latest_disposition' => $status,
                'action' => $action,
                'window' => $windowLabel,
                'window_start' => $start->toDateTimeString(),
                'window_end' => $end->toDateTimeString(),
                'would_update' => $action !== null,
                'updated' => $updated,
                'call_date' => $latestLog->call_date,
            ];
        }

        $holdingListId = (string) config('remarketing.call_hopper.holding_list_id');
        $holdStatus = (string) config('remarketing.call_hopper.hold_status');
        $finalOutcomes = ['NA', 'AA', 'AIS', 'CHUP', 'CALLBK', 'CBHOLD', 'WIP', 'NI', 'NODEBT', 'DNC', 'REM'];

        $manualCallStepLeadIds = DB::table('lead_remarketing_progress as lrp')
            ->join('remarketing_steps as rs', 'rs.id', '=', 'lrp.current_step_id')
            ->whereIn('lrp.status', ['active', 'pending_manual_task'])
            ->whereNull('lrp.stopped_at')
            ->where(function ($stepQuery) {
                $stepQuery->whereRaw('LOWER(COALESCE(rs.medium, "")) = ?', ['call'])
                    ->orWhereRaw('LOWER(COALESCE(rs.primary_medium, "")) = ?', ['call']);
            });

        if ($leadId !== null && $leadId !== '') {
            $manualCallStepLeadIds->where('lrp.lead_id', (int) $leadId);
        }

        $manualCallStepLeadIds = $manualCallStepLeadIds
            ->distinct()
            ->pluck('lrp.lead_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $parkOnlyQuery = DB::connection($connection)->table('vicidial_list')
            ->where('list_id', $holdingListId)
            ->whereIn('status', $finalOutcomes)
            ->where('status', '!=', $holdStatus)
            ->where('modify_date', '>=', $start)
            ->where('modify_date', '<', $end)
            ->orderByDesc('modify_date');

        if ($leadId !== null && $leadId !== '') {
            $parkOnlyQuery->where('lead_id', (int) $leadId);
        }

        if (! empty($manualCallStepLeadIds)) {
            $parkOnlyQuery->whereNotIn('lead_id', $manualCallStepLeadIds);
        }

        foreach ($parkOnlyQuery->limit($limit)->get(['lead_id', 'status', 'modify_date', 'list_id']) as $vicidialLead) {
            $parkUpdated = false;
            if ($commit) {
                $parkUpdated = DB::connection($connection)->table('vicidial_list')
                    ->where('lead_id', (int) $vicidialLead->lead_id)
                    ->where('list_id', (string) $vicidialLead->list_id)
                    ->where('status', (string) $vicidialLead->status)
                    ->update(['status' => $holdStatus]) > 0;
            }

            $rows[] = [
                'lead_id' => (int) $vicidialLead->lead_id,
                'step_key' => null,
                'step_order' => null,
                'progress_status' => null,
                'latest_disposition' => strtoupper(trim((string) $vicidialLead->status)),
                'action' => 'park_only_no_manual_call_step',
                'window' => $windowLabel,
                'window_start' => $start->toDateTimeString(),
                'window_end' => $end->toDateTimeString(),
                'would_update' => true,
                'updated' => $parkUpdated,
                'call_date' => null,
                'modify_date' => $vicidialLead->modify_date,
                'list_id' => (string) $vicidialLead->list_id,
            ];
        }

        $payload = ['mode' => $commit ? 'commit' : 'dry-run', 'window' => $windowLabel, 'rows' => $rows];
        if ($asJson && $leadId !== null && $leadId !== '') {
            $vicidialRow = DB::connection($connection)->table('vicidial_list')
                ->where('lead_id', (int) $leadId)
                ->first(['lead_id', 'list_id', 'status', 'modify_date', 'called_since_last_reset', 'last_local_call_time']);

            $manualCallStepActive = in_array((int) $leadId, $manualCallStepLeadIds, true);

            $statusNormalized = strtoupper(trim((string) ($vicidialRow->status ?? '')));
            $statusAllowed = $vicidialRow ? in_array($statusNormalized, $finalOutcomes, true) && $statusNormalized !== strtoupper(trim($holdStatus)) : false;
            $listMatch = $vicidialRow ? (string) $vicidialRow->list_id === $holdingListId : false;
            $windowMatch = $vicidialRow
                ? Carbon::parse((string) $vicidialRow->modify_date)->gte($start) && Carbon::parse((string) $vicidialRow->modify_date)->lt($end)
                : false;
            $matchedQuery = $statusAllowed && $listMatch && $windowMatch && ! $manualCallStepActive;

            $excludedReason = null;
            if (! $vicidialRow) {
                $excludedReason = 'vicidial_row_not_found';
            } elseif (! $statusAllowed) {
                $excludedReason = 'status_not_allowed_or_already_hold';
            } elseif (! $listMatch) {
                $excludedReason = 'holding_list_mismatch';
            } elseif (! $windowMatch) {
                $excludedReason = 'outside_selected_window';
            } elseif ($manualCallStepActive) {
                $excludedReason = 'active_manual_call_step_progress_exists';
            }

            $payload['debug'] = [
                'lead_id' => (int) $leadId,
                'holding_list_id' => $holdingListId,
                'window_start' => $start->toDateTimeString(),
                'window_end' => $end->toDateTimeString(),
                'vicidial_list' => $vicidialRow ? [
                    'lead_id' => (int) $vicidialRow->lead_id,
                    'list_id' => (string) $vicidialRow->list_id,
                    'status' => $statusNormalized,
                    'modify_date' => (string) $vicidialRow->modify_date,
                    'called_since_last_reset' => (string) ($vicidialRow->called_since_last_reset ?? ''),
                    'last_local_call_time' => (string) ($vicidialRow->last_local_call_time ?? ''),
                ] : null,
                'parking_checks' => [
                    'status_allowed' => $statusAllowed,
                    'list_match' => $listMatch,
                    'window_match' => $windowMatch,
                    'manual_call_step_active' => $manualCallStepActive,
                    'matched_query' => $matchedQuery,
                    'excluded_reason' => $excludedReason,
                ],
            ];
        }
        $this->line($asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : json_encode($payload));

        return self::SUCCESS;
    }

    private function resolveWindow(string $window): array
    {
        $now = now();
        $morningStart = Carbon::createFromFormat('H:i', (string) config('remarketing.call_hopper.morning_start', '09:00'), $now->timezone)->setDateFrom($now);
        $eveningStart = Carbon::createFromFormat('H:i', (string) config('remarketing.call_hopper.evening_start', '17:45'), $now->timezone)->setDateFrom($now);
        $tomorrow = $now->copy()->endOfDay()->addSecond();

        if ($window === 'morning') {
            return ['morning', $morningStart, $eveningStart];
        }
        if ($window === 'evening') {
            return ['evening', $eveningStart, $tomorrow];
        }

        return $now->lt($eveningStart)
            ? ['morning', $morningStart, $eveningStart]
            : ['evening', $eveningStart, $tomorrow];
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
                        'window' => $meta['window'] ?? null,
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
