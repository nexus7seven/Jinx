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


    public function snapshot(Lead $lead, ?string $partnerKey = null, ?string $ipKey = null): array
    {
        $analysis = $this->analyse($lead, $partnerKey, $ipKey);

        return DB::transaction(function () use ($lead, $partnerKey, $ipKey, $analysis) {
            $snapshotId = DB::table('lead_voting_snapshots')->insertGetId([
                'lead_id' => $lead->id,
                'partner_key' => $partnerKey,
                'ip_key' => $ipKey,
                'qualifying_debt_total' => $analysis['qualifying_debt_total'],
                'summary' => json_encode([
                    'houses' => $analysis['houses'],
                    'unresolved_representative_count' => $analysis['unresolved_representative_count'],
                    'unresolved_voting_debt_total' => $analysis['unresolved_voting_debt_total'],
                    'unresolved_voting_percent' => $analysis['unresolved_voting_percent'],
                ]),
                'assessed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($analysis['debts'] as $debt) {
                $houseId = null;
                if ($debt['voting_house'] !== 'Unresolved representative') {
                    $houseId = DB::table('voting_houses')->whereRaw('LOWER(`key`) = ?', [strtolower((string)$debt['voting_house'])])->value('id');
                }

                DB::table('lead_voting_snapshot_debts')->insert([
                    'snapshot_id' => $snapshotId,
                    'debt_id' => $debt['debt_id'],
                    'creditor_id' => $debt['creditor_id'],
                    'voting_house_id' => $houseId,
                    'balance' => $debt['balance'],
                    'voting_percent' => $debt['percent_of_known_debt'],
                    'applicable_rule_ids' => json_encode(array_values(array_unique(array_map(
                        fn($r) => $r['id'],
                        array_merge($debt['applicable_rules'] ?? [], $debt['supporting_company_rules'] ?? [])
                    )))),
                    'assessment' => json_encode([
                        'voting_house' => $debt['voting_house'],
                        'route_source' => $debt['route_source'],
                        'route_candidates' => $debt['route_candidates'] ?? [],
                        'route_conflict' => $debt['route_conflict'] ?? false,
                        'route_conflict_reason' => $debt['route_conflict_reason'] ?? null,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [
                'success' => true,
                'snapshot_id' => $snapshotId,
                'lead_id' => $lead->id,
                'partner_key' => $partnerKey,
                'ip_key' => $ipKey,
                'qualifying_debt_total' => $analysis['qualifying_debt_total'],
                'unresolved_representative_count' => $analysis['unresolved_representative_count'],
            ];
        });
    }

    public function auditKnowledge(?Lead $lead = null, ?string $partnerKey = null): array
    {
        $unmatched = DB::table('decision_creditor_source_rows')
            ->whereNull('creditor_id')
            ->orderBy('source_name')->orderBy('sheet')->orderBy('source_row')
            ->get(['source_name','sheet','source_row','partner_key','creditor_name_raw','representative_key','status_text','detail_text'])
            ->map(fn($r)=>(array)$r)->all();

        $conflicts = DB::table('creditor_voting_routes as r')
            ->join('creditors as c','c.id','=','r.creditor_id')
            ->join('voting_houses as h','h.id','=','r.voting_house_id')
            ->where('r.is_active',true)->whereNotNull('r.partner_key')
            ->whereNotNull('r.source_id')
            ->select('r.creditor_id','c.name','r.partner_key',
                DB::raw('COUNT(DISTINCT h.key) as house_count'),
                DB::raw('GROUP_CONCAT(DISTINCT h.key ORDER BY h.key SEPARATOR ", ") as houses'))
            ->groupBy('r.creditor_id','c.name','r.partner_key')
            ->having('house_count','>',1)
            ->orderBy('r.partner_key')->orderBy('c.name')
            ->get()->map(fn($r)=>(array)$r)->all();

        $sources = DB::table('decision_rule_sources as s')
            ->select('s.name','s.sheet',DB::raw('COUNT(*) as source_rows'))
            ->where('s.source_type','workbook_decision_engine')
            ->groupBy('s.name','s.sheet')->orderBy('s.name')->orderBy('s.sheet')
            ->get()->map(fn($r)=>(array)$r)->all();

        $case = null;
        if ($lead) {
            $caseAnalysis = $this->analyse($lead, $partnerKey, null);
            $case = [
                'lead_id'=>$lead->id,
                'partner_key'=>$partnerKey,
                'unresolved_representative_count'=>$caseAnalysis['unresolved_representative_count'],
                'unresolved_voting_debt_total'=>$caseAnalysis['unresolved_voting_debt_total'],
                'unresolved_voting_percent'=>$caseAnalysis['unresolved_voting_percent'],
                'conflicted_debts'=>collect($caseAnalysis['debts'])->filter(fn($d)=>$d['route_conflict']??false)->values()->all(),
            ];
        }

        return [
            'unmatched_source_rows'=>$unmatched,
            'unmatched_source_row_count'=>count($unmatched),
            'multi_representative_mappings'=>$conflicts,
            'multi_representative_mapping_count'=>count($conflicts),
            'source_coverage'=>$sources,
            'case_audit'=>$case,
            'instruction'=>'Unmatched and conflicting source knowledge is intentionally retained. Do not invent a creditor match or choose between equally preferred representative mappings without a deterministic product/account condition or operator ruling.',
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
