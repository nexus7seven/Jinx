<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\RemarketingTask;
use App\Services\RemarketingCallbackService;
use App\Services\RemarketingTaskService;
use App\Services\VicidialDispositionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    ) {
    }

    public function index(Request $request)
    {
        $stages = ['all', 'fresh', 'cooling', 'cold', 'dormant'];
        $selectedStage = $request->query('stage', 'all');
        if (! in_array($selectedStage, $stages, true)) {
            $selectedStage = 'all';
        }

        $pendingTasks = RemarketingTask::query()
            ->where('status', RemarketingTask::STATUS_PENDING)
            ->orderBy('id')
            ->get();

        $leadIds = $pendingTasks
            ->pluck('lead_id')
            ->filter(fn ($leadId) => $leadId !== null)
            ->map(fn ($leadId) => (int) $leadId)
            ->unique()
            ->values();

        $latestFlowStartedIds = RemarketingTask::query()
            ->whereIn('lead_id', $leadIds)
            ->where('task_type', 'flow_started')
            ->where('reason', self::FLOW_START_REASON)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_id')
            ->map(fn ($tasks) => (int) $tasks->first()->id);

        $latestStopMarkerIds = RemarketingTask::query()
            ->whereIn('lead_id', $leadIds)
            ->where('task_type', 'whatsapp')
            ->where('reason', self::DORMANT_FINAL_WHATSAPP_REASON)
            ->whereIn('status', [
                RemarketingTask::STATUS_PENDING,
                RemarketingTask::STATUS_COMPLETED,
                RemarketingTask::STATUS_CLOSED,
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('lead_id')
            ->map(fn ($tasks) => (int) $tasks->first()->id);

        $pendingTasks = $pendingTasks->filter(function (RemarketingTask $task) use ($latestFlowStartedIds, $latestStopMarkerIds, $selectedStage) {
            if ($selectedStage !== 'all' && $task->stage !== $selectedStage) {
                return false;
            }

            if ($task->lead_id === null) {
                return false;
            }

            $leadId = (int) $task->lead_id;
            $latestFlowStartedId = $latestFlowStartedIds->get($leadId);
            if ($latestFlowStartedId === null || (int) $task->id <= $latestFlowStartedId) {
                return false;
            }

            $latestStopMarkerId = $latestStopMarkerIds->get($leadId);

            return $latestStopMarkerId === null || $latestStopMarkerId <= $latestFlowStartedId;
        })->values();

        $callTasks = $pendingTasks
            ->where('task_type', 'call')
            ->values()
            ->map(function (RemarketingTask $task) {
                return [
                    'id' => $task->id,
                    'lead_id' => $task->lead_id,
                    'lead_name' => $task->lead_name,
                    'phone' => $task->phone,
                    'campaign_id' => $task->campaign_id,
                    'reason' => $task->reason,
                    'time_waiting' => $task->time_waiting_text ?? 'Waiting',
                    'task_type' => $task->task_type,
                    'stage' => $task->stage,
                ];
            })
            ->all();

        $whatsappTasks = $pendingTasks
            ->where('task_type', 'whatsapp')
            ->values()
            ->map(function (RemarketingTask $task) {
                $phone = str_replace(' ', '', $task->phone);
                if (str_starts_with($phone, '0')) {
                    $phone = '44' . substr($phone, 1);
                }
                $message = 'Hi ' . $task->lead_name . ', just following up in case WhatsApp is easier for you.';
                $whatsappUrl = 'https://wa.me/' . $phone . '?text=' . urlencode($message);

                return [
                    'id' => $task->id,
                    'lead_id' => $task->lead_id,
                    'lead_name' => $task->lead_name,
                    'phone' => $task->phone,
                    'campaign_id' => $task->campaign_id,
                    'reason' => $task->reason,
                    'time_waiting' => $task->time_waiting_text ?? 'Waiting',
                    'task_type' => $task->task_type,
                    'stage' => $task->stage,
                    'whatsapp_url' => $whatsappUrl,
                ];
            })
            ->all();

        $recentActivity = RemarketingTask::query()
            ->whereIn('status', [
                RemarketingTask::STATUS_STARTED,
                RemarketingTask::STATUS_COMPLETED,
                RemarketingTask::STATUS_CLOSED,
            ])
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(function (RemarketingTask $task) {
                if ($task->task_type === 'call' && $task->status === RemarketingTask::STATUS_STARTED) {
                    $activity = 'Call started';
                } elseif ($task->task_type === 'call' && $task->status === RemarketingTask::STATUS_COMPLETED) {
                    $activity = 'Call completed';
                } elseif ($task->task_type === 'call' && $task->status === RemarketingTask::STATUS_CLOSED) {
                    $activity = 'Call closed';
                } elseif ($task->task_type === 'whatsapp' && $task->status === RemarketingTask::STATUS_STARTED) {
                    $activity = 'WhatsApp started';
                } elseif ($task->task_type === 'whatsapp' && $task->status === RemarketingTask::STATUS_COMPLETED) {
                    $activity = 'WhatsApp completed';
                } elseif ($task->task_type === 'whatsapp' && $task->status === RemarketingTask::STATUS_CLOSED) {
                    $activity = 'WhatsApp closed';
                } else {
                    $activity = ucfirst((string) $task->task_type) . ' ' . ucfirst((string) $task->status);
                }

                return [
                    'lead_name' => $task->lead_name,
                    'activity' => $activity,
                    'time' => optional($task->updated_at)->diffForHumans() ?? 'Just now',
                ];
            })
            ->all();

        return view('remarketing.index', [
            'stages' => $stages,
            'selectedStage' => $selectedStage,
            'callTasks' => $callTasks,
            'whatsappTasks' => $whatsappTasks,
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
            ->with('success', 'Test task added.');
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
            $task->save();

            if ($task->task_type === 'call') {
                $this->remarketingStepTwoAfterCallCompleted($task);
            }
        }

        return redirect()
            ->route('remarketing.index', ['stage' => $validated['current_stage'] ?? 'all'])
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
                ->route('remarketing.index', ['stage' => $validated['current_stage'] ?? 'all'])
                ->with('error', 'Task is not available for calling.');
        }

        if (! $task->lead_id) {
            return redirect()
                ->route('remarketing.index', ['stage' => $validated['current_stage'] ?? 'all'])
                ->with('error', 'Task is missing lead_id.');
        }
        if (! $task->campaign_id) {
            return redirect()
                ->route('remarketing.index', ['stage' => $validated['current_stage'] ?? 'all'])
                ->with('error', 'Task is missing campaign_id.');
        }
        if (! $task->phone || trim((string) $task->phone) === '') {
            return redirect()
                ->route('remarketing.index', ['stage' => $validated['current_stage'] ?? 'all'])
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
            ->route('remarketing.index', ['stage' => $validated['current_stage'] ?? 'all'])
            ->with($flashType, $flashMessage);
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

