<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class IvaDecisionEngineService
{
    public const ROUTE_ORDER = [
        ['label'=>'Zebra','key'=>'zebra'],
        ['label'=>'Lawson Fox','key'=>'lawson_fox'],
        ['label'=>'AC','key'=>'anchorage_chambers'],
        ['label'=>'Assure','key'=>'assure'],
        ['label'=>'TIG','key'=>'tig'],
    ];

    public function __construct(
        private readonly JinxAgentIvaService $iva,
        private readonly DecisionVotingService $voting,
        private readonly DecisionCaseFactService $facts,
        private readonly PropertyDecisionService $property,
        private readonly RefreshDmpDecisionService $dmp,
    ) {}

    public function assess(Lead $lead): array
    {
        $review=$this->iva->review($lead);
        $ie=$review['ie'];
        $factContext=$this->facts->allForLead($lead);
        $adjustments=$this->facts->ieAdjustmentSummary($lead);
        $routes=[];

        foreach (self::ROUTE_ORDER as $route) {
            $key=$route['key'];
            $routeIe=$this->iva->routeIe($lead,$route['label']);
            $routeReview=$review;
            $routeReview['ie']=$routeIe;
            $voting=$this->voting->analyse($lead,$key,null);
            $basic=$this->basicRequirements($key,$routeReview);
            $evidence=$this->evidenceFeasibility($key,$routeReview,$factContext['case_facts'] ?? []);
            $property=$this->property->evaluate($lead,$key);
            $special=$this->specialCircumstances($key,$lead,$routeReview,$factContext['case_facts'] ?? []);
            $routes[]=[
                'destination'=>$route['label'],
                'destination_key'=>$key,
                'priority'=>count($routes)+1,
                'deterministic_status'=>$this->routeStatus($basic,$evidence,$property,$special,$voting),
                'basic_requirements'=>$basic,
                'evidence_feasibility'=>$evidence,
                'special_circumstances'=>$special,
                'route_ie'=>[
                    'partner'=>$routeIe['partner'] ?? null,
                    'calculation'=>$routeIe['calculation'] ?? [],
                    'sfs_analysis'=>$routeIe['sfs_analysis'] ?? [],
                    'sfs_expenditure'=>data_get($routeIe,'expenditure.sfs'),
                ],
                'voting_house_exposure'=>$voting['houses'],
                'unresolved_representative_count'=>$voting['unresolved_representative_count'],
                'unresolved_voting_percent'=>$voting['unresolved_voting_percent'],
                'property'=>$property,
                'general_rule_count'=>$this->generalRuleCount($key),
                'partner_rule_count'=>$key==='zebra'?0:$this->generalRuleCount('avondale'),
                'creditor_or_voting_rule_count'=>$this->caseRuleCount($voting),
            ];
        }

        $dmp=$this->dmp->evaluate($lead,$ie);

        return [
            'lead_id'=>$lead->id,
            'route_order'=>array_column(self::ROUTE_ORDER,'label'),
            'case'=>[
                'known_debt_total'=>$review['known_debt_total'],
                'ie'=>$ie,
                'decision_facts'=>$factContext,
                'ie_adjustments'=>$adjustments,
                'outstanding_checklist'=>$review['outstanding_checklist'],
            ],
            'route_overview'=>$routes,
            'dmp_fallback'=>$dmp,
            'instruction'=>'Assess IVA routes strictly in the configured business order: Zebra, Lawson Fox, AC (Anchorage Chambers), Assure, TIG. Use each route\'s own I&E treatment: Lawson Fox, Anchorage Chambers, Assure and TIG are Avondale routes and use the Avondale 65%-of-SFS-maximum baseline unless an IP-specific rule overrides it. BASIC_PASS only means the deterministic headline thresholds currently known are not breached; it is not final approval. For each serious route call analyse_iva_route and assess all applicable partner, IP, creditor, voting, evidence, property and learned guidance. Stop preferring a higher route only when it is BLOCKED, UNKNOWN on a material requirement that cannot currently be resolved, or a lower route has a documented operational treatment that resolves the blocker. Use Refresh DMP only as fallback where IVA is unsuitable. Do not invent missing Anchorage/general criteria.',
        ];
    }



    public function assessments(Lead $lead, int $limit = 10): array
    {
        return DB::table('lead_decision_assessments')
            ->where('lead_id',$lead->id)
            ->orderByDesc('assessed_at')->orderByDesc('id')
            ->limit(max(1,min(50,$limit)))
            ->get()
            ->map(function($r){
                $x=(array)$r;
                $x['result']=json_decode((string)$r->result_json,true);
                unset($x['result_json']);
                return $x;
            })->all();
    }

    public function recordDecision(Lead $lead, string $preferredRoute, string $status, string $rationale, ?string $actions = null): array
    {
        $allowedStatus=['CLEAR_FIT','FIT_WITH_ACTIONS','EXCEPTION_MANUAL_ESCALATION','BLOCKED','UNKNOWN','DMP_FALLBACK'];
        if (!in_array($status,$allowedStatus,true)) {
            throw new \RuntimeException('Invalid decision status.');
        }

        $result=$this->assess($lead);
        $routeKey=null;$snapshot=null;$selected=null;
        if (strtolower(trim($preferredRoute))!=='refresh dmp') {
            $routeKey=$this->iva->destinationKey($preferredRoute);
            $selected=$this->iva->analyseRoute($lead,$preferredRoute);
            $snapshot=$this->voting->snapshot($lead,$routeKey,null);
        }

        $result['final_decision']=[
            'preferred_route'=>$preferredRoute,
            'destination_key'=>$routeKey,
            'status'=>$status,
            'rationale'=>$rationale,
            'actions'=>$actions,
            'recorded_at'=>now()->toIso8601String(),
        ];
        if ($selected) $result['selected_route_analysis']=$selected;
        if ($snapshot) $result['voting_snapshot_id']=$snapshot['snapshot_id'];

        $saved=$this->persistAssessment($lead,$result,$preferredRoute,$status);
        return $saved+['voting_snapshot_id'=>$snapshot['snapshot_id']??null,'preferred_route'=>$preferredRoute,'status'=>$status];
    }

    public function persistAssessment(Lead $lead, array $result, ?string $preferredRoute = null, ?string $status = null): array
    {
        $id=DB::table('lead_decision_assessments')->insertGetId([
            'lead_id'=>$lead->id,
            'assessment_type'=>'iva_route',
            'preferred_route'=>$preferredRoute,
            'status'=>$status,
            'result_json'=>json_encode($result),
            'assessed_at'=>now(),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
        return ['success'=>true,'assessment_id'=>$id,'lead_id'=>$lead->id,'preferred_route'=>$preferredRoute,'status'=>$status];
    }

    private function basicRequirements(string $key,array $review): array
    {
        $debt=(float)$review['known_debt_total'];
        $di=data_get($review,'ie.calculation.disposable_income');
        $income=data_get($review,'ie.calculation.income_total');
        $criteria=match($key) {
            'zebra'=>['minimum_debt'=>7000.0,'minimum_di'=>110.0,'minimum_income'=>1000.0,'minimum_term_repayment'=>6600.0],
            'lawson_fox'=>['minimum_debt'=>7500.0,'minimum_di'=>110.0,'minimum_income'=>1000.0],
            'assure'=>['minimum_debt'=>7000.0,'minimum_di'=>110.0,'minimum_income'=>1000.0,'minimum_term_repayment'=>6600.0],
            'tig'=>['minimum_debt'=>6000.0,'minimum_di'=>100.0],
            'anchorage_chambers'=>[],
            default=>[],
        };

        $checks=[];$blocked=false;$unknown=false;
        if (!$criteria) {
            return [
                'status'=>'UNKNOWN',
                'checks'=>[],
                'note'=>'The supplied Anchorage Chambers (AC) workbook does not provide a complete general minimum-debt/minimum-DI eligibility set. AC remains in the requested route order, but its fit must be assessed from the loaded Anchorage creditor/representative/HMRC rules, Avondale partner baseline and any future operator knowledge.',
            ];
        }

        foreach ($criteria as $name=>$required) {
            $actual=match($name) {
                'minimum_debt'=>$debt,
                'minimum_di'=>$di,
                'minimum_income'=>$income,
                'minimum_term_repayment'=>$di===null?null:round((float)$di*60,2),
            };
            if ($actual===null) {$status='UNKNOWN';$unknown=true;}
            else {$status=(float)$actual >= $required?'SATISFIED':'BLOCKED'; if($status==='BLOCKED')$blocked=true;}
            $checks[]=['requirement'=>$name,'required'=>$required,'actual'=>$actual,'status'=>$status,'source'=>$this->requirementSource($key,$name)];
        }

        return [
            'status'=>$blocked?'BLOCKED':($unknown?'UNKNOWN':'BASIC_PASS'),
            'checks'=>$checks,
            'note'=>'Headline deterministic screening only; all sourced route/creditor/voting/property/evidence rules still apply.',
        ];
    }




    private function specialCircumstances(string $key, Lead $lead, array $review, array $caseFacts): array
    {
        $v=fn(string $k)=>$this->factValue($caseFacts,$k);
        $findings=[];
        $selfEmployed=$v('case.self_employed');
        $gambling=$v('case.gambling_monthly');
        $gamstop=$v('case.gamstop_registered');
        $hasHmrc=$lead->debts()->whereHas('creditor',fn($q)=>$q->where('name','like','%HMRC%')->orWhere('name','like','%HM Revenue%'))->exists();

        if (in_array($key,['zebra','lawson_fox','assure'],true) && $selfEmployed===true) {
            $months=$v('case.self_employed_trading_months');
            if (is_numeric($months)) $findings[]=$this->circumstanceFinding(
                'self_employed_trading_months',
                (float)$months >= 6 ? 'SATISFIED' : 'BLOCKED',
                'Client has '.(float)$months.' months trading history; supplied criteria require at least 6 months.',
                $key,'trading for at least 6 months'
            );
            else $findings[]=$this->circumstanceFinding('self_employed_trading_months','UNKNOWN','Trading duration has not been recorded.',$key,'trading for at least 6 months');

            foreach ([
                ['case.self_employed_profitable',true,'self_employed_profitability','Must be making a profit','profit'],
                ['case.self_employed_has_employees',false,'self_employed_employees','Must not have employees','employees'],
                ['case.self_employed_partnership',false,'self_employed_partnership','Must not be a partnership','partnership'],
                ['case.tax_returns_up_to_date',true,'tax_returns','Tax Returns must be up to date','Tax Returns must be up to date'],
            ] as [$fact,$wanted,$topic,$label,$needle]) {
                $actual=$v($fact);
                $status=$actual===null?'UNKNOWN':($actual===$wanted?'SATISFIED':'BLOCKED');
                $findings[]=$this->circumstanceFinding($topic,$status,$actual===null?$label.' fact has not been recorded.':$label.'.',$key,$needle);
            }
        }

        if (in_array($key,['zebra','lawson_fox','assure'],true) && is_numeric($gambling)) {
            $contribution=(float)(data_get($review,'ie.calculation.disposable_income') ?? 0);
            if ((float)$gambling > $contribution) {
                $status=$gamstop===true?'SATISFIED':($gamstop===false?'FIT_WITH_ACTIONS':'FIT_WITH_ACTIONS');
                $findings[]=$this->circumstanceFinding('gambling_gamstop',$status,
                    $gamstop===true ? 'Gambling exceeds the proposed monthly contribution and GAMSTOP is recorded.' : 'Gambling exceeds the proposed monthly contribution; GAMSTOP is required before referral.',
                    $key,'GAMSTOP required');
            }
        }

        if ($key==='tig') {
            if (is_numeric($gambling)) {
                if ((float)$gambling >= 1000) {
                    $findings[]=$this->circumstanceFinding('gambling_limit','BLOCKED','Recorded monthly gambling is £'.number_format((float)$gambling,2).'; TIG criteria say gambling must be under £1,000.',$key,'Gambling MUST BE under £1000');
                } else {
                    $findings[]=$this->circumstanceFinding('gambling_limit','SATISFIED','Recorded monthly gambling is under TIG’s £1,000 limit.',$key,'Gambling MUST BE under £1000');
                    if ((float)$gambling > 200) {
                        $findings[]=$this->circumstanceFinding('gambling_gamstop',$gamstop===true?'SATISFIED':'FIT_WITH_ACTIONS',$gamstop===true?'GAMSTOP is recorded for gambling over £200.':'TIG requires GAMSTOP where gambling is over £200.',$key,'GAMSTOP if over £200');
                    }
                }
            }
            if ($selfEmployed===true) {
                $months=$v('case.self_employed_trading_months');
                $findings[]=$this->circumstanceFinding('self_employed_trading_months',
                    $months===null?'UNKNOWN':((float)$months>=3?'SATISFIED':'BLOCKED'),
                    $months===null?'New self-employed trading duration has not been recorded.':'Recorded trading duration is '.(float)$months.' months.',
                    $key,"MINIMUM OF 3 MONTH");
            }
            if ($v('case.hmrc_majority')===true && $v('case.hmrc_deduction_from_income')===true) {
                $findings[]=$this->circumstanceFinding('hmrc_majority_deduction','BLOCKED','HMRC is recorded as majority and there is a deduction from income/benefits.',$key,'MUST NOT have a deduction');
            }
            if ($v('case.hmrc_majority')===true && ($v('case.previous_iva')===true || $v('case.previous_bankruptcy')===true)) {
                $findings[]=$this->circumstanceFinding('hmrc_previous_insolvency','BLOCKED','HMRC majority with previous IVA/bankruptcy is recorded.',$key,'WILL REJECT IF PREVIOUS IVA OR BANKRUPTCY');
            }
            if ($hasHmrc && $v('case.benefits_only')===true) {
                $findings[]=$this->circumstanceFinding('hmrc_benefits_only','BLOCKED','The case has HMRC debt and is recorded as benefits-only.',$key,'WILL REJECT IF BENEFTIS ONLY');
            }
        }

        if ($key==='anchorage_chambers' && $hasHmrc) {
            foreach ([
                ['case.tax_returns_up_to_date',false,'hmrc_tax_returns','Outstanding tax returns/self-assessments are recorded.','OUTSTANDING TAX RETURNS'],
                ['case.joint_iva',true,'hmrc_joint_iva','Joint IVA is recorded with HMRC present.','ALL JOINT IVA'],
                ['case.previous_iva_failed',true,'hmrc_failed_iva','Previous failed IVA is recorded with HMRC present.','failed previous IVA'],
                ['case.seiss_debt',true,'hmrc_seiss','SEISS-related debt is recorded.','SEISS'],
                ['case.vat_debt',true,'hmrc_vat','VAT debt is recorded.','VAT DEBT'],
            ] as [$fact,$bad,$topic,$message,$needle]) {
                $actual=$v($fact);
                if ($actual===$bad) $findings[]=$this->circumstanceFinding($topic,'BLOCKED',$message,$key,$needle);
            }
        }

        return [
            'status'=>$this->worstCircumstanceStatus($findings),
            'findings'=>$findings,
        ];
    }

    private function circumstanceFinding(string $topic,string $status,string $message,string $key,string $needle): array
    {
        $source=DB::table('decision_rules as r')->leftJoin('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('r.partner_key',$key)->where('r.is_active',true)
            ->where('r.requirement_text','like','%'.$needle.'%')
            ->select('r.id','r.requirement_text','r.severity','s.source_type','s.name as source_name','s.sheet','s.location')->first();

        return ['topic'=>$topic,'status'=>$status,'message'=>$message,'source'=>$source?(array)$source:null];
    }

    private function worstCircumstanceStatus(array $findings): string
    {
        if (!$findings) return 'NOT_APPLICABLE';
        $order=['BLOCKED'=>5,'UNKNOWN'=>4,'EXCEPTION_ESCALATION'=>3,'FIT_WITH_ACTIONS'=>2,'SATISFIED'=>1,'NOT_APPLICABLE'=>0];
        return collect($findings)->sortByDesc(fn($x)=>$order[$x['status']]??0)->first()['status'];
    }

    private function routeStatus(array $basic,array $evidence,array $property,array $special,array $voting): string
    {
        if (in_array('BLOCKED',[$basic['status']??null,$evidence['status']??null,$special['status']??null],true)) return 'BLOCKED';
        if (($voting['unresolved_representative_count']??0)>0) return 'UNKNOWN';
        if (($property['status']??null)==='UNKNOWN') return 'UNKNOWN';
        if (in_array('UNKNOWN',[$basic['status']??null,$evidence['status']??null,$special['status']??null],true)) return 'UNKNOWN';
        if (in_array($evidence['status']??null,['FIT_WITH_ACTIONS','EXCEPTION_ESCALATION'],true)
            || in_array($special['status']??null,['FIT_WITH_ACTIONS','EXCEPTION_ESCALATION'],true)) return 'FIT_WITH_ACTIONS';
        return 'BASIC_PASS';
    }

    private function requirementSource(string $key,string $requirement): ?array
    {
        $needle=match($requirement) {
            'minimum_debt'=>'Minimum Debt',
            'minimum_di'=>'Minimum DI',
            'minimum_income'=>'minimum income',
            'minimum_term_repayment'=>'re-pay over £6600',
            default=>null,
        };
        if (!$needle) return null;

        $row=DB::table('decision_rules as r')->leftJoin('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('r.partner_key',$key)->where('r.is_active',true)
            ->where('r.requirement_text','like','%'.$needle.'%')
            ->select('r.id','r.requirement_text','s.source_type','s.name as source_name','s.sheet','s.location')->first();
        return $row?(array)$row:null;
    }

    private function evidenceFeasibility(string $key,array $review,array $caseFacts): array
    {
        $items=[];
        $partnerIncome=(float)(data_get($review,'ie.income.partner_salary') ?? 0);
        $partnerEvidence=$this->factValue($caseFacts,'partner.income_evidence_available');
        $partnerDeclaration=$this->factValue($caseFacts,'partner.declaration_available');

        if ($partnerIncome > 0) {
            if ($key==='lawson_fox') {
                if ($partnerEvidence===true) $items[]=['topic'=>'partner_income','status'=>'SATISFIED','reason'=>'Partner income evidence is recorded as available.'];
                elseif ($partnerDeclaration===true) $items[]=['topic'=>'partner_income','status'=>'SATISFIED','reason'=>'Lawson Fox operator instruction allows a signed partner declaration instead of partner bank statements/wage slips.'];
                elseif ($partnerEvidence===false && $partnerDeclaration===false) $items[]=['topic'=>'partner_income','status'=>'BLOCKED','reason'=>'No conventional partner evidence and partner declaration is recorded unavailable.'];
                else $items[]=['topic'=>'partner_income','status'=>'FIT_WITH_ACTIONS','reason'=>'Lawson Fox can use a signed partner declaration; confirm it can be obtained.'];
            } elseif (in_array($key,['zebra','assure'],true)) {
                if ($partnerEvidence===true) $items[]=['topic'=>'partner_income','status'=>'SATISFIED','reason'=>'Partner income evidence is recorded as available.'];
                elseif ($partnerEvidence===false) $items[]=['topic'=>'partner_income','status'=>'BLOCKED','reason'=>'This route requires partner income proof and it is recorded unavailable.'];
                else $items[]=['topic'=>'partner_income','status'=>'UNKNOWN','reason'=>'Partner income proof availability has not been recorded.'];
            } elseif ($key==='tig') {
                if ($partnerEvidence===true) $items[]=['topic'=>'partner_income','status'=>'SATISFIED','reason'=>'Partner income evidence is recorded as available.'];
                elseif ($partnerEvidence===false) $items[]=['topic'=>'partner_income','status'=>'EXCEPTION_ESCALATION','reason'=>'TIG normally requires a recent wage slip for each wage; supplied criteria allow exceptional alternatives with manager sign-off.'];
                else $items[]=['topic'=>'partner_income','status'=>'UNKNOWN','reason'=>'Partner income evidence availability has not been recorded.'];
            } else {
                $items[]=['topic'=>'partner_income','status'=>'UNKNOWN','reason'=>'No complete Anchorage Chambers partner-income evidence rule has been supplied.'];
            }
        }

        $immigration=trim((string)($this->factValue($caseFacts,'case.immigration_status') ?? ''));
        if ($immigration !== '' && !in_array(strtolower($immigration),['uk citizen','british','british citizen'],true)) {
            if ($key==='lawson_fox') {
                $licence=$this->factValue($caseFacts,'case.uk_driving_licence');
                if ($licence===true) $items[]=['topic'=>'immigration_id','status'=>'SATISFIED','reason'=>'Lawson Fox operator instruction permits immigrant cases where the client has a UK driving licence.'];
                elseif ($licence===false) $items[]=['topic'=>'immigration_id','status'=>'UNKNOWN','reason'=>'The supplied Lawson Fox operational rule covers immigrant cases with a UK driving licence; no alternative evidence rule has been supplied for this circumstance.'];
                else $items[]=['topic'=>'immigration_id','status'=>'FIT_WITH_ACTIONS','reason'=>'Confirm whether the client has a UK driving licence for the Lawson Fox immigrant-case route.'];
            } else {
                $items[]=['topic'=>'immigration_id','status'=>'UNKNOWN','reason'=>'No route-specific immigration/ID treatment is recorded for this destination in the current structured facts.'];
            }
        }

        return [
            'status'=>$this->worstEvidenceStatus($items),
            'items'=>$items,
        ];
    }

    private function factValue(array $facts,string $key): mixed
    {
        return $facts[$key]['value'] ?? null;
    }

    private function worstEvidenceStatus(array $items): string
    {
        if (!$items) return 'NOT_APPLICABLE';
        $order=['BLOCKED'=>5,'UNKNOWN'=>4,'EXCEPTION_ESCALATION'=>3,'FIT_WITH_ACTIONS'=>2,'SATISFIED'=>1,'NOT_APPLICABLE'=>0];
        return collect($items)->sortByDesc(fn($x)=>$order[$x['status']]??0)->first()['status'];
    }

    private function generalRuleCount(string $key): int
    {
        return DB::table('decision_rules')->where('is_active',true)->where('partner_key',$key)
            ->whereNull('creditor_id')->whereNull('voting_house_id')->count();
    }

    private function caseRuleCount(array $voting): int
    {
        return collect($voting['debts'])->sum(fn($d)=>count($d['applicable_rules']??[])+count($d['supporting_company_rules']??[]));
    }
}
