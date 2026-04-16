<?php

namespace App\Http\Controllers;

use App\Models\DebtDocument;
use App\Models\Lead;
use App\Models\LeadChecklistItem;
use App\Models\RemarketingTask;
use App\Services\LeadChecklistService;
use App\Services\LeadOpsAlertEligibility;
use App\Services\RemarketingEntryService;
use App\Services\RemarketingTaskService;
use App\Services\VicidialDialActivityService;
use App\Services\VicidialLeadLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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

        return view('wip.index', [
            'leads' => $leads,
            'statuses' => Lead::WIP_STATUSES,
            'show' => $show,
            'ops_alert_leads' => $opsAlertLeads,
            'wip_source_filter_options' => $wipSourceFilterOptions,
        ]);
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
                            ->where('status', 'pending')
                            ->update([
                                'status' => 'completed',
                            ]);

                        RemarketingTask::create([
                            'lead_id' => $vicidialLeadId,
                            'lead_name' => $fullName !== '' ? $fullName : ('Lead #' . $freshLead->id),
                            'phone' => (string) ($freshLead->phone_number ?? ''),
                            'campaign_id' => 'MAIN',
                            'task_type' => 'flow_started',
                            'reason' => 'flow_start',
                            'stage' => 'fresh',
                            'status' => 'completed',
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
                            ->where('status', 'pending')
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
