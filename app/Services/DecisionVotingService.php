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
            $house = $route['house'] ?? ($route['unresolved'] ?? false ? 'Unresolved representative' : trim((string) ($debt->creditor?->voting_house ?: 'Independent / ungrouped')));
            $balance = (float) $debt->balance;
            $percent = $total > 0 ? round(($balance / $total) * 100, 4) : 0.0;

            $rules = $this->rulesFor((int) $debt->creditor_id, $route['voting_house_id'] ?? null, $partnerKey, $ipKey);
            $companyRules = in_array($partnerKey,['assure','lawson_fox','tig'],true)
                ? $this->rulesFor((int) $debt->creditor_id, $route['voting_house_id'] ?? null, 'avondale_ac', null)
                : [];
            $rows[] = [
                'debt_id' => $debt->id,
                'creditor_id' => $debt->creditor_id,
                'creditor' => $debt->creditor?->name,
                'balance' => $balance,
                'percent_of_known_debt' => $percent,
                'voting_house' => $house,
                'route_id' => $route['id'] ?? null,
                'route_source' => $route['source'] ?? 'legacy_creditor_field',
                'route_candidates' => $route['candidates'] ?? [],
                'route_conflict' => $route['conflict'] ?? false,
                'route_conflict_reason' => $route['conflict_reason'] ?? null,
                'applicable_rules' => $rules,
                'supporting_company_rules' => $companyRules,
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

        $unresolved = collect($rows)->where('route_source','unresolved_conflict');
        $unresolvedBalance = round((float)$unresolved->sum('balance'),2);

        return [
            'qualifying_debt_total' => round($total, 2),
            'partner_key' => $partnerKey,
            'ip_key' => $ipKey,
            'houses' => array_values($houseTotals),
            'debts' => $rows,
            'unresolved_representative_count' => $unresolved->count(),
            'unresolved_voting_debt_total' => $unresolvedBalance,
            'unresolved_voting_percent' => $total > 0 ? round(($unresolvedBalance/$total)*100,4) : 0.0,
            'warning' => 'Voting exposure is deterministic from known debts. Rule consequences must be assessed from applicable sourced rules; a breached representative rule is not automatically a route rejection. Any unresolved representative amount must remain unknown until the mapping conflict is resolved.',
        ];
    }

    private function resolveRoute(int $creditorId, ?string $partnerKey, ?string $ipKey): array
    {
        if (!Schema::hasTable('creditor_voting_routes')) return [];

        $isAvondale = in_array($partnerKey,['assure','lawson_fox','tig'],true);
        $query = DB::table('creditor_voting_routes as r')
            ->leftJoin('voting_houses as h', 'h.id', '=', 'r.voting_house_id')
            ->leftJoin('decision_rule_sources as s', 's.id', '=', 'r.source_id')
            ->where('r.creditor_id', $creditorId)
            ->where('r.is_active', true)
            ->where(fn($q) => $q->whereNull('r.effective_from')->orWhere('r.effective_from', '<=', now()->toDateString()))
            ->where(fn($q) => $q->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', now()->toDateString()))
            ->where(function($q) use ($partnerKey,$isAvondale) {
                $q->whereNull('r.partner_key');
                if ($partnerKey) $q->orWhere('r.partner_key',$partnerKey);
                if ($isAvondale) $q->orWhere('r.partner_key','avondale_ac');
            })
            ->where(fn($q) => $q->whereNull('r.ip_key')->orWhere('r.ip_key', $ipKey))
            ->select('r.*','h.key as house','s.source_type','s.name as source_name','s.sheet as source_sheet','s.location as source_location')
            ->get()
            ->map(function($r) use ($partnerKey,$isAvondale) {
                $r->scope_rank = $r->partner_key === $partnerKey ? 0 : (($isAvondale && $r->partner_key === 'avondale_ac') ? 1 : 2);
                return $r;
            })
            ->sortBy(fn($r) => sprintf('%02d-%04d-%01d',$r->scope_rank,$r->priority,$r->is_default?0:1))
            ->values();

        if ($query->isEmpty()) return [];

        $sourced = $query->whereNotNull('source_id')->values();
        $pool = $sourced->isNotEmpty() ? $sourced : $query;
        $bestScope = (int)$pool->min('scope_rank');
        $scopePool = $pool->where('scope_rank',$bestScope)->values();
        $bestPriority = (int)$scopePool->min('priority');
        $best = $scopePool->where('priority',$bestPriority)->values();
        $bestHouses = $best->pluck('house')->filter()->unique()->values();
        $allHouses = $pool->pluck('house')->filter()->unique()->values();
        $conflict = $allHouses->count() > 1;
        $unresolved = $bestHouses->count() > 1;
        $route = $unresolved ? null : $best->first();

        $candidates = $pool->map(fn($r)=>[
            'route_id'=>$r->id,'house'=>$r->house,'partner_key'=>$r->partner_key,'priority'=>$r->priority,
            'condition'=>$r->condition_text,'source_type'=>$r->source_type,'source_name'=>$r->source_name,
            'source_sheet'=>$r->source_sheet,'source_location'=>$r->source_location,'selected'=>$route && $route->id===$r->id,
        ])->all();

        return [
            'id' => $route?->id,
            'voting_house_id' => $route?->voting_house_id,
            'house' => $route?->house,
            'source' => $route ? ($route->source_id ? 'sourced_route' : 'migrated_or_manual_route') : 'unresolved_conflict',
            'candidates' => $candidates,
            'conflict' => $conflict,
            'unresolved' => $unresolved,
            'conflict_reason' => $unresolved
                ? 'Multiple equally preferred sourced representative mappings apply; account/product detail or an operator ruling is required.'
                : ($conflict ? 'Lower-precedence source mappings disagree with the selected mapping; the conflict is retained for audit.' : null),
        ];
    }

    private function rulesFor(int $creditorId, ?int $houseId, ?string $partnerKey, ?string $ipKey): array
    {
        if (!Schema::hasTable('decision_rules')) return [];

        return DB::table('decision_rules as r')
            ->leftJoin('decision_rule_sources as s', 's.id', '=', 'r.source_id')
            ->where('r.is_active', true)
            ->where(function($q) use ($creditorId,$houseId) {
                $q->where('r.creditor_id',$creditorId);
                if ($houseId) $q->orWhere('r.voting_house_id',$houseId);
            })
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
