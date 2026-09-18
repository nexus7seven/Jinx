<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class PropertyDecisionService
{
    public function __construct(
        private readonly DecisionCaseFactService $facts,
        private readonly DecisionVotingService $voting,
    ) {}

    public function evaluate(Lead $lead, ?string $destinationKey = null): array
    {
        $values = $this->facts->leadValues($lead);
        $homeowner = $this->bool($values['property.is_homeowner'] ?? null);

        if ($homeowner === false) {
            return [
                'status'=>'NOT_APPLICABLE',
                'is_homeowner'=>false,
                'calculation'=>null,
                'missing_facts'=>[],
                'route_rules'=>$this->rules($lead,$destinationKey),
                'instruction'=>'Client is recorded as not being a homeowner. Do not infer an ownership interest from a secured loan or another person’s mortgage without evidence of legal or beneficial ownership.',
            ];
        }

        if ($homeowner === null) {
            return [
                'status'=>'UNKNOWN',
                'is_homeowner'=>null,
                'calculation'=>null,
                'missing_facts'=>['property.is_homeowner'],
                'route_rules'=>$this->rules($lead,$destinationKey),
                'instruction'=>'Homeownership has not been established. Do not infer ownership from mortgage/secured-loan liability alone.',
            ];
        }

        $missing = [];
        foreach (['property.value','property.mortgage_balance','property.ownership_percent'] as $key) {
            if (!array_key_exists($key,$values) || !is_numeric($values[$key])) $missing[]=$key;
        }

        $secured = isset($values['property.secured_loans_total']) && is_numeric($values['property.secured_loans_total'])
            ? (float)$values['property.secured_loans_total'] : 0.0;

        $calc = null;
        if (!$missing) {
            $value=(float)$values['property.value'];
            $mortgage=(float)$values['property.mortgage_balance'];
            $share=max(0,min(100,(float)$values['property.ownership_percent']));
            $gross=round($value-$mortgage-$secured,2);
            $attributable=round(max(0,$gross)*($share/100),2);
            $calc=[
                'property_value'=>round($value,2),
                'mortgage_balance'=>round($mortgage,2),
                'secured_loans_total'=>round($secured,2),
                'gross_equity'=>round($gross,2),
                'ownership_percent'=>$share,
                'client_attributable_equity'=>$attributable,
                'formula'=>'max(0, property value - mortgage - secured lending) × client ownership share',
            ];
        }

        $rules=$this->rules($lead,$destinationKey);
        return [
            'status'=>$missing ? 'UNKNOWN' : 'CALCULATED',
            'is_homeowner'=>true,
            'facts'=>collect($values)->filter(fn($v,$k)=>str_starts_with($k,'property.'))->all(),
            'calculation'=>$calc,
            'missing_facts'=>$missing,
            'route_rules'=>$rules,
            'instruction'=>'Apply only the client’s evidenced ownership/beneficial share. Compare the calculated property position with the supplied route and creditor/voting rules; this calculator does not itself decide whether an IVA is acceptable.',
        ];
    }

    private function rules(Lead $lead, ?string $destinationKey): array
    {
        $keys=array_values(array_filter([$destinationKey]));
        if (in_array($destinationKey,['lawson_fox','assure','tig'],true)) $keys[]='avondale_ac';
        if (!$keys) return [];

        $lead->loadMissing('debts');
        $creditorIds=$lead->debts->pluck('creditor_id')->filter()->unique()->values()->all();
        $voting=$this->voting->analyse($lead,$destinationKey,null);
        $houseNames=collect($voting['debts'])->pluck('voting_house')->filter(fn($x)=>$x && $x!=='Unresolved representative')->unique()->values();
        $houseIds=$houseNames->isEmpty()?[]:DB::table('voting_houses')->whereIn('key',$houseNames)->pluck('id')->all();

        return DB::table('decision_rules as r')
            ->leftJoin('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('r.is_active',true)
            ->whereIn('r.partner_key',array_values(array_unique($keys)))
            ->where('r.category','property')
            ->where(function($q) use ($creditorIds,$houseIds) {
                $q->where(function($x){$x->whereNull('r.creditor_id')->whereNull('r.voting_house_id');});
                if ($creditorIds) $q->orWhereIn('r.creditor_id',$creditorIds);
                if ($houseIds) $q->orWhereIn('r.voting_house_id',$houseIds);
            })
            ->select('r.id','r.partner_key','r.scope_type','r.creditor_id','r.voting_house_id','r.requirement_text','r.severity',
                's.source_type','s.name as source_name','s.sheet','s.location','s.original_text')
            ->orderBy('r.partner_key')->orderBy('r.id')
            ->get()->map(fn($r)=>(array)$r)->all();
    }

    private function bool(mixed $value): ?bool
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value;
        return filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    }
}
