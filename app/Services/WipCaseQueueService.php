<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use App\Models\WipCaseQueueItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WipCaseQueueService
{
    private const STEP = 1000;

    public const WAITING_ON = [
        'client' => 'Client',
        'creditor' => 'Creditor',
        'ip_provider' => 'IP / IVA provider',
        'documents' => 'Documents',
        'internal' => 'Internal action',
        'review_later' => 'Nothing / review later',
        'other' => 'Other',
    ];

    public function sync(User $user, Collection $leads): Collection
    {
        if ($leads->isEmpty()) {
            return $leads;
        }

        $ids = $leads->pluck('id')->map(fn ($id) => (int) $id)->values();
        $items = WipCaseQueueItem::query()
            ->where('user_id', $user->id)
            ->whereIn('lead_id', $ids)
            ->get()
            ->keyBy('lead_id');

        $missing = $leads->filter(fn (Lead $lead) => ! $items->has($lead->id))->values();

        if ($missing->isNotEmpty()) {
            DB::transaction(function () use ($user, $missing, $items): void {
                if ($items->isEmpty()) {
                    foreach ($missing as $index => $lead) {
                        WipCaseQueueItem::query()->updateOrCreate(
                            ['user_id' => $user->id, 'lead_id' => $lead->id],
                            ['position' => ($index + 1) * self::STEP]
                        );
                    }
                    return;
                }

                $min = (int) $items->min('position');
                $start = $min - ($missing->count() * self::STEP);

                foreach ($missing as $index => $lead) {
                    WipCaseQueueItem::query()->updateOrCreate(
                        ['user_id' => $user->id, 'lead_id' => $lead->id],
                        ['position' => $start + ($index * self::STEP)]
                    );
                }
            });

            $items = WipCaseQueueItem::query()
                ->where('user_id', $user->id)
                ->whereIn('lead_id', $ids)
                ->get()
                ->keyBy('lead_id');
        }

        return $leads
            ->map(function (Lead $lead) use ($items) {
                $item = $items->get($lead->id);
                $lead->setRelation('wipQueueItem', $item);
                $lead->wip_queue_position = (int) ($item?->position ?? PHP_INT_MAX);
                $lead->wip_waiting_on_label = $this->waitingOnLabel($item?->waiting_on);
                $lead->wip_chase_due = $item?->next_chase_at?->lte(now()) ?? false;
                return $lead;
            })
            ->sortBy(fn (Lead $lead) => $lead->wip_queue_position)
            ->values();
    }

    public function reorder(User $user, array $leadIds): void
    {
        $leadIds = array_values(array_unique(array_map('intval', $leadIds)));

        DB::transaction(function () use ($user, $leadIds): void {
            foreach ($leadIds as $index => $leadId) {
                WipCaseQueueItem::query()->updateOrCreate(
                    ['user_id' => $user->id, 'lead_id' => $leadId],
                    ['position' => ($index + 1) * self::STEP]
                );
            }
        });
    }

    public function actioned(
        User $user,
        Lead $lead,
        string $waitingOn,
        ?string $nextChaseAt,
        ?string $note
    ): WipCaseQueueItem {
        return DB::transaction(function () use ($user, $lead, $waitingOn, $nextChaseAt, $note) {
            $max = (int) WipCaseQueueItem::query()
                ->where('user_id', $user->id)
                ->max('position');

            return WipCaseQueueItem::query()->updateOrCreate(
                ['user_id' => $user->id, 'lead_id' => $lead->id],
                [
                    'position' => $max + self::STEP,
                    'waiting_on' => $waitingOn,
                    'next_chase_at' => $nextChaseAt,
                    'last_actioned_at' => now(),
                    'action_note' => filled($note) ? trim((string) $note) : null,
                ]
            )->fresh();
        });
    }

    public function waitingOnLabel(?string $waitingOn): ?string
    {
        if ($waitingOn === null || $waitingOn === '') {
            return null;
        }

        return self::WAITING_ON[$waitingOn] ?? $waitingOn;
    }

    public function payload(WipCaseQueueItem $item): array
    {
        return [
            'lead_id' => (int) $item->lead_id,
            'position' => (int) $item->position,
            'waiting_on' => $item->waiting_on,
            'waiting_on_label' => $this->waitingOnLabel($item->waiting_on),
            'next_chase_at' => $item->next_chase_at?->toIso8601String(),
            'next_chase_display' => $item->next_chase_at?->format('D j M, H:i'),
            'chase_due' => $item->next_chase_at?->lte(now()) ?? false,
            'last_actioned_at' => $item->last_actioned_at?->toIso8601String(),
            'last_actioned_display' => $item->last_actioned_at?->diffForHumans(),
            'action_note' => $item->action_note,
        ];
    }
}
