<?php
namespace App\Services;

use App\Models\Lead;
use RuntimeException;

class JinxAgentIvaService
{
    public function __construct(
        private readonly FinancialStatementService $statements,
        private readonly AssistantIeCalculationService $calculator,
        private readonly AssistantLeadFactSyncService $sync,
        private readonly PartnerKnowledgeService $knowledge,
        private readonly DecisionVotingService $voting,
        private readonly DecisionCaseFactService $decisionFacts,
    ) {}

    public function facts(Lead $lead): array
    {
        $s=$this->statements->mergeForLead($lead); $f=[];
        $put=function(string $k,mixed $v)use(&$f){if($v!==null)$f[$k]=$v;};
        $put('client.employment_status',$lead->employment_status);$put('case.estimated_total_debt',$lead->estimated_total_debt);
        $put('calculation.target_di',data_get($s,'facts.target_di'));$put('household.partner_exists',data_get($s,'household.partner_exists',false));
        $children=collect(data_get($s,'household.children',[]))->pluck('age')->filter(fn($x)=>$x!==null)->values()->all();$put('household.children_count',count(data_get($s,'household.children',[])));if($children)$put('household.children_ages',$children);
        foreach(['client_salary','partner_salary','self_employed','universal_credit','child_benefit','maintenance_received','pension','pip_dla','esa','carers_allowance','student','foster_guardianship','other_income','uc_advance_add_back'] as $k)$put('income.'.$k,data_get($s,'income.'.$k));
        $put('housing.type',data_get($s,'facts.housing_type'));foreach(['rent_mortgage','council_tax'] as $k)$put('housing.'.$k,data_get($s,'expenditure.housing.'.$k));
        foreach(['electricity','gas','water'] as $k)$put('utilities.'.$k,data_get($s,'expenditure.utilities.'.$k));
        $put('sfs.housekeeping',data_get($s,'expenditure.sfs.housekeeping'));
        foreach(['home_internet_tv','mobile','leisure'] as $k)$put('sfs.comms.'.$k,data_get($s,'expenditure.sfs.comms.'.$k));
        foreach(['clothing','hairdressing','toiletries'] as $k)$put('sfs.personal.'.$k,data_get($s,'expenditure.sfs.personal.'.$k));
        foreach(['client','partner'] as $who){$put("transport.$who.mode",data_get($s,"facts.{$who}_transport_mode"));foreach(['fuel','mot_maintenance','road_tax','car_finance','car_insurance','public_transport'] as $k)$put("transport.$who.$k",data_get($s,"expenditure.transport.$who.$k"));}
        foreach(['childcare','maintenance_paid','dla_care','pip_care','student_offset'] as $k)$put('other.'.$k,data_get($s,'expenditure.other.'.$k));
        return $f;
    }

    public function updateFact(Lead $lead,string $key,mixed $value): array
    {
        $allowed=$this->allowedFacts();if(!in_array($key,$allowed,true))throw new RuntimeException('Unsupported I&E fact key: '.$key);
        if(in_array($key,['household.partner_exists'],true))$value=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        elseif($key==='household.children_ages'){$value=array_values(array_filter(array_map('trim',explode(',',(string)$value)),fn($x)=>$x!==''));$value=array_map('intval',$value);}
        elseif(!in_array($key,['transport.client.mode','transport.partner.mode','client.employment_status','housing.type'],true)){if(!is_numeric($value))throw new RuntimeException('This I&E fact requires a numeric value.');$value=(float)$value;}
        $changed=$this->sync->sync($lead,[$key=>$value]);return ['lead_id'=>$lead->id,'fact'=>$key,'value'=>$value,'changed_fields'=>$changed];
    }

    public function calculate(Lead $lead): array
    {
        $facts=$this->facts($lead);$profile=$this->knowledge->profile($lead->source,$facts);$snapshot=$this->calculator->snapshot($facts,$profile);$changed=$this->sync->syncDeterministicIe($lead,$snapshot);return ['lead_id'=>$lead->id,'profile'=>['partner'=>$profile['partner'],'ip'=>$profile['ip']],'snapshot'=>$snapshot,'persisted_fields'=>$changed];
    }

    public function review(Lead $lead): array
    {
        $lead->loadMissing('debts.creditor');$facts=$this->facts($lead);$profile=$this->knowledge->profile($lead->source,$facts);$snapshot=$this->calculator->snapshot($facts,$profile);$total=(float)$lead->debts->sum('balance');
        $voting=$this->voting->analyse($lead,$profile['partner']??null,$profile['ip']??null);
        $check=\App\Models\LeadChecklistItem::where('lead_id',$lead->id)->get()->map(fn($i)=>['item_id'=>$i->id,'item'=>$i->item_name,'complete'=>(bool)$i->is_complete])->all();
        return ['lead_id'=>$lead->id,'name'=>$lead->formattedName(),'source'=>$lead->source,'profile'=>['partner'=>$profile['partner'],'ip'=>$profile['ip'],'basis'=>$profile['partner_basis']],'estimated_total_debt'=>$lead->estimated_total_debt,'known_debt_total'=>$total,'ie'=>$snapshot,'checklist'=>$check,'outstanding_checklist'=>array_values(array_filter($check,fn($x)=>!$x['complete'])),'voting_house_exposure'=>$voting['houses'],'voting_analysis'=>$voting,'instruction'=>'Use sourced decision rules and internal knowledge for the applicable partner/IP before making a packaging assessment. Voting-rule non-compliance is not automatically a rejection: assess its consequence against the whole voting position.'];
    }


    public function analyseRoute(Lead $lead, string $destination): array
    {
        $key = $this->destinationKey($destination);
        $lead->loadMissing('debts.creditor');
        $voting = $this->voting->analyse($lead, $key, null);
        $routeIe = $this->routeIe($lead, $destination);
        $general = \Illuminate\Support\Facades\DB::table('decision_rules as r')
            ->leftJoin('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('r.is_active',true)->where('r.partner_key',$key)
            ->whereNull('r.voting_house_id')->whereNull('r.creditor_id')
            ->select('r.id','r.scope_type','r.category','r.requirement_text','r.severity','s.source_type','s.name as source_name','s.sheet','s.location','s.original_text')
            ->orderBy('r.category')->orderBy('r.id')->get()->map(fn($r)=>(array)$r)->all();

        $partnerRules = $key !== 'zebra'
            ? \Illuminate\Support\Facades\DB::table('decision_rules as r')
                ->leftJoin('decision_rule_sources as s','s.id','=','r.source_id')
                ->where('r.is_active',true)->where('r.partner_key','avondale')
                ->whereNull('r.voting_house_id')->whereNull('r.creditor_id')
                ->select('r.id','r.scope_type','r.category','r.requirement_text','r.severity','s.source_type','s.name as source_name','s.sheet','s.location','s.original_text')
                ->orderBy('r.category')->orderBy('r.id')->get()->map(fn($r)=>(array)$r)->all()
            : [];

        $learning = $this->learnedGuidance($lead,$key,$voting,$routeIe);

        return [
            'lead_id'=>$lead->id, 'destination'=>$destination, 'destination_key'=>$key,
            'known_debt_total'=>$voting['qualifying_debt_total'], 'voting_house_exposure'=>$voting['houses'],
            'route_ie'=>$routeIe,
            'general_route_rules'=>$general,
            'partner_supporting_rules'=>$partnerRules,
            'company_supporting_rules'=>[],
            'debt_voting_analysis'=>$voting['debts'], 'learned_guidance'=>$learning,
            'instruction'=>'Assess the actual case facts against these sourced rules. Do not treat a voting-house rule breach as an automatic route failure; consider that house percentage, other voting exposure, stated modification/escalation options and relevant learned guidance. Authoritative workbook/internal rules remain distinct from operator corrections, techniques and precedents; learned guidance may refine reasoning but must not be presented as an official rule.',
        ];
    }


    public function learningSignature(Lead $lead, ?array $voting = null, ?array $ie = null): array
    {
        $lead->loadMissing('debts.creditor');
        $ieFacts=$this->facts($lead);
        $decision=$this->decisionFacts->allForLead($lead);
        $caseFacts=collect($decision['case_facts'] ?? [])->mapWithKeys(
            fn($value,$key)=>[$key=>$value['value'] ?? null]
        )->all();

        if ($ie===null) {
            $profile=$this->knowledge->profile($lead->source,$ieFacts);
            $ie=$this->calculator->snapshot($ieFacts,$profile);
        }
        $voting ??= $this->voting->analyse($lead,null,null);

        $debtProducts=collect($decision['debt_facts'] ?? [])
            ->map(fn($row)=>data_get($row,'facts.debt.product_type.value'))
            ->filter(fn($value)=>filled($value))
            ->map(fn($value)=>mb_strtolower(trim((string)$value)))
            ->unique()->sort()->values()->all();

        $creditors=$lead->debts
            ->pluck('creditor.name')->filter()
            ->map(fn($value)=>mb_strtolower(trim((string)$value)))
            ->unique()->sort()->values()->all();

        $houses=collect($voting['houses'] ?? [])
            ->pluck('name')->filter(fn($value)=>filled($value) && mb_strtolower(trim((string)$value))!=='unresolved representative')
            ->map(fn($value)=>mb_strtolower(trim((string)$value)))
            ->unique()->sort()->values()->all();

        $immigration=trim((string)($caseFacts['case.immigration_status'] ?? ''));
        $immigrationGroup=$immigration==='' ? null
            : (in_array(mb_strtolower($immigration),['uk citizen','british','british citizen'],true) ? 'british' : 'non_british');

        $gambling=$caseFacts['case.gambling_monthly'] ?? null;
        $gamblingBand=null;
        if (is_numeric($gambling)) {
            $gambling=(float)$gambling;
            $gamblingBand=$gambling<=0?'none':($gambling<=200?'up_to_200':($gambling<1000?'201_to_999':'1000_plus'));
        }

        $partnerExists=data_get($ieFacts,'household.partner_exists');
        if ($partnerExists===null) $partnerExists=(float)data_get($ieFacts,'income.partner_salary',0)>0;

        $selfEmployed=$caseFacts['case.self_employed'] ?? null;
        if ($selfEmployed===null && (float)data_get($ieFacts,'income.self_employed',0)>0) $selfEmployed=true;

        return [
            'source'=>filled($lead->source)?mb_strtolower(trim((string)$lead->source)):null,
            'known_debt_total'=>(float)$lead->debts->sum('balance'),
            'disposable_income'=>is_numeric(data_get($ie,'calculation.disposable_income'))?(float)data_get($ie,'calculation.disposable_income'):null,
            'income_total'=>is_numeric(data_get($ie,'calculation.income_total'))?(float)data_get($ie,'calculation.income_total'):null,
            'partner_exists'=>$partnerExists===null?null:(bool)$partnerExists,
            'homeowner'=>array_key_exists('property.is_homeowner',$caseFacts)?(bool)$caseFacts['property.is_homeowner']:null,
            'self_employed'=>$selfEmployed===null?null:(bool)$selfEmployed,
            'previous_iva'=>array_key_exists('case.previous_iva',$caseFacts)?(bool)$caseFacts['case.previous_iva']:null,
            'immigration_group'=>$immigrationGroup,
            'hmrc_majority'=>array_key_exists('case.hmrc_majority',$caseFacts)?(bool)$caseFacts['case.hmrc_majority']:null,
            'benefits_only'=>array_key_exists('case.benefits_only',$caseFacts)?(bool)$caseFacts['case.benefits_only']:null,
            'vulnerable_client'=>array_key_exists('case.vulnerable_client',$caseFacts)?(bool)$caseFacts['case.vulnerable_client']:null,
            'has_hmrc_debt'=>$lead->debts->contains(fn($debt)=>str_contains(mb_strtolower((string)($debt->creditor?->name ?? '')),'hmrc') || str_contains(mb_strtolower((string)($debt->creditor?->name ?? '')),'hm revenue')),
            'gambling_band'=>$gamblingBand,
            'voting_houses'=>$houses,
            'creditors'=>$creditors,
            'debt_products'=>$debtProducts,
        ];
    }

    private function learnedGuidance(Lead $lead,string $key,array $voting,array $routeIe): array
    {
        $target=$this->learningSignature($lead,$voting,$routeIe);
        $records=\Illuminate\Support\Facades\DB::table('decision_learning_records')
            ->where('status','active')
            ->orderByDesc('last_confirmed_at')->orderByDesc('id')->limit(250)->get();

        $matched=[];
        foreach ($records as $record) {
            $row=(array)$record;
            $row['case_context']=$record->case_context?json_decode($record->case_context,true):null;
            $row['applicability']=$record->applicability?json_decode($record->applicability,true):null;

            $route=$this->learningRouteRelevance($row,$key);
            if (!$route['relevant']) continue;

            if ((int)($record->lead_id ?? 0)===$lead->id) {
                $score=1.0;
                $reasons=['Same-case operator learning.'];
                $scope='same_case';
            } elseif ($record->lead_id===null) {
                $score=$route['specific']?0.95:0.85;
                $reasons=[$route['specific']?'Global guidance explicitly applies to this route.':'Global operator guidance.'];
                $scope='global';
            } else {
                $candidate=data_get($row,'case_context.similarity_signature');
                if (!is_array($candidate)) $candidate=$this->legacyLearningSignature($row['case_context'] ?? []);
                [$score,$reasons,$matchedDimensions,$comparedDimensions,$strongMatches]=$this->learningSimilarity($target,$candidate);
                $threshold=$route['specific']?0.55:0.65;
                if ($comparedDimensions<3 || $matchedDimensions<2 || $strongMatches<1 || $score<$threshold) continue;
                $scope='similar_case';
            }

            $row['match_score']=round($score,4);
            $row['match_scope']=$scope;
            $row['match_reasons']=$reasons;
            $row['route_match']=$route['specific']?'route_specific':'general';
            $row['provenance']='operator_learning';
            $matched[]=$row;
        }

        usort($matched,function($a,$b){
            $scopeOrder=['same_case'=>3,'global'=>2,'similar_case'=>1];
            $scopeCmp=($scopeOrder[$b['match_scope']]??0)<=>($scopeOrder[$a['match_scope']]??0);
            if ($scopeCmp!==0) return $scopeCmp;
            $scoreCmp=($b['match_score']??0)<=>($a['match_score']??0);
            if ($scoreCmp!==0) return $scoreCmp;
            return ($b['id']??0)<=>($a['id']??0);
        });

        return array_slice($matched,0,20);
    }

    private function learningRouteRelevance(array $row,string $key): array
    {
        $text=mb_strtolower(implode(' ',array_filter([
            $row['corrected_decision'] ?? null,
            $row['original_decision'] ?? null,
            $row['reason'] ?? null,
            is_array($row['applicability'] ?? null)?json_encode($row['applicability']):($row['applicability'] ?? null),
        ])));

        $routeAliases=[
            'zebra'=>['zebra'],
            'lawson_fox'=>['lawson fox','lawson_fox','lawson'],
            'anchorage_chambers'=>['anchorage chambers','anchorage_chambers','anchorage','ac'],
            'assure'=>['assure'],
            'tig'=>['tig'],
        ];
        $mentioned=[];
        foreach ($routeAliases as $route=>$aliases) {
            foreach ($aliases as $alias) {
                $pattern='/\\b'.preg_quote($alias,'/').'\\b/u';
                if (preg_match($pattern,$text)) {$mentioned[]=$route;break;}
            }
        }
        $mentioned=array_values(array_unique($mentioned));
        if (!$mentioned) return ['relevant'=>true,'specific'=>false];
        return ['relevant'=>in_array($key,$mentioned,true),'specific'=>in_array($key,$mentioned,true)];
    }

    private function learningSimilarity(array $target,array $candidate): array
    {
        $score=0.0;$weight=0.0;$matchedDimensions=0;$comparedDimensions=0;$strongMatches=0;$reasons=[];

        $scalars=[
            'homeowner'=>0.16,'self_employed'=>0.16,'previous_iva'=>0.10,'partner_exists'=>0.12,
            'immigration_group'=>0.10,'hmrc_majority'=>0.10,'benefits_only'=>0.07,
            'vulnerable_client'=>0.06,'has_hmrc_debt'=>0.12,'gambling_band'=>0.08,'source'=>0.04,
        ];
        foreach ($scalars as $field=>$fieldWeight) {
            if (!array_key_exists($field,$target) || !array_key_exists($field,$candidate)
                || $target[$field]===null || $candidate[$field]===null) continue;
            $weight+=$fieldWeight;$comparedDimensions++;
            if ($target[$field]===$candidate[$field]) {
                $score+=$fieldWeight;$matchedDimensions++;
                if (in_array($field,['homeowner','self_employed','partner_exists','has_hmrc_debt','previous_iva','immigration_group','gambling_band'],true)) {
                    $reasons[]=$this->learningMatchLabel($field,$target[$field]);
                }
                if (($field==='homeowner' && $target[$field]===true)
                    || ($field==='self_employed' && $target[$field]===true)
                    || ($field==='previous_iva' && $target[$field]===true)
                    || ($field==='has_hmrc_debt' && $target[$field]===true)
                    || ($field==='hmrc_majority' && $target[$field]===true)
                    || ($field==='benefits_only' && $target[$field]===true)
                    || ($field==='vulnerable_client' && $target[$field]===true)
                    || ($field==='immigration_group' && $target[$field]==='non_british')
                    || ($field==='gambling_band' && !in_array($target[$field],['none',null],true))) {
                    $strongMatches++;
                }
            }
        }

        foreach ([['known_debt_total',0.12,'Similar total debt'],['disposable_income',0.10,'Similar disposable income']] as [$field,$fieldWeight,$label]) {
            $a=$target[$field]??null;$b=$candidate[$field]??null;
            if (!is_numeric($a) || !is_numeric($b)) continue;
            $a=(float)$a;$b=(float)$b;
            if ($field==='known_debt_total' && ($a<=0 || $b<=0)) continue;
            $weight+=$fieldWeight;$comparedDimensions++;
            $scale=max(100.0,abs($a),abs($b));
            $closeness=max(0.0,1.0-(abs($a-$b)/$scale));
            $score+=$fieldWeight*$closeness;
            if ($closeness>=0.75) {$matchedDimensions++;$reasons[]=$label;}
        }

        foreach ([['voting_houses',0.25,'Overlapping voting-house exposure'],['creditors',0.20,'Overlapping creditors'],['debt_products',0.15,'Overlapping debt products']] as [$field,$fieldWeight,$label]) {
            $a=array_values(array_filter((array)($target[$field]??[])));
            $b=array_values(array_filter((array)($candidate[$field]??[])));
            if (!$a || !$b) continue;
            $weight+=$fieldWeight;$comparedDimensions++;
            $similarity=$this->jaccard($a,$b);
            $score+=$fieldWeight*$similarity;
            if ($similarity>0) {
                $matchedDimensions++;$reasons[]=$label;
                if ($field==='creditors' || $field==='debt_products') $strongMatches++;
                elseif ($field==='voting_houses') {
                    $shared=array_intersect($a,$b);
                    if (collect($shared)->contains(fn($house)=>!str_contains($house,'independent') && !str_contains($house,'ungrouped'))) $strongMatches++;
                }
            }
        }

        $final=$weight>0?$score/$weight:0.0;
        return [$final,array_values(array_unique($reasons)),$matchedDimensions,$comparedDimensions,$strongMatches];
    }

    private function legacyLearningSignature(array $context): array
    {
        $facts=collect(data_get($context,'decision_facts.case_facts',[]))->mapWithKeys(
            fn($value,$key)=>[$key=>$value['value'] ?? null]
        )->all();
        return [
            'source'=>filled($context['source']??null)?mb_strtolower(trim((string)$context['source'])):null,
            'known_debt_total'=>is_numeric($context['known_debt_total']??null)?(float)$context['known_debt_total']:null,
            'disposable_income'=>is_numeric(data_get($context,'ie_calculation.disposable_income'))?(float)data_get($context,'ie_calculation.disposable_income'):null,
            'homeowner'=>array_key_exists('property.is_homeowner',$facts)?(bool)$facts['property.is_homeowner']:null,
            'self_employed'=>array_key_exists('case.self_employed',$facts)?(bool)$facts['case.self_employed']:null,
            'previous_iva'=>array_key_exists('case.previous_iva',$facts)?(bool)$facts['case.previous_iva']:null,
            'hmrc_majority'=>array_key_exists('case.hmrc_majority',$facts)?(bool)$facts['case.hmrc_majority']:null,
            'benefits_only'=>array_key_exists('case.benefits_only',$facts)?(bool)$facts['case.benefits_only']:null,
            'vulnerable_client'=>array_key_exists('case.vulnerable_client',$facts)?(bool)$facts['case.vulnerable_client']:null,
            'voting_houses'=>collect($context['voting_house_exposure']??[])->pluck('name')->filter()->map(fn($x)=>mb_strtolower(trim((string)$x)))->unique()->values()->all(),
        ];
    }

    private function jaccard(array $a,array $b): float
    {
        $a=array_values(array_unique(array_map(fn($x)=>mb_strtolower(trim((string)$x)),$a)));
        $b=array_values(array_unique(array_map(fn($x)=>mb_strtolower(trim((string)$x)),$b)));
        $union=array_unique(array_merge($a,$b));
        if (!$union) return 0.0;
        return count(array_intersect($a,$b))/count($union);
    }

    private function learningMatchLabel(string $field,mixed $value): string
    {
        return match($field) {
            'homeowner'=>(bool)$value?'Both cases are homeowner cases.':'Both cases are non-homeowner cases.',
            'self_employed'=>(bool)$value?'Both cases are self-employed.':'Both cases are not recorded as self-employed.',
            'partner_exists'=>(bool)$value?'Both cases include a partner.':'Both cases are single-client households.',
            'has_hmrc_debt'=>(bool)$value?'Both cases include HMRC debt.':'Neither case includes an HMRC debt.',
            'previous_iva'=>(bool)$value?'Both cases have previous IVA history.':'Neither case is recorded with previous IVA history.',
            'immigration_group'=>'Same immigration-status grouping.',
            'gambling_band'=>'Same gambling band.',
            default=>'Matching '.$field.'.',
        };
    }

    public function auditDecisionKnowledge(?Lead $lead = null, ?string $destination = null): array
    {
        $key = $destination ? $this->destinationKey($destination) : null;
        return $this->voting->auditKnowledge($lead, $key);
    }

    public function recordVotingSnapshot(Lead $lead, string $destination): array
    {
        $key = $this->destinationKey($destination);
        return $this->voting->snapshot($lead, $key, null);
    }

    public function destinationKey(string $destination): string
    {
        return match (strtolower(trim($destination))) {
            'zebra' => 'zebra',
            'lawson fox', 'lawson', 'lawson_fox' => 'lawson_fox',
            'ac', 'anchorage', 'anchorage chambers', 'anchorage_chambers' => 'anchorage_chambers',
            'assure' => 'assure',
            'tig' => 'tig',
            default => throw new RuntimeException('Unsupported IVA destination. Use Zebra, Lawson Fox, AC, Assure or TIG.'),
        };
    }

    public function routeIe(Lead $lead, string $destination): array
    {
        $key=$this->destinationKey($destination);
        $profile=match($key) {
            'zebra'=>['partner'=>'Zebra','ip'=>'Zebra'],
            'lawson_fox'=>['partner'=>'Avondale','ip'=>'Lawson Fox'],
            'anchorage_chambers'=>['partner'=>'Avondale','ip'=>'Anchorage Chambers'],
            'assure'=>['partner'=>'Avondale','ip'=>'Assure'],
            'tig'=>['partner'=>'Avondale','ip'=>'TIG'],
        };
        return $this->calculator->snapshot($this->facts($lead),$profile);
    }

    public function targetDi(float $debt,float $dividend,int $months=60): array
    {if($debt<0||$dividend<=0||$dividend>100||$months<1)throw new RuntimeException('Invalid target DI inputs.');$fee=3935.0;$di=round((($debt+$fee)*($dividend/100))/$months,2);return ['debt'=>$debt,'base_fees'=>$fee,'dividend_percent'=>$dividend,'months'=>$months,'target_di'=>$di];}

    private function allowedFacts(): array {return ['calculation.target_di','household.partner_exists','household.children_count','household.children_ages','client.employment_status','case.estimated_total_debt','housing.type','housing.rent_mortgage','housing.council_tax','utilities.electricity','utilities.gas','utilities.water','income.client_salary','income.partner_salary','income.self_employed','income.universal_credit','income.child_benefit','income.maintenance_received','income.pension','income.pip_dla','income.esa','income.carers_allowance','income.student','income.foster_guardianship','income.other_income','income.uc_advance_add_back','sfs.housekeeping','sfs.comms.home_internet_tv','sfs.comms.mobile','sfs.comms.leisure','sfs.personal.clothing','sfs.personal.hairdressing','sfs.personal.toiletries','transport.client.mode','transport.client.fuel','transport.client.mot_maintenance','transport.client.road_tax','transport.client.car_finance','transport.client.car_insurance','transport.client.public_transport','transport.partner.mode','transport.partner.fuel','transport.partner.mot_maintenance','transport.partner.road_tax','transport.partner.car_finance','transport.partner.car_insurance','transport.partner.public_transport','other.childcare','other.maintenance_paid','other.dla_care','other.pip_care','other.student_offset'];}
}
