<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DecisionVotingService
{
    public function analyse(Lead $lead, ?string $partnerKey = null, ?string $ipKey = null): array
    {
        $lead->loadMissing('debts.creditor');
        $total = (float) $lead->debts->sum('balance');
        $rows = [];
        $houseTotals = [];

        foreach ($lead->debts as $debt) {
            $route = $this->resolveRoute((int) $debt->creditor_id, $partnerKey, $ipKey);
            $house = $route['house'] ?? trim((string) ($debt->creditor?->voting_house ?: 'Independent / ungrouped'));
            $balance = (float) $debt->balance;
            $percent = $total > 0 ? round(($balance / $total) * 100, 4) : 0.0;

            $rules = $this->rulesFor((int) $debt->creditor_id, $route['voting_house_id'] ?? null, $partnerKey, $ipKey);
            $rows[] = [
                'debt_id' => $debt->id,
                'creditor_id' => $debt->creditor_id,
                'creditor' => $debt->creditor?->name,
                'balance' => $balance,
                'percent_of_known_debt' => $percent,
                'voting_house' => $house,
                'route_id' => $route['id'] ?? null,
                'route_source' => $route['source'] ?? 'legacy_creditor_field',
                'applicable_rules' => $rules,
            ];

            $houseTotals[$house] ??= ['name' => $house, 'balance' => 0.0, 'percent_of_known_debt' => 0.0, 'creditors' => []];
            $houseTotals[$house]['balance'] += $balance;
            $houseTotals[$house]['creditors'][] = $debt->creditor?->name;
        }

        foreach ($houseTotals as &$house) {
            $house['balance'] = round($house['balance'], 2);
            $house['percent_of_known_debt'] = $total > 0 ? round(($house['balance'] / $total) * 100, 4) : 0.0;
            $house['creditors'] = array_values(array_unique(array_filter($house['creditors'])));
        }

        return [
            'qualifying_debt_total' => round($total, 2),
            'partner_key' => $partnerKey,
            'ip_key' => $ipKey,
            'houses' => array_values($houseTotals),
            'debts' => $rows,
            'warning' => 'Voting exposure is deterministic from known debts. Rule consequences must be assessed from applicable sourced rules; a breached representative rule is not automatically a route rejection.',
        ];
    }

    private function resolveRoute(int $creditorId, ?string $partnerKey, ?string $ipKey): array
    {
        if (!Schema::hasTable('creditor_voting_routes')) return [];

        $query = DB::table('creditor_voting_routes as r')
            ->leftJoin('voting_houses as h', 'h.id', '=', 'r.voting_house_id')
            ->where('r.creditor_id', $creditorId)
            ->where('r.is_active', true)
            ->where(fn($q) => $q->whereNull('r.effective_from')->orWhere('r.effective_from', '<=', now()->toDateString()))
            ->where(fn($q) => $q->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', now()->toDateString()))
            ->where(fn($q) => $q->whereNull('r.partner_key')->orWhere('r.partner_key', $partnerKey))
            ->where(fn($q) => $q->whereNull('r.ip_key')->orWhere('r.ip_key', $ipKey))
            ->orderByRaw('CASE WHEN r.partner_key IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN r.ip_key IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('r.priority')
            ->orderByDesc('r.is_default')
            ->select('r.*', 'h.key as house');

        $route = $query->first();
        if (!$route) return [];

        return [
            'id' => $route->id,
            'voting_house_id' => $route->voting_house_id,
            'house' => $route->house,
            'source' => $route->source_id ? 'sourced_route' : 'migrated_or_manual_route',
        ];
    }

    private function rulesFor(int $creditorId, ?int $houseId, ?string $partnerKey, ?string $ipKey): array
    {
        if (!Schema::hasTable('decision_rules')) return [];

        return DB::table('decision_rules as r')
            ->leftJoin('decision_rule_sources as s', 's.id', '=', 'r.source_id')
            ->where('r.is_active', true)
            ->where(fn($q) => $q->whereNull('r.creditor_id')->orWhere('r.creditor_id', $creditorId))
            ->when($houseId, fn($q) => $q->where(fn($x) => $x->whereNull('r.voting_house_id')->orWhere('r.voting_house_id', $houseId)))
            ->where(fn($q) => $q->whereNull('r.partner_key')->orWhere('r.partner_key', $partnerKey))
            ->where(fn($q) => $q->whereNull('r.ip_key')->orWhere('r.ip_key', $ipKey))
            ->where(fn($q) => $q->whereNull('r.effective_from')->orWhere('r.effective_from', '<=', now()->toDateString()))
            ->where(fn($q) => $q->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', now()->toDateString()))
            ->select('r.id', 'r.scope_type', 'r.category', 'r.rule_key', 'r.condition_text', 'r.requirement_text', 'r.consequence_text', 'r.severity', 's.name as source_name', 's.sheet as source_sheet', 's.location as source_location', 's.original_text')
            ->orderBy('r.category')
            ->orderBy('r.id')
            ->get()
            ->map(fn($r) => (array) $r)
            ->all();
    }
}
