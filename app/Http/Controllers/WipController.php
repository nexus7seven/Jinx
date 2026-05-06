<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\DebtDocument;
use App\Models\Lead;
use App\Models\LeadChecklistItem;
use App\Models\LeadRemarketingProgress;
use App\Models\LeadReengagementEvent;
use App\Models\LeadRemarketingStepLog;
use App\Models\RemarketingResponseEvent;
use App\Models\RemarketingTask;
use App\Services\LeadChecklistService;
use App\Services\LeadOpsAlertEligibility;
use App\Services\RemarketingEntryService;
use App\Services\RemarketingTaskService;
use App\Services\VicidialDialActivityService;
use App\Services\VicidialLeadLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Throwable;

class WipController extends Controller
{
    private const PRIORITY_ORDER_SQL = "CASE WHEN wip_status IN ('Initial Assessment','Awaiting Call') THEN 0 ELSE 1 END";

    public function __construct(
        private LeadChecklistService $checklistService,
        private VicidialDialActivityService $dialActivityService,
        private LeadOpsAlertEligibility $opsAlertEligibility,
        private RemarketingEntryService $remarketingEntryService,
        private RemarketingTaskService $remarketingTaskService,
        private VicidialLeadLookupService $vicidialLeadLookupService,
    ) {
    }

    public function index(Request $request): View
    {
        $show = $request->get('show', 'active');

        $firstPass = Lead::query()
            ->when($show !== 'all', function ($query) {
                $query->whereNotIn('wip_status', Lead::WIP_STATUSES_EXCLUDED_FROM_ACTIVE_TAB);
            })
            ->orderByRaw(self::PRIORITY_ORDER_SQL)
            ->orderByDesc('created_at')
            ->get();

        foreach ($firstPass as $lead) {
            $this->checklistService->syncForLead($lead);
        }

        $leads = Lead::query()
            ->when($show !== 'all', function ($query) {
                $query->whereNotIn('wip_status', Lead::WIP_STATUSES_EXCLUDED_FROM_ACTIVE_TAB);
            })
            ->whereIn('id', $firstPass->pluck('id'))
            ->orderByRaw(self::PRIORITY_ORDER_SQL)
            ->orderByDesc('created_at')
            ->get();

        $leadIds = $leads->pluck('id');

        $counts = LeadChecklistItem::query()
            ->selectRaw('lead_id, COUNT(*) as total_count, SUM(CASE WHEN is_complete = 0 THEN 1 ELSE 0 END) as outstanding_count')
            ->whereIn('lead_id', $leadIds)
            ->groupBy('lead_id')
            ->get()
            ->keyBy('lead_id');

        $this->dialActivityService->attachLastDialledToLeads($leads);

        $leads = $leads->map(function ($lead) use ($counts) {
            $countRow = $counts->get($lead->id);

            $lead->checklist_total_count = $countRow ? (int) $countRow->total_count : 0;
            $lead->checklist_outstanding_count = $countRow ? (int) $countRow->outstanding_count : 0;

            return $lead;
        });

        $unseenReengagementLeadIds = LeadReengagementEvent::query()
            ->whereNull('seen_at')
            ->whereIn('lead_id', $leadIds)
            ->distinct()
            ->pluck('lead_id')
            ->all();

        $unseenReengagementLeadSet = array_flip(array_map('intval', $unseenReengagementLeadIds));

        // Unseen re-engagement is surfaced elsewhere (Attention Required / lead workflow); omit from main grid to avoid duplicate rows.
        $leads = $leads->filter(function (Lead $lead) use ($unseenReengagementLeadSet) {
            return ! isset($unseenReengagementLeadSet[(int) $lead->id]);
        })->values();

        $unseenReengagementEventIds = LeadReengagementEvent::query()
            ->whereNull('seen_at')
            ->whereIn('lead_id', $leadIds)
            ->pluck('id')
            ->values()
            ->all();

        $reengagementChannelByLeadId = [];
        if ($leadIds->isNotEmpty()) {
            $reengagementChannelByLeadId = LeadReengagementEvent::query()
                ->whereIn('lead_id', $leadIds)
                ->orderByDesc('id')
                ->get()
                ->unique('lead_id')
                ->mapWithKeys(fn (LeadReengagementEvent $e) => [(int) $e->lead_id => $e->channel])
                ->all();
        }

        $reengagementSortTier = static function (Lead $lead) use ($unseenReengagementLeadSet): int {
            if ($lead->wip_status !== Lead::WIP_STATUS_REENGAGED) {
                return 2;
            }

            return isset($unseenReengagementLeadSet[(int) $lead->id]) ? 0 : 1;
        };

        $leads = $leads->sort(function (Lead $a, Lead $b) use ($reengagementSortTier) {
            $aT = $reengagementSortTier($a);
            $bT = $reengagementSortTier($b);
            if ($aT !== $bT) {
                return $aT <=> $bT;
            }

            if ($aT < 2) {
                return $b->created_at <=> $a->created_at;
            }

            $aPri = in_array($a->wip_status, Lead::PRIORITY_WIP_STATUSES, true) ? 0 : 1;
            $bPri = in_array($b->wip_status, Lead::PRIORITY_WIP_STATUSES, true) ? 0 : 1;
            if ($aPri !== $bPri) {
                return $aPri <=> $bPri;
            }

            return $b->created_at <=> $a->created_at;
        })->values();

        $opsAlertLeads = $leads->map(function (Lead $lead) {
            return [
                'id' => $lead->id,
                'label' => $this->caseName($lead),
                'eligible' => $this->opsAlertEligibility->shouldNotifyNewLeadForOps($lead),
            ];
        })->values()->all();

        $wipSourceFilterOptions = $leads
            ->map(function (Lead $lead) {
                $s = $lead->source;
                if ($s === null) {
                    return '';
                }

                return trim((string) $s);
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        $remarketingResponseEvents = RemarketingResponseEvent::query()
            ->where('status', RemarketingResponseEvent::STATUS_NEEDS_REVIEW)
            ->with('jinxLead')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(function (RemarketingResponseEvent $event, int $index) {
                // Collapse call alerts to the latest per lead so repeated inbound calls
                // don't spam the inbox; the newest call remains for human handling.
                static $seenCallLeadIds = [];
                if (strtolower((string) $event->channel) !== 'call') {
                    return true;
                }

                $leadId = (int) $event->lead_id;
                if (isset($seenCallLeadIds[$leadId])) {
                    return false;
                }

                $seenCallLeadIds[$leadId] = true;

                return true;
            })
            ->take(20)
            ->map(function (RemarketingResponseEvent $event): array {
                $jinxLead = $event->jinxLead;
                $leadName = trim((string) ($jinxLead?->first_name ?? '').' '.(string) ($jinxLead?->last_name ?? ''));
                $now = now();
                $channel = strtolower((string) $event->channel);
                $payload = is_array($event->raw_payload_json) ? $event->raw_payload_json : [];
                $eventAt = $event->detected_at ?? $event->created_at;
                if ($channel === 'call') {
                    $payloadStartTime = $payload['start_time'] ?? null;
                    if (is_string($payloadStartTime) && trim($payloadStartTime) !== '') {
                        try {
                            $eventAt = Carbon::parse($payloadStartTime);
                        } catch (\Throwable) {
                            $eventAt = $event->created_at;
                        }
                    } else {
                        $eventAt = $event->created_at;
                    }
                }
                // Use model Carbon instances (timezone-aware) and ensure attention cards
                // always read as past detections in the UI.
                $detectedText = $eventAt?->diffForHumans($now, ['parts' => 2]) ?? 'Just now';
                $detectedText = str_replace(' from now', ' ago', $detectedText);

                return [
                    'id' => $event->id,
                    'lead_id' => $event->lead_id,
                    'jinx_lead_id' => $event->jinx_lead_id,
                    'lead_name' => $leadName !== '' ? $leadName : ('Lead '.$event->lead_id),
                    'phone' => $event->matched_phone ?: ($jinxLead?->phone_number ?: null),
                    'channel' => (string) $event->channel,
                    'message_preview' => $event->message_preview,
                    'detected_at' => $event->detected_at,
                    'detected_text' => $detectedText,
                    'event_at_ts' => $eventAt?->getTimestamp() ?? 0,
                    'call_from_phone' => $payload['from_phone'] ?? $payload['caller_code'] ?? null,
                    'call_to_phone' => $payload['to_phone'] ?? $payload['number_dialed'] ?? null,
                ];
            })
            ->sortByDesc(function (array $event): int {
                return (int) ($event['event_at_ts'] ?? 0);
            })
            ->take(20)
            ->values()
            ->all();

        return view('wip.index', [
            'leads' => $leads,
            'statuses' => Lead::WIP_STATUSES,
            'show' => $show,
            'ops_alert_leads' => $opsAlertLeads,
            'wip_source_filter_options' => $wipSourceFilterOptions,
            'unseen_reengagement_lead_set' => $unseenReengagementLeadSet,
            'unseen_reengagement_event_ids' => $unseenReengagementEventIds,
            'reengagement_channel_by_lead_id' => $reengagementChannelByLeadId,
            'remarketing_response_events' => $remarketingResponseEvents,
        ]);
    }

    public function handleResponseEvent(int $id, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['dead', 'awaiting_call', 'initial_assessment', 'callback', 'continue', 'ignore'])],
        ]);

        $decision = (string) $validated['decision'];
        $leadMissing = false;

        DB::transaction(function () use ($id, $decision, &$leadMissing): void {
            $event = RemarketingResponseEvent::query()->lockForUpdate()->findOrFail($id);
            $now = now();
            $lead = Lead::query()
                ->where('vicidial_lead_id', $event->lead_id)
                ->first();

            // Ignore is event-only: no lead status update, no progress stop, no stopped log.
            if ($decision === 'ignore') {
                $event->status = RemarketingResponseEvent::STATUS_IGNORED;
                $event->decision = $decision;
                $event->handled_at = $now;
                $event->handled_by = auth()->id();
                $event->save();

                return;
            }

            $statusMap = [
                'dead' => 'DEAD',
                'awaiting_call' => 'Awaiting Call',
                'initial_assessment' => 'Initial Assessment',
                'callback' => 'Callback',
            ];

            $targetWipStatus = $statusMap[$decision] ?? null;

            if ($targetWipStatus !== null) {
                if ($lead !== null) {
                    // Keep this aligned with updateStatus() which writes to wip_status.
                    $lead->wip_status = $targetWipStatus;
                    $lead->save();
                } else {
                    $leadMissing = true;
                }

                $progress = LeadRemarketingProgress::query()
                    ->where('lead_id', $event->lead_id)
                    ->orderByDesc('id')
                    ->first();

                if ($progress !== null && ! in_array((string) $progress->status, ['stopped', LeadRemarketingProgress::STATUS_COMPLETED], true)) {
                    $progress->status = 'stopped';
                    $progress->stopped_at = $now;
                    $progress->stop_reason = 'response_handled:'.$decision;
                    $progress->stop_context_json = [
                        'source' => 'remarketing_response_event',
                        'response_event_id' => $event->id,
                        'decision' => $decision,
                    ];
                    $progress->next_step_due_at = null;
                    $progress->save();

                    LeadRemarketingStepLog::query()->create([
                        'lead_id' => $event->lead_id,
                        'remarketing_step_id' => $progress->current_step_id,
                        'step_order' => $progress->current_step_order,
                        'medium' => null,
                        'template_id' => null,
                        'status' => 'stopped',
                        'due_at' => null,
                        'started_at' => null,
                        'completed_at' => $now,
                        'failed_at' => null,
                        'provider_message_id' => null,
                        'error_message' => null,
                        'created_task_id' => null,
                        'context_json' => [
                            'mode' => 'response_event_decision',
                            'response_event_id' => $event->id,
                            'decision' => $decision,
                            'note' => 'Flow stopped after inbound response was handled',
                        ],
                    ]);
                }
            }

            $event->status = $decision === 'ignore'
                ? RemarketingResponseEvent::STATUS_IGNORED
                : RemarketingResponseEvent::STATUS_HANDLED;
            $event->decision = $decision;
            $event->handled_at = $now;
            $event->handled_by = auth()->id();
            $event->save();
        });

        $message = 'Remarketing response handled: '.$decision.'.';
        if ($leadMissing) {
            $message .= ' Lead not found for VICIdial lead_id.';
        }

        return redirect()->back()->with('success', $message);
    }

    public function pollReengagement(): JsonResponse
    {
        $events = LeadReengagementEvent::query()
            ->whereNull('seen_at')
            ->whereNotNull('lead_id')
            ->with(['lead:id,first_name,last_name'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function (LeadReengagementEvent $e) {
                $lead = $e->lead;
                $name = '';
                if ($lead !== null) {
                    $name = trim((string) ($lead->first_name ?? '').' '.(string) ($lead->last_name ?? ''));
                }

                return [
                    'id' => $e->id,
                    'lead_id' => $e->lead_id,
                    'lead_name' => $name !== '' ? $name : ('Lead #'.$e->lead_id),
                ];
            })
            ->values()
            ->all();

        return response()->json(['events' => $events]);
    }

    public function acknowledgeReengagement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
        ]);

        LeadReengagementEvent::query()
            ->where('lead_id', $validated['lead_id'])
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function updateStatus(Request $request, Lead $lead): JsonResponse
    {
        $previousStatus = (string) $lead->wip_status;

        $validated = $request->validate([
            'wip_status' => ['required', Rule::in(Lead::WIP_STATUSES)],
        ]);

        $lead->update([
            'wip_status' => $validated['wip_status'],
        ]);

        if ($validated['wip_status'] === 'DEAD') {
            try {
                $vicidialLeadId = is_numeric($lead->vicidial_lead_id) ? (int) $lead->vicidial_lead_id : null;

                if ($vicidialLeadId !== null) {
                    LeadRemarketingProgress::query()
                        ->where('lead_id', $vicidialLeadId)
                        ->whereIn('status', [LeadRemarketingProgress::STATUS_ACTIVE, LeadRemarketingProgress::STATUS_WAITING])
                        ->update([
                            'status' => LeadRemarketingProgress::STATUS_STOPPED,
                            'stopped_at' => now(),
                            'stop_reason' => 'lead_marked_dead',
                            'next_step_due_at' => null,
                        ]);

                    RemarketingTask::query()
                        ->where('lead_id', $vicidialLeadId)
                        ->where('status', RemarketingTask::STATUS_PENDING)
                        ->update([
                            'status' => RemarketingTask::STATUS_CLOSED,
                        ]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($previousStatus === 'Lost Contact' && $validated['wip_status'] !== 'Lost Contact') {
            try {
                $freshLead = $lead->fresh();
                if ($freshLead !== null && $freshLead->wip_status !== 'DEAD') {
                    $vicidialLeadId = is_numeric($freshLead->vicidial_lead_id) ? (int) $freshLead->vicidial_lead_id : null;

                    if ($vicidialLeadId !== null) {
                        $first = trim((string) ($freshLead->first_name ?? ''));
                        $last = trim((string) ($freshLead->last_name ?? ''));
                        $fullName = trim($first . ' ' . $last);

                        RemarketingTask::query()
                            ->where('lead_id', $vicidialLeadId)
                            ->where('status', RemarketingTask::STATUS_PENDING)
                            ->update([
                                'status' => RemarketingTask::STATUS_CLOSED,
                            ]);

                        RemarketingTask::create([
                            'lead_id' => $vicidialLeadId,
                            'lead_name' => $fullName !== '' ? $fullName : ('Lead #' . $freshLead->id),
                            'phone' => (string) ($freshLead->phone_number ?? ''),
                            'campaign_id' => 'MAIN',
                            'task_type' => 'flow_started',
                            'reason' => 'flow_start',
                            'stage' => 'fresh',
                            'status' => RemarketingTask::STATUS_COMPLETED,
                            'time_waiting_text' => null,
                        ]);

                        Log::info('Remarketing flow_started marker created after WIP reset from Lost Contact', [
                            'lead_local_id' => $freshLead->id,
                            'vicidial_lead_id' => $vicidialLeadId,
                            'new_wip_status' => $validated['wip_status'],
                        ]);

                        $initialReason = $this->remarketingTaskService->resolveReason('no_answer');
                        $existingPendingFreshCall = RemarketingTask::query()
                            ->where('lead_id', $vicidialLeadId)
                            ->where('task_type', 'call')
                            ->where('status', RemarketingTask::STATUS_PENDING)
                            ->where('stage', 'fresh')
                            ->where('reason', $initialReason)
                            ->exists();

                        if (! $existingPendingFreshCall) {
                            $this->remarketingTaskService->createCallTaskForLead($freshLead, [
                                'campaign_id' => 'MAIN',
                                'reason' => $initialReason,
                                'stage' => 'fresh',
                                'time_waiting_text' => '0h',
                            ]);
                        }
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($validated['wip_status'] === 'Lost Contact' && $previousStatus !== 'Lost Contact') {
            try {
                $this->remarketingEntryService->enterRemarketingFlow($lead->fresh(), 'lost_contact');
                Log::info('Remarketing trigger executed', [
                    'trigger_status' => 'Lost Contact',
                    'trigger_source' => 'lost_contact',
                ]);

                $freshLead = $lead->fresh();
                $vicidialLeadId = is_numeric($freshLead?->vicidial_lead_id) ? (int) $freshLead->vicidial_lead_id : null;
                if ($vicidialLeadId !== null) {
                    $existingProgress = LeadRemarketingProgress::query()
                        ->where('lead_id', $vicidialLeadId)
                        ->exists();

                    if (! $existingProgress) {
                        LeadRemarketingProgress::query()->create([
                            'lead_id' => $vicidialLeadId,
                            'current_step_id' => null,
                            'current_step_order' => null,
                            'status' => 'active',
                            'started_at' => now(),
                            'last_step_completed_at' => null,
                            'next_step_due_at' => now(),
                            'stopped_at' => null,
                            'stop_reason' => null,
                            'stop_context_json' => null,
                        ]);

                        Log::info('Linear remarketing progress initialized from Lost Contact transition', [
                            'lead_local_id' => $freshLead?->id,
                            'vicidial_lead_id' => $vicidialLeadId,
                            'trigger_status' => 'Lost Contact',
                        ]);
                    } else {
                        Log::info('Linear remarketing progress already exists; initialization skipped', [
                            'lead_local_id' => $freshLead?->id,
                            'vicidial_lead_id' => $vicidialLeadId,
                            'trigger_status' => 'Lost Contact',
                        ]);
                    }
                } else {
                    Log::warning('Linear remarketing initialization skipped: missing vicidial_lead_id', [
                        'lead_local_id' => $freshLead?->id ?? $lead->id,
                        'trigger_status' => 'Lost Contact',
                    ]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (
            $validated['wip_status'] === 'Awaiting Call'
            && $previousStatus !== 'Awaiting Call'
            && $previousStatus !== 'Lost Contact'
        ) {
            try {
                $campaignId = $this->vicidialLeadLookupService->resolveCampaignIdForLead($lead->fresh());
                if ($campaignId === null) {
                    throw new RuntimeException('missing_vicidial_campaign_id');
                }

                $triggerResult = $this->remarketingTaskService->createTaskForLeadTriggerWithResult(
                    $lead->fresh(),
                    'call',
                    'callback_follow_up',
                    [
                        'campaign_id' => $campaignId,
                        'stage' => 'fresh',
                        'time_waiting_text' => '0h',
                    ]
                );
                Log::info('Remarketing trigger executed', [
                    'trigger_status' => 'Awaiting Call',
                    'task_type' => 'call',
                    'task_id' => $triggerResult['task']->id ?? null,
                    'lead_id' => $triggerResult['task']->lead_id ?? null,
                    'was_created' => (bool) ($triggerResult['was_created'] ?? false),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($validated['wip_status'] === 'Awaiting Docs' && $previousStatus !== 'Awaiting Docs') {
            try {
                $triggerResult = $this->remarketingTaskService->createTaskForLeadTriggerWithResult(
                    $lead->fresh(),
                    'whatsapp',
                    'whatsapp_follow_up',
                    [
                        'stage' => 'cooling',
                        'time_waiting_text' => '0h',
                    ]
                );
                Log::info('Remarketing trigger executed', [
                    'trigger_status' => 'Awaiting Docs',
                    'task_type' => 'whatsapp',
                    'task_id' => $triggerResult['task']->id ?? null,
                    'lead_id' => $triggerResult['task']->lead_id ?? null,
                    'was_created' => (bool) ($triggerResult['was_created'] ?? false),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'success' => true,
            'wip_status' => $lead->wip_status,
        ]);
    }

    public function checklist(Lead $lead): JsonResponse
    {
        $this->checklistService->syncForLead($lead);

        $items = LeadChecklistItem::query()
            ->where('lead_id', $lead->id)
            ->orderBy('is_complete')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'lead' => [
                'id' => $lead->id,
                'name' => $this->caseName($lead),
                'wip_status' => $lead->fresh()->wip_status,
            ],
            'items' => $items,
            'counts' => $this->checklistCounts($lead->id),
        ]);
    }

    public function storeChecklistItem(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
        ]);

        $item = LeadChecklistItem::create([
            'lead_id' => $lead->id,
            'item_name' => $validated['item_name'],
            'is_complete' => false,
            'source_type' => 'manual',
            'source_id' => null,
            'is_system' => false,
        ]);

        $this->checklistService->syncForLead($lead);

        return response()->json([
            'success' => true,
            'item' => $item,
            'counts' => $this->checklistCounts($lead->id),
            'wip_status' => $lead->fresh()->wip_status,
        ]);
    }

    public function updateChecklistItem(Request $request, Lead $lead, LeadChecklistItem $item): JsonResponse
    {
        abort_unless($item->lead_id === $lead->id, 404);

        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
        ]);

        $item->update([
            'item_name' => $validated['item_name'],
        ]);

        $this->checklistService->syncForLead($lead);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(),
            'counts' => $this->checklistCounts($lead->id),
            'wip_status' => $lead->fresh()->wip_status,
        ]);
    }

    public function toggleChecklistItem(Request $request, Lead $lead, LeadChecklistItem $item): JsonResponse
    {
        abort_unless($item->lead_id === $lead->id, 404);

        $validated = $request->validate([
            'is_complete' => ['required', 'boolean'],
        ]);

        $newState = (bool) $validated['is_complete'];

        $item->update([
            'is_complete' => $newState,
        ]);

        if ($item->source_type === 'debt_document' && $item->source_id) {
            $document = DebtDocument::find($item->source_id);

            if ($document) {
                $document->update([
                    'is_complete' => $newState,
                ]);
            }
        }

        $this->checklistService->syncForLead($lead);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(),
            'counts' => $this->checklistCounts($lead->id),
            'wip_status' => $lead->fresh()->wip_status,
        ]);
    }

    public function destroyChecklistItem(Lead $lead, LeadChecklistItem $item): JsonResponse
    {
        abort_unless($item->lead_id === $lead->id, 404);

        if ($item->source_type === 'debt_document' && $item->source_id) {
            $document = DebtDocument::find($item->source_id);

            if ($document) {
                $document->update([
                    'is_complete' => false,
                ]);
            }
        }

        $item->delete();

        $this->checklistService->syncForLead($lead);

        return response()->json([
            'success' => true,
            'counts' => $this->checklistCounts($lead->id),
            'wip_status' => $lead->fresh()->wip_status,
        ]);
    }

    private function checklistCounts(int $leadId): array
    {
        $total = LeadChecklistItem::query()
            ->where('lead_id', $leadId)
            ->count();

        $outstanding = LeadChecklistItem::query()
            ->where('lead_id', $leadId)
            ->where('is_complete', false)
            ->count();

        return [
            'total' => $total,
            'outstanding' => $outstanding,
        ];
    }

    private function caseName(Lead $lead): string
    {
        $first = trim((string) ($lead->first_name ?? ''));
        $last = trim((string) ($lead->last_name ?? ''));

        $fullName = trim($first . ' ' . $last);

        if ($fullName !== '') {
            return $fullName;
        }

        return 'Lead #' . $lead->id;
    }
}
