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
use Illuminate\Support\Facades\Schema;

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
        {--window=auto : morning|evening|auto}
        {--repair-paused-tasks : Close dangling pending call tasks for callback-paused leads}';

    protected $description = 'Reconcile VICIdial call outcomes back into remarketing linear progress flow using manual-task anchors.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $limit = max(1, (int) $this->option('limit'));
        $leadId = $this->option('lead_id');
        $asJson = (bool) $this->option('json');
        $repairPausedTasks = (bool) $this->option('repair-paused-tasks');
        $connection = config('services.vicidial.db_connection', 'asterisk');
        $holdingListId = (string) config('remarketing.call_hopper.holding_list_id');
        $holdStatus = (string) config('remarketing.call_hopper.hold_status');
        $finalOutcomes = ['NA', 'AA', 'AIS', 'CHUP', 'PDROP', 'AB', 'CALLBK', 'CBHOLD', 'WIP', 'NI', 'NODEBT', 'DNC', 'REM'];

        [$windowLabel, $start, $end] = $this->resolveWindow((string) $this->option('window'));

        if ($repairPausedTasks) {
            $payload = [
                'mode' => $commit ? 'commit' : 'dry-run',
                'repair_paused_tasks' => true,
                'rows' => $this->repairPausedCallbackTasks($commit, $leadId),
            ];

            $this->line($asJson ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : json_encode($payload));

            return self::SUCCESS;
        }

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

            $taskAnchorAt = $this->resolveTaskAnchorTimestamp($progress);
            $latestLog = DB::connection($connection)->table('vicidial_log')
                ->where('lead_id', (int) $progress->lead_id)
                ->where('list_id', $holdingListId)
                ->when($taskAnchorAt, fn ($q) => $q->where('call_date', '>', $taskAnchorAt))
                ->whereIn('status', $finalOutcomes)
                ->orderByDesc('call_date')
                ->first(['status', 'call_date', 'list_id']);

            $selectedOutcome = null;
            $outcomeSource = null;
            if ($latestLog) {
                $selectedOutcome = strtoupper(trim((string) ($latestLog->status ?? '')));
                $outcomeSource = 'vicidial_log';
            } else {
                $vicidialListFallback = DB::connection($connection)->table('vicidial_list')
                    ->where('lead_id', (int) $progress->lead_id)
                    ->where('list_id', $holdingListId)
                    ->whereIn('status', $finalOutcomes)
                    ->when($taskAnchorAt, fn ($q) => $q->where('modify_date', '>', $taskAnchorAt))
                    ->orderByDesc('modify_date')
                    ->first(['status', 'modify_date', 'list_id']);

                if ($vicidialListFallback) {
                    $selectedOutcome = strtoupper(trim((string) ($vicidialListFallback->status ?? '')));
                    $latestLog = (object) ['status' => $selectedOutcome, 'call_date' => $vicidialListFallback->modify_date, 'list_id' => $vicidialListFallback->list_id];
                    $outcomeSource = 'vicidial_list_fallback';
                }
            }

            if (! $selectedOutcome || in_array($selectedOutcome, ['HOLD', 'NEW'], true)) {
                continue;
            }

            $action = $this->actionForStatus($selectedOutcome);
            $updated = false;
            if ($action !== null && $commit) {
                $updated = $this->applyAction($progress, $selectedOutcome, $action, $connection, ['call_date' => $latestLog->call_date, 'window' => $windowLabel, 'task_anchor_at' => $taskAnchorAt]);
            }

            $rows[] = [
                'lead_id' => (int) $progress->lead_id,
                'step_key' => $step->step_key,
                'step_order' => $step->step_order,
                'task_anchor_at' => $taskAnchorAt,
                'outcome_source' => $outcomeSource,
                'outcome_status' => $selectedOutcome,
                'outcome_at' => $latestLog->call_date,
                'action' => $action,
                'would_update' => $action !== null,
                'updated' => $updated,
                'mode' => $commit ? 'commit' : 'dry-run',
            ];
        }

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
            $progress = LeadRemarketingProgress::query()->with('currentStep')->whereIn('status', ['active', 'pending_manual_task'])->where('lead_id', (int) $leadId)->whereNull('stopped_at')->orderByDesc('updated_at')->first();
            $manualCallStepActive = $progress && $progress->currentStep instanceof RemarketingStep && $this->isCallStep($progress->currentStep);
            $taskAnchorAt = $progress ? $this->resolveTaskAnchorTimestamp($progress) : null;

            $latestLogOutcome = DB::connection($connection)->table('vicidial_log')
                ->where('lead_id', (int) $leadId)
                ->where('list_id', $holdingListId)
                ->when($taskAnchorAt, fn ($q) => $q->where('call_date', '>', $taskAnchorAt))
                ->whereIn('status', $finalOutcomes)
                ->orderByDesc('call_date')
                ->first(['status', 'call_date', 'list_id']);

            $vicidialRow = DB::connection($connection)->table('vicidial_list')
                ->where('lead_id', (int) $leadId)
                ->first(['lead_id', 'list_id', 'status', 'modify_date', 'called_since_last_reset', 'last_local_call_time']);

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
                'manual_call_step_active' => $manualCallStepActive,
                'task_anchor_at' => $taskAnchorAt,
                'latest_vicidial_log_outcome_after_anchor' => $latestLogOutcome ? [
                    'status' => strtoupper(trim((string) $latestLogOutcome->status)),
                    'call_date' => (string) $latestLogOutcome->call_date,
                    'list_id' => (string) $latestLogOutcome->list_id,
                ] : null,
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

    private function resolveTaskAnchorTimestamp(LeadRemarketingProgress $progress): ?string
    {
        $logQuery = DB::table('lead_remarketing_step_logs')
            ->where('lead_id', (int) $progress->lead_id)
            ->orderByDesc('id');
        if ($progress->current_step_id) {
            $logQuery->where('remarketing_step_id', (int) $progress->current_step_id);
        } elseif ($progress->current_step_order) {
            $logQuery->where('step_order', (int) $progress->current_step_order);
        }
        if (Schema::hasColumn('lead_remarketing_step_logs', 'execution_status')) {
            $logQuery->whereIn('execution_status', ['manual_task_created', 'manual_task_exists']);
        }
        $latestStepLog = $logQuery->first(['created_task_id', 'due_at', 'created_at']);

        if ($latestStepLog && ! empty($latestStepLog->created_task_id) && Schema::hasTable('remarketing_tasks')) {
            $task = DB::table('remarketing_tasks')->where('id', (int) $latestStepLog->created_task_id)->first(['created_at']);
            if ($task && ! empty($task->created_at)) {
                return (string) $task->created_at;
            }
        }

        if ($progress->status === 'pending_manual_task' && $progress->updated_at) {
            return (string) $progress->updated_at;
        }
        if ($progress->next_step_due_at) {
            return (string) $progress->next_step_due_at;
        }
        if ($latestStepLog && ! empty($latestStepLog->created_at)) {
            return (string) $latestStepLog->created_at;
        }
        if ($latestStepLog && ! empty($latestStepLog->due_at)) {
            return (string) $latestStepLog->due_at;
        }

        return null;
    }

    private function actionForStatus(string $status): ?string
    {
        return match ($status) {
            'NA', 'AA', 'AIS', 'CHUP', 'PDROP', 'AB' => 'continue',
            'CALLBK', 'CBHOLD' => 'pause',
            'WIP' => 'convert_wip',
            'NI', 'NODEBT' => 'stop_dead',
            'DNC', 'REM' => 'suppress_stop',
            default => null,
        };
    }

    private function repairPausedCallbackTasks(bool $commit, mixed $leadIdOption): array
    {
        $callbackOutcomes = ['CALLBK', 'CBHOLD'];
        $query = DB::table('remarketing_tasks as rt')
            ->join('lead_remarketing_progress as lrp', 'lrp.lead_id', '=', 'rt.lead_id')
            ->leftJoin('lead_remarketing_step_logs as lrsl', function ($join) {
                $join->on('lrsl.created_task_id', '=', 'rt.id')
                    ->on('lrsl.lead_id', '=', 'rt.lead_id');
            })
            ->where('rt.task_type', 'call')
            ->where('rt.status', 'pending')
            ->where('lrp.status', 'pending_manual_task')
            ->whereNull('lrp.stopped_at')
            ->where(function ($progressQuery) use ($callbackOutcomes) {
                $progressQuery->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(lrp.stop_context_json, '$.pause_reason')) = ?", ['callback_requested'])
                    ->orWhereIn(DB::raw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(lrp.stop_context_json, '$.call_outcome')))"), $callbackOutcomes);
            })
            ->when($leadIdOption !== null && $leadIdOption !== '', fn ($q) => $q->where('rt.lead_id', (int) $leadIdOption))
            ->groupBy('rt.id', 'rt.lead_id', 'rt.status', 'lrp.id', 'lrp.current_step_id', 'lrp.current_step_order', 'lrp.status', 'lrp.stop_context_json')
            ->orderBy('rt.id')
            ->select([
                'rt.id as task_id',
                'rt.lead_id',
                'rt.status as task_status',
                'lrp.id as progress_id',
                'lrp.current_step_id',
                'lrp.current_step_order',
                'lrp.status as progress_status',
                'lrp.stop_context_json',
                DB::raw('MAX(lrsl.id) as source_step_log_id'),
                DB::raw('MAX(CASE WHEN lrsl.created_task_id = rt.id THEN 1 ELSE 0 END) as has_step_log_link'),
            ]);

        $rows = [];
        foreach ($query->get() as $candidate) {
            $context = is_string($candidate->stop_context_json)
                ? (json_decode($candidate->stop_context_json, true) ?: [])
                : ((array) ($candidate->stop_context_json ?? []));

            $stepId = $candidate->current_step_id ? (int) $candidate->current_step_id : null;
            $stepOrder = $candidate->current_step_order ? (int) $candidate->current_step_order : null;
            $closeResult = ['closed' => false, 'reason' => 'dry_run'];

            if ($commit) {
                $closeResult = $this->remarketingProgressionService->closeLinkedManualTaskForCurrentStep(
                    leadId: (int) $candidate->lead_id,
                    stepId: $stepId,
                    stepOrder: $stepOrder,
                    metadata: [
                        'source' => 'remarketing:reconcile-call-outcomes',
                        'mode' => 'repair_paused_tasks',
                        'action' => 'repair_close_paused_callback_task',
                        'pause_reason' => $context['pause_reason'] ?? 'callback_requested',
                        'call_outcome' => $context['call_outcome'] ?? null,
                        'callback_id' => $context['callback_id'] ?? null,
                        'callback_time' => $context['callback_time'] ?? null,
                    ]
                );
            }

            $rows[] = [
                'action' => 'repair_close_paused_callback_task',
                'mode' => $commit ? 'commit' : 'dry-run',
                'lead_id' => (int) $candidate->lead_id,
                'progress_id' => (int) $candidate->progress_id,
                'progress_status' => (string) $candidate->progress_status,
                'task_id' => (int) $candidate->task_id,
                'task_status' => (string) $candidate->task_status,
                'source_step_log_id' => $candidate->source_step_log_id ? (int) $candidate->source_step_log_id : null,
                'has_step_log_link' => ((int) $candidate->has_step_log_link) === 1,
                'pause_reason' => $context['pause_reason'] ?? null,
                'call_outcome' => $context['call_outcome'] ?? null,
                'would_update' => true,
                'updated' => $commit ? (bool) ($closeResult['closed'] ?? false) : false,
                'close_result' => $closeResult,
            ];
        }

        return $rows;
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

                return (bool) ($result['advanced'] ?? $result['ok'] ?? false);
            } elseif ($action === 'pause') {
                $callback = DB::connection($connection)->table('vicidial_callbacks')
                    ->where('lead_id', (int) $progress->lead_id)
                    ->orderByDesc('callback_time')
                    ->orderByDesc('callback_id')
                    ->first(['callback_id', 'callback_time', 'status']);

                $pauseMeta = [
                    'pause_reason' => 'callback_requested',
                    'call_outcome' => $status,
                    'source' => 'vicidial_call_reconcile',
                    'call_date' => $meta['call_date'] ?? null,
                    'window' => $meta['window'] ?? null,
                    'reconciled_at' => now()->toIso8601String(),
                    'callback_id' => $callback->callback_id ?? null,
                    'callback_time' => $callback->callback_time ?? null,
                    'callback_status' => $callback->status ?? null,
                ];

                $progress->status = 'pending_manual_task';
                $progress->stop_context_json = $pauseMeta;
                $progress->save();

                $this->remarketingProgressionService->closeLinkedManualTaskForCurrentStep(
                    leadId: (int) $progress->lead_id,
                    stepId: $progress->current_step_id ? (int) $progress->current_step_id : null,
                    stepOrder: $progress->current_step_order ? (int) $progress->current_step_order : null,
                    metadata: array_merge($pauseMeta, ['action' => 'pause'])
                );
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
