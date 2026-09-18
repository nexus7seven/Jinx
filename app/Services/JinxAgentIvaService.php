<?php
namespace App\Services;

use App\Models\Lead;
use RuntimeException;

class JinxAgentIvaService
{
    public function __construct(private readonly FinancialStatementService $statements, private readonly AssistantIeCalculationService $calculator, private readonly AssistantLeadFactSyncService $sync, private readonly PartnerKnowledgeService $knowledge, private readonly DecisionVotingService $voting) {}

    public function facts(Lead $lead): array
    {
        $s=$this->statements->mergeForLead($lead); $f=[];
        $put=function(string $k,mixed $v)use(&$f){if($v!==null)$f[$k]=$v;};
        $put('client.employment_status',$lead->employment_status);$put('case.estimated_total_debt',$lead->estimated_total_debt);
        $put('calculation.target_di',data_get($s,'facts.target_di'));$put('household.partner_exists',data_get($s,'household.partner_exists',false));
        $children=collect(data_get($s,'household.children',[]))->pluck('age')->filter(fn($x)=>$x!==null)->values()->all();$put('household.children_count',count(data_get($s,'household.children',[])));if($children)$put('household.children_ages',$children);
        foreach(['client_salary','partner_salary','self_employed','universal_credit','child_benefit','maintenance_received','pension','pip_dla','esa','carers_allowance','student','foster_guardianship','other_income','uc_advance_add_back'] as $k)$put('income.'.$k,data_get($s,'income.'.$k));
        foreach(['rent_mortgage','council_tax'] as $k)$put('housing.'.$k,data_get($s,'expenditure.housing.'.$k));
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

        $aliases = match($key) {
            'zebra' => ['zebra'],
            'lawson_fox' => ['lawson_fox','lawson fox','lawson'],
            'anchorage_chambers' => ['anchorage_chambers','anchorage chambers','anchorage','ac'],
            'assure' => ['assure'],
            'tig' => ['tig'],
            default => [$key],
        };
        $learning = \Illuminate\Support\Facades\DB::table('decision_learning_records')
            ->where('status','active')
            ->where(function($q) use ($lead) {
                $q->where('lead_id',$lead->id)->orWhereNull('lead_id');
            })
            ->where(function($q) use ($aliases) {
                foreach ($aliases as $i=>$alias) {
                    $method=$i===0?'where':'orWhere';
                    $q->{$method}(function($x) use ($alias) {
                        $x->where('corrected_decision','like','%'.$alias.'%')
                          ->orWhere('original_decision','like','%'.$alias.'%')
                          ->orWhere('reason','like','%'.$alias.'%')
                          ->orWhere('applicability','like','%'.$alias.'%');
                    });
                }
                $q->orWhereNull('corrected_decision');
            })
            ->orderByRaw('CASE WHEN lead_id = ? THEN 0 ELSE 1 END',[$lead->id])
            ->orderByDesc('last_confirmed_at')->limit(20)->get()
            ->map(function($r){$x=(array)$r;$x['case_context']=$r->case_context?json_decode($r->case_context,true):null;$x['applicability']=$r->applicability?json_decode($r->applicability,true):null;return $x;})->all();

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
