<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Models\LeadRemarketingStepLog;
use App\Models\RemarketingStep;
use App\Models\RemarketingTask;
use App\Services\RemarketingScheduleWindowService;
use App\Support\LeadSourceDisplay;
use App\Services\RemarketingCallbackService;
use App\Services\RemarketingTaskService;
use App\Services\VicidialDispositionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemarketingController extends Controller
{
    private const FLOW_START_REASON = 'flow_start';
    private const DORMANT_FINAL_WHATSAPP_REASON = 'Dormant stage final WhatsApp touch';

    /** VICIdial dispositions that end remarketing (no further timeline steps). */
    private const REMARKETING_TERMINAL_DISPOSITIONS = [
        'AIS',
        'NI',
        'DNC',
        'REM',
        'ADC',
        'DC',
        'NODEBT',
    ];

    public function __construct(
        private RemarketingCallbackService $remarketingCallbackService,
        private RemarketingTaskService $remarketingTaskService,
        private VicidialDispositionService $vicidialDispositionService,
        private RemarketingScheduleWindowService $scheduleWindowService,
    ) {
    }

    public function index()
    {
        $steps = RemarketingStep::query()
            ->where('is_active', true)
            ->orderBy('step_order')
            ->with('template')
            ->get();

        $stepsByOrder = $steps->keyBy('step_order');
        $now = Carbon::now(RemarketingScheduleWindowService::TIMEZONE);

        $manualCandidates = LeadRemarketingProgress::query()
            ->whereIn('status', ['active', 'pending_manual_task'])
            ->orderBy('id')
            ->get()
            ->map(function (LeadRemarketingProgress $progress) use ($steps, $stepsByOrder, $now) {
                $currentOrder = $progress->current_step_order;
                if ($currentOrder === null && $progress->current_step_id !== null) {
                    $currentOrder = optional($steps->firstWhere('id', $progress->current_step_id))->step_order;
                }

                $nextStep = $currentOrder === null
                    ? $steps->first()
                    : $steps->first(fn (RemarketingStep $step) => $step->step_order > $currentOrder);

                if (! $nextStep instanceof RemarketingStep) {
                    return null;
                }

                if (! in_array($nextStep->medium, ['call', 'whatsapp'], true)) {
                    return null;
                }

                $baseTime = $progress->current_step_order === null
                    ? ($progress->started_at ?? $progress->created_at)
                    : ($progress->last_step_completed_at ?? $progress->updated_at ?? $progress->created_at);
                $baseTime = ($baseTime ?? $now)
                    ->copy()
                    ->setTimezone(RemarketingScheduleWindowService::TIMEZONE);

                $rawDue = $baseTime->copy()->addMinutes((int) $nextStep->delay_minutes);
                $nextAllowed = $this->scheduleWindowService->nextAllowedTime($nextStep, $rawDue->copy());
                $dueNow = $now->greaterThanOrEqualTo($nextAllowed);

                if ($progress->status !== 'pending_manual_task' && ! $dueNow) {
                    return null;
                }

                return [
                    'progress' => $progress,
                    'next_step' => $nextStep,
                    'due_at' => $nextAllowed,
                    'is_due_now' => $dueNow,
                ];
            })
            ->filter()
            ->values();

        $vicidialIds = $manualCandidates->pluck('progress.lead_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $leadsByVicidialId = Lead::query()
            ->whereIn('vicidial_lead_id', $vicidialIds->all())
            ->get()
            ->keyBy(fn (Lead $lead) => (int) $lead->vicidial_lead_id);

        $activeTasks = $manualCandidates->map(function (array $candidate) use ($leadsByVicidialId, $now) {
            /** @var LeadRemarketingProgress $progress */
            $progress = $candidate['progress'];
            /** @var RemarketingStep $nextStep */
            $nextStep = $candidate['next_step'];
            /** @var Carbon $dueAt */
            $dueAt = $candidate['due_at'];
            $isDueNow = (bool) ($candidate['is_due_now'] ?? false);

            $lead = $leadsByVicidialId->get((int) $progress->lead_id);
            $first = trim((string) ($lead?->first_name ?? ''));
            $last = trim((string) ($lead?->last_name ?? ''));
            $leadName = trim($first.' '.$last);
            if ($leadName === '') {
                $leadName = 'Lead #'.$progress->lead_id;
            }

            $phone = trim((string) ($lead?->phone_number ?? ''));
            $waiting = $dueAt ? $dueAt->diffForHumans($now, [
                'parts' => 2,
                'short' => true,
            ]) : 'Waiting';
            $waiting = str_replace([' ago', 'from now'], '', $waiting);

            $whatsappUrl = null;
            if ($nextStep->medium === 'whatsapp' && $phone !== '') {
                $digits = preg_replace('/\D+/', '', $phone) ?? '';

                if (str_starts_with($digits, '0') && strlen($digits) === 11) {
                    $digits = '44'.substr($digits, 1);
                } elseif (str_starts_with($digits, '7') && strlen($digits) === 10) {
                    $digits = '44'.$digits;
                } elseif (! str_starts_with($digits, '44')) {
                    $digits = '';
                }

                if ($digits !== '') {
                    $whatsappUrl = 'https://wa.me/'.$digits;

                    $templateBody = trim((string) ($nextStep->template?->body ?? ''));
                    if ($templateBody !== '') {
                        $renderedBody = str_replace(
                            ['{{first_name}}', '{{lead_id}}', '{{portal_link}}'],
                            [
                                (string) ($lead?->first_name ?? ''),
                                (string) $progress->lead_id,
                                (string) config('app.url'),
                            ],
                            $templateBody
                        );
                        $whatsappUrl .= '?text='.urlencode($renderedBody);
                    }
                }
            }

            return [
                // preserve card contract keys
                'id' => 'linear-progress-'.$progress->id,
                'lead_id' => $progress->lead_id,
                'lead_name' => $leadName,
                'phone' => $phone !== '' ? $phone : '-',
                'campaign_id' => null,
                'reason' => $nextStep->step_name,
                'time_waiting' => $waiting,
                'waiting_text' => $waiting,
                'is_due_now' => $isDueNow,
                'task_type' => $nextStep->medium,
                'stage' => $nextStep->step_name,
                'whatsapp_url' => $whatsappUrl,
                'source_label' => $lead ? LeadSourceDisplay::label($lead->source) : null,
                'is_linear' => true,
            ];
        })->all();

        $pendingTasks = RemarketingTask::query()
            ->where('status', RemarketingTask::STATUS_PENDING)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (RemarketingTask $task): array {
                $messageBody = null;
                $whatsappUrl = $task->whatsapp_url;
                if ($task->task_type === 'whatsapp') {
                    $whatsappUrl = $whatsappUrl ?: 'https://whatsapp.clearmycredit.co.uk';
                    $messageBody = trim((string) ($task->message_body ?? ''));
                    if ($messageBody === '') {
                        $renderedFromMetadata = trim((string) data_get($task->metadata_json, 'rendered_body', ''));
                        if ($renderedFromMetadata !== '') {
                            $messageBody = $renderedFromMetadata;
                        }
                    }
                    if ($messageBody === '') {
                        $query = parse_url((string) $whatsappUrl, PHP_URL_QUERY);
                        if (is_string($query) && $query !== '') {
                            parse_str($query, $queryParams);
                            $rawText = $queryParams['text'] ?? null;
                            if (is_string($rawText) && trim($rawText) !== '') {
                                $messageBody = urldecode($rawText);
                            }
                        }
                    }
                }

                return [
                    'id' => 'task-'.$task->id,
                    'task_id' => $task->id,
                    'lead_id' => $task->lead_id,
                    'lead_name' => $task->lead_name ?: ('Lead #'.$task->lead_id),
                    'phone' => $task->phone ?: '-',
                    'campaign_id' => $task->campaign_id,
                    'reason' => $task->reason,
                    'time_waiting' => $task->time_waiting_text ?: 'Waiting',
                    'waiting_text' => $task->time_waiting_text ?: 'Waiting',
                    'is_due_now' => true,
                    'task_type' => $task->task_type,
                    'stage' => $task->stage,
                    'whatsapp_url' => $whatsappUrl,
                    'message_body' => $messageBody,
                    'source_label' => null,
                    'is_linear' => false,
                ];
            })
            ->all();

        $activeTasks = collect(array_merge($pendingTasks, $activeTasks))
            ->unique(fn (array $task): string => implode('|', [
                (string) ($task['lead_id'] ?? ''),
                (string) ($task['task_type'] ?? ''),
                (string) ($task['reason'] ?? ''),
            ]))
            ->values()
            ->all();

        $recentActivity = LeadRemarketingStepLog::query()
            ->with(['remarketingStep', 'template'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function (LeadRemarketingStepLog $log) use ($leadsByVicidialId) {
                $lead = $leadsByVicidialId->get((int) $log->lead_id);
                $first = trim((string) ($lead?->first_name ?? ''));
                $last = trim((string) ($lead?->last_name ?? ''));
                $leadName = trim($first.' '.$last);
                if ($leadName === '') {
                    $leadName = 'Lead #'.$log->lead_id;
                }

                $medium = strtolower((string) ($log->medium ?? ''));
                $status = strtolower((string) ($log->status ?? ''));

                if ($medium === 'sms' && $status === 'sent') {
                    $activity = 'sms sent';
                } elseif ($medium === 'email' && $status === 'sent') {
                    $activity = 'email sent';
                } elseif ($medium === 'sms' && $status === 'failed') {
                    $activity = 'sms failed';
                } elseif ($medium === 'email' && $status === 'failed') {
                    $activity = 'email failed';
                } elseif ($medium === 'call' && $status === 'queued_task') {
                    $activity = 'call queued';
                } elseif ($medium === 'whatsapp' && $status === 'queued_task') {
                    $activity = 'whatsapp queued';
                } elseif ($medium === 'whatsapp' && $status === 'completed') {
                    $activity = 'WhatsApp completed';
                } elseif ($status === 'completed') {
                    $activity = 'step completed';
                } else {
                    $activity = trim(($medium !== '' ? $medium.' ' : '').$status);
                }

                return [
                    'lead_name' => $leadName,
                    'activity' => $activity,
                    'time' => optional($log->created_at)->diffForHumans() ?? 'Just now',
                ];
            })
            ->all();

        return view('remarketing.index', [
            'activeTasks' => $activeTasks,
            'recentActivity' => $recentActivity,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'lead_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'task_type' => ['required', 'in:call,whatsapp'],
            'reason' => ['required', 'string', 'max:255'],
            'stage' => ['required', 'in:fresh,cooling,cold,dormant'],
            'time_waiting_text' => ['nullable', 'string', 'max:255'],
            'lead_id' => ['nullable', 'integer', 'required_if:task_type,call'],
            'campaign_id' => ['nullable', 'string', 'max:255', 'required_if:task_type,call'],
        ]);

        if ($validated['task_type'] === 'call') {
            $this->remarketingTaskService->createCallTask($validated);
        } else {
            $this->remarketingTaskService->createWhatsAppTask($validated);
        }

        return redirect()
            ->route('remarketing.index')
            ->with('success', 'Task added.');
    }

    public function complete(Request $request)
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'task_type' => ['required', 'in:call,whatsapp'],
            'lead_name' => ['required', 'string', 'max:255'],
            'current_stage' => ['nullable', 'in:all,fresh,cooling,cold,dormant'],
        ]);

        Log::info('Remarketing task completed', [
            'task_id' => $validated['task_id'],
            'task_type' => $validated['task_type'],
            'lead_name' => $validated['lead_name'],
        ]);

        $task = RemarketingTask::query()
            ->where('id', $validated['task_id'])
            ->where('status', RemarketingTask::STATUS_PENDING)
            ->first();

        if ($task) {
            $task->status = RemarketingTask::STATUS_COMPLETED;
            if (Schema::hasColumn('remarketing_tasks', 'completed_at')) {
                $task->completed_at = $task->completed_at ?? now();
            }
            $task->save();

            $linkedLog = $this->completeLinkedManualStepLog($task);
            $this->advanceProgressForCompletedManualTask($linkedLog);

            if ($task->task_type === 'call') {
                $this->remarketingStepTwoAfterCallCompleted($task);
            }
        }

        return redirect()
            ->route('remarketing.index')
            ->with('success', 'Task marked complete.');
    }

    public function call(Request $request)
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer'],
            'lead_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'task_type' => ['required', 'in:call'],
            'current_stage' => ['nullable', 'in:all,fresh,cooling,cold,dormant'],
        ]);

        $task = RemarketingTask::query()->find($validated['task_id']);
        if (! $task || $task->status !== RemarketingTask::STATUS_PENDING || $task->task_type !== 'call') {
            return redirect()
                ->route('remarketing.index')
                ->with('error', 'Task is not available for calling.');
        }

        if (! $task->lead_id) {
            return redirect()
                ->route('remarketing.index')
                ->with('error', 'Task is missing lead_id.');
        }
        if (! $task->campaign_id) {
            return redirect()
                ->route('remarketing.index')
                ->with('error', 'Task is missing campaign_id.');
        }
        if (! $task->phone || trim((string) $task->phone) === '') {
            return redirect()
                ->route('remarketing.index')
                ->with('error', 'Task is missing a usable phone number.');
        }

        $result = $this->remarketingCallbackService->dialLead([
            'lead_id' => (int) $task->lead_id,
            'phone_number' => (string) $task->phone,
            'campaign_id' => (string) $task->campaign_id,
        ]);

        Log::info('Remarketing call launch result', [
            'task_id' => $validated['task_id'],
            'lead_name' => $validated['lead_name'],
            'phone' => $task->phone,
            'reason' => $validated['reason'],
            'task_type' => $validated['task_type'],
            'current_stage' => $validated['current_stage'] ?? null,
            'lead_id' => $task->lead_id,
            'campaign_id' => $task->campaign_id,
            'result' => $result,
        ]);

        $isOk = (bool) ($result['ok'] ?? false);
        $popupConfirmed = (bool) ($result['popup_confirmed'] ?? false);

        if ($isOk) {
            $fromStatus = (string) $task->status;
            $toStatus = $popupConfirmed ? RemarketingTask::STATUS_COMPLETED : RemarketingTask::STATUS_STARTED;
            $task->status = $toStatus;
            $task->save();

            Log::info('Remarketing task status transition', [
                'task_id' => $task->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'popup_confirmed' => $popupConfirmed,
            ]);

            if ($toStatus === RemarketingTask::STATUS_COMPLETED && $task->task_type === 'call') {
                $this->remarketingStepTwoAfterCallCompleted($task);
            }
        }

        if (! $isOk) {
            $flashType = 'error';
            $flashMessage = (string) ($result['message'] ?? 'Call failed.');
        } elseif ($popupConfirmed) {
            $flashType = 'success';
            $flashMessage = 'Call started and popup confirmed.';
        } else {
            $flashType = 'success';
            $flashMessage = 'Call started, but popup not confirmed yet.';
        }

        return redirect()
            ->route('remarketing.index')
            ->with($flashType, $flashMessage);
    }

    public function completeLinearManualStep(Request $request, int $leadId)
    {
        $result = DB::transaction(function () use ($leadId) {
            $progress = LeadRemarketingProgress::query()
                ->where('lead_id', $leadId)
                ->lockForUpdate()
                ->first();

            if (! $progress) {
                return [
                    'ok' => false,
                    'message' => 'Remarketing progress not found for this lead.',
                ];
            }

            $steps = RemarketingStep::query()
                ->where('is_active', true)
                ->orderBy('step_order')
                ->get();

            if ($steps->isEmpty()) {
                return [
                    'ok' => false,
                    'message' => 'No active remarketing steps are configured.',
                ];
            }

            $nextStep = null;
            if ($progress->current_step_order === null) {
                $nextStep = $steps->first();
            } else {
                $nextStep = $steps->first(fn (RemarketingStep $step) => $step->step_order > $progress->current_step_order);
            }

            if (! $nextStep) {
                $progress->status = LeadRemarketingProgress::STATUS_COMPLETED;
                $progress->save();

                return [
                    'ok' => true,
                    'message' => 'Remarketing flow already completed.',
                ];
            }

            if (! in_array($nextStep->medium, ['call', 'whatsapp'], true)) {
                return [
                    'ok' => false,
                    'message' => 'Only call or WhatsApp steps can be completed manually.',
                ];
            }

            $baseTime = $progress->current_step_order === null
                ? ($progress->started_at ?? $progress->created_at)
                : ($progress->last_step_completed_at ?? $progress->updated_at ?? $progress->created_at);

            $rawDue = $baseTime->copy()->addMinutes((int) $nextStep->delay_minutes);
            $nextAllowed = $this->scheduleWindowService->nextAllowedTime($nextStep, $rawDue);
            $dueNow = now()->greaterThanOrEqualTo($nextAllowed);

            if (! $dueNow && $progress->status !== LeadRemarketingProgress::STATUS_PENDING_MANUAL_TASK) {
                return [
                    'ok' => false,
                    'message' => 'This manual step is not due yet.',
                ];
            }

            $now = now();

            LeadRemarketingStepLog::query()->create([
                'lead_id' => $leadId,
                'remarketing_step_id' => $nextStep->id,
                'step_order' => $nextStep->step_order,
                'medium' => $nextStep->medium,
                'template_id' => $nextStep->template_id,
                'status' => 'completed',
                'due_at' => $nextAllowed,
                'started_at' => $now,
                'completed_at' => $now,
                'context_json' => [
                    'mode' => 'manual_ui_complete',
                    'note' => 'completed from remarketing screen',
                ],
            ]);

            $progress->current_step_id = $nextStep->id;
            $progress->current_step_order = $nextStep->step_order;
            $progress->status = LeadRemarketingProgress::STATUS_ACTIVE;
            $progress->last_step_completed_at = $now;

            $followingStep = $steps->first(fn (RemarketingStep $step) => $step->step_order > $nextStep->step_order);
            if ($followingStep) {
                $nextRaw = $now->copy()->addMinutes((int) $followingStep->delay_minutes);
                $progress->next_step_due_at = app(RemarketingScheduleWindowService::class)->nextAllowedTime($followingStep, $nextRaw);
            } else {
                $progress->status = LeadRemarketingProgress::STATUS_COMPLETED;
                $progress->next_step_due_at = null;
            }

            $progress->save();

            return [
                'ok' => true,
                'message' => 'Manual step completed.',
            ];
        });

        return redirect()->back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    private function completeLinkedManualStepLog(RemarketingTask $task): ?LeadRemarketingStepLog
    {
        $log = LeadRemarketingStepLog::query()
            ->where('created_task_id', $task->id)
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return null;
        }

        $completedAt = $log->completed_at ?? now();
        $log->status = 'completed';
        $log->execution_status = 'completed_manual_task';
        $log->completed_at = $completedAt;
        $log->updated_at = now();
        $log->save();

        return $log;
    }

    private function advanceProgressForCompletedManualTask(?LeadRemarketingStepLog $log): void
    {
        if (! $log) {
            return;
        }

        $progress = LeadRemarketingProgress::query()
            ->where('lead_id', (int) $log->lead_id)
            ->whereIn('status', [
                LeadRemarketingProgress::STATUS_ACTIVE,
                LeadRemarketingProgress::STATUS_PENDING_MANUAL_TASK,
            ])
            ->orderByDesc('id')
            ->first();

        if (! $progress) {
            Log::warning('Manual remarketing task completed without active progress row', [
                'lead_id' => $log->lead_id,
                'step_log_id' => $log->id,
                'task_id' => $log->created_task_id,
            ]);

            return;
        }

        $currentStep = null;
        if ($log->remarketing_step_id !== null) {
            $currentStep = RemarketingStep::query()->find((int) $log->remarketing_step_id);
        }
        if (! $currentStep && $progress->current_step_id !== null) {
            $currentStep = RemarketingStep::query()->find((int) $progress->current_step_id);
        }

        $stepsQuery = RemarketingStep::query()
            ->where('is_active', true)
            ->orderBy('step_order');

        if ($currentStep && str_starts_with((string) $currentStep->step_key, 'cbna_')) {
            $stepsQuery->where('step_key', 'like', 'cbna_%');
        }

        $nextStep = $stepsQuery
            ->where('step_order', '>', (int) ($log->step_order ?? $progress->current_step_order ?? 0))
            ->first();

        $now = now();

        if (! $nextStep) {
            $progress->status = LeadRemarketingProgress::STATUS_COMPLETED;
            $progress->next_step_due_at = null;
            $progress->last_step_completed_at = $now;
            if (Schema::hasColumn('lead_remarketing_progress', 'stopped_at')) {
                $progress->stopped_at = $progress->stopped_at ?? $now;
            }
            if (Schema::hasColumn('lead_remarketing_progress', 'stop_reason')) {
                $progress->stop_reason = 'journey_completed';
            }
            $progress->save();

            Log::info('Manual remarketing task completion finished journey', [
                'lead_id' => $log->lead_id,
                'step_log_id' => $log->id,
                'task_id' => $log->created_task_id,
            ]);

            return;
        }

        $progress->current_step_id = $nextStep->id;
        $progress->current_step_order = $nextStep->step_order;
        $progress->status = LeadRemarketingProgress::STATUS_ACTIVE;
        $progress->last_step_completed_at = $now;
        $progress->next_step_due_at = $this->scheduleWindowService->nextAllowedTime(
            $nextStep,
            $now->copy()->addMinutes((int) $nextStep->delay_minutes)
        );
        $progress->save();

        Log::info('Manual remarketing task completion advanced progress', [
            'lead_id' => $log->lead_id,
            'step_log_id' => $log->id,
            'task_id' => $log->created_task_id,
            'next_step_id' => $nextStep->id,
            'next_step_key' => $nextStep->step_key,
            'next_step_order' => $nextStep->step_order,
        ]);
    }

    /**
     * Step 2: after a remarketing CALL task is completed, branch on latest VICIdial disposition.
     */
    private function remarketingStepTwoAfterCallCompleted(RemarketingTask $task): void
    {
        if ($task->task_type !== 'call') {
            return;
        }

        $vicidialLeadId = $task->lead_id;
        if ($vicidialLeadId === null || (int) $vicidialLeadId <= 0) {
            return;
        }

        $lead = Lead::query()
            ->where('vicidial_lead_id', (int) $vicidialLeadId)
            ->first();

        if ($lead === null) {
            return;
        }

        if ($lead->wip_status === 'DEAD') {
            return;
        }

        $latest = $this->vicidialDispositionService->getLatestStatusForLead($lead->fresh());
        if ($latest === null) {
            return;
        }

        $code = strtoupper(trim($latest));

        if (in_array($code, self::REMARKETING_TERMINAL_DISPOSITIONS, true)) {
            if ($lead->wip_status === Lead::WIP_STATUS_REENGAGED) {
                return;
            }

            $lead->update(['wip_status' => 'DEAD']);

            RemarketingTask::query()
                ->where('lead_id', (int) $vicidialLeadId)
                ->where('status', RemarketingTask::STATUS_PENDING)
                ->update([
                    'status' => RemarketingTask::STATUS_CLOSED,
                ]);

            return;
        }

        if ($code !== 'NA') {
            return;
        }

        if ($task->stage === 'cooling') {
            $this->remarketingTaskService->createTaskForLeadTriggerWithResult(
                $lead->fresh(),
                'whatsapp',
                'cooling_whatsapp_follow_up',
                [
                    'stage' => 'cooling',
                    'time_waiting_text' => '0h',
                ]
            );

            return;
        }

        if ($task->stage === 'fresh') {
            $this->remarketingTaskService->createTaskForLeadTriggerWithResult(
                $lead->fresh(),
                'whatsapp',
                'whatsapp_follow_up',
                [
                    'stage' => 'fresh',
                    'time_waiting_text' => '0h',
                ]
            );
        }
    }

}

