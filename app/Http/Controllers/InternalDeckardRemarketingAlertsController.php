<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadRemarketingProgress;
use App\Models\RemarketingResponseEvent;
use App\Models\RemarketingTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class InternalDeckardRemarketingAlertsController extends Controller
{
    private const STALE_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $now = now();
        $items = [];
        $diagnostics = [];
        $counts = [
            'manual_steps' => 0,
            'requires_attention' => 0,
            'reengaged' => 0,
            'needs_review' => 0,
        ];

        try {
            $manual = $this->manualTaskItems($now);
            $counts['manual_steps'] = count($manual);
            $items = array_merge($items, $manual);
        } catch (\Throwable $e) {
            $diagnostics[] = 'manual_tasks_failed: '.$e->getMessage();
        }

        try {
            $needsReview = $this->needsReviewItems($now);
            $counts['needs_review'] = count($needsReview);
            $items = array_merge($items, $needsReview);
        } catch (\Throwable $e) {
            $diagnostics[] = 'needs_review_failed: '.$e->getMessage();
        }

        try {
            $attention = $this->requiresAttentionItems($now);
            $counts['requires_attention'] = count($attention);
            $items = array_merge($items, $attention);
        } catch (\Throwable $e) {
            $diagnostics[] = 'requires_attention_failed: '.$e->getMessage();
        }

        try {
            $reengaged = $this->reengagedItems($now);
            $counts['reengaged'] = count($reengaged);
            $items = array_merge($items, $reengaged);
        } catch (\Throwable $e) {
            $diagnostics[] = 'reengaged_failed: '.$e->getMessage();
        }

        usort($items, fn (array $a, array $b) => ($b['_ts'] ?? 0) <=> ($a['_ts'] ?? 0));
        $items = array_map(function (array $item) {
            unset($item['_ts']);
            return $item;
        }, $items);

        return response()->json([
            'ok' => true,
            'counts' => $counts,
            'items' => $items,
            'health' => $this->healthState($now),
            'diagnostics' => $diagnostics,
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $configuredKey = trim((string) env('DECKARD_INTERNAL_KEY', ''));
        if ($configuredKey !== '') {
            return hash_equals($configuredKey, (string) $request->header('X-Deckard-Key', ''));
        }

        $ip = (string) $request->ip();

        return in_array($ip, ['127.0.0.1', '::1'], true);
    }

    private function manualTaskItems(Carbon $now): array
    {
        $rows = RemarketingTask::query()
            ->where('status', RemarketingTask::STATUS_PENDING)
            ->whereIn('task_type', ['call', 'whatsapp'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $leadsByVicidial = Lead::query()
            ->whereIn('vicidial_lead_id', $rows->pluck('lead_id')->filter()->unique()->values())
            ->get()
            ->keyBy(fn (Lead $lead) => (int) $lead->vicidial_lead_id);

        return $rows->map(function (RemarketingTask $task) use ($leadsByVicidial, $now): array {
            $lead = $leadsByVicidial->get((int) $task->lead_id);
            $leadId = $lead?->id;
            $ageBase = $task->created_at ?? $now;

            return [
                'type' => 'manual_task',
                'severity' => 'warning',
                'lead_id' => $leadId,
                'title' => 'Manual '.strtoupper((string) $task->task_type).' due',
                'detail' => trim((string) $task->reason).' ('.($task->stage ?? 'unknown').')',
                'age' => $this->safeAgeLabel($ageBase, $now),
                'url' => $this->buildLeadUrl($leadId),
                '_ts' => $ageBase?->timestamp ?? 0,
            ];
        })->all();
    }

    private function needsReviewItems(Carbon $now): array
    {
        $events = RemarketingResponseEvent::query()
            ->where('status', RemarketingResponseEvent::STATUS_NEEDS_REVIEW)
            ->orderByDesc('detected_at')
            ->limit(50)
            ->get();

        return $events->map(function (RemarketingResponseEvent $event) use ($now): array {
            $leadId = $event->jinx_lead_id;
            $at = $event->detected_at ?? $event->created_at ?? $now;

            return [
                'type' => 'needs_review',
                'severity' => 'critical',
                'lead_id' => $leadId,
                'title' => 'Inbound response needs review',
                'detail' => strtoupper((string) $event->channel).' message: '.trim((string) ($event->message_preview ?? 'No preview')),
                'age' => $this->safeAgeLabel($at, $now),
                'url' => $this->buildLeadUrl($leadId),
                '_ts' => $at?->timestamp ?? 0,
            ];
        })->all();
    }

    private function requiresAttentionItems(Carbon $now): array
    {
        $rows = LeadRemarketingProgress::query()
            ->where('status', LeadRemarketingProgress::STATUS_PENDING_MANUAL_TASK)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $leadsByVicidial = Lead::query()
            ->whereIn('vicidial_lead_id', $rows->pluck('lead_id')->filter()->unique()->values())
            ->get()
            ->keyBy(fn (Lead $lead) => (int) $lead->vicidial_lead_id);

        return $rows->map(function (LeadRemarketingProgress $progress) use ($leadsByVicidial, $now): array {
            $lead = $leadsByVicidial->get((int) $progress->lead_id);
            $leadId = $lead?->id;
            $at = $progress->updated_at ?? $progress->created_at ?? $now;

            return [
                'type' => 'requires_attention',
                'severity' => 'warning',
                'lead_id' => $leadId,
                'title' => 'Remarketing manual completion pending',
                'detail' => 'lead_remarketing_progress pending_manual_task (step_order '.($progress->current_step_order ?? 'n/a').')',
                'age' => $this->safeAgeLabel($at, $now),
                'url' => $this->buildLeadUrl($leadId),
                '_ts' => $at?->timestamp ?? 0,
            ];
        })->all();
    }

    private function reengagedItems(Carbon $now): array
    {
        return Lead::query()
            ->where('wip_status', Lead::WIP_STATUS_REENGAGED)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(function (Lead $lead) use ($now): array {
                $at = $lead->updated_at ?? $lead->created_at ?? $now;

                return [
                    'type' => 'reengaged',
                    'severity' => 'info',
                    'lead_id' => $lead->id,
                    'title' => 'Lead re-engaged',
                    'detail' => 'WIP status is Re-engaged',
                    'age' => $this->safeAgeLabel($at, $now),
                    'url' => $this->buildLeadUrl($lead->id),
                    '_ts' => $at?->timestamp ?? 0,
                ];
            })
            ->all();
    }

    private function healthState(Carbon $now): array
    {
        return [
            'scanner' => [
                'status' => $this->inferHealthState((bool) env('REMARKETING_CBNA_SCANNER_ENABLED', false), $this->safeLogMtime(storage_path('logs/remarketing-cbna-scan.log')), $now),
                'last_run' => $this->safeLogMtime(storage_path('logs/remarketing-cbna-scan.log')),
            ],
            'executor' => [
                'status' => $this->inferHealthState((bool) env('REMARKETING_LINEAR_EXECUTOR_ENABLED', false), $this->safeLogMtime(storage_path('logs/remarketing-linear-execute.log')), $now),
                'last_run' => $this->safeLogMtime(storage_path('logs/remarketing-linear-execute.log')),
            ],
            'scheduler' => [
                'status' => 'unknown',
            ],
        ];
    }

    private function safeAgeLabel($from, Carbon $now): string
    {
        if (! $from instanceof Carbon) {
            return 'unknown';
        }

        return str_replace([' ago', 'from now'], '', $from->diffForHumans($now, ['short' => true, 'parts' => 2]));
    }

    private function buildLeadUrl(?int $leadId): ?string
    {
        if (! $leadId) {
            return null;
        }

        return url('/lead/'.$leadId);
    }

    private function inferHealthState(bool $enabled, ?string $lastRun, Carbon $now): string
    {
        if (! $enabled) {
            return 'disabled';
        }

        if ($lastRun === null) {
            return 'unknown';
        }

        $runAt = Carbon::parse($lastRun);

        return $runAt->diffInSeconds($now) > self::STALE_SECONDS ? 'stale' : 'ok';
    }

    private function safeLogMtime(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);
        if (! is_int($mtime) || $mtime <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($mtime)->toIso8601String();
    }
}
