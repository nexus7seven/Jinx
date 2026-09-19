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
        private readonly DecisionDynamicRuleService $dynamicRules,
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
            $dynamic=$this->dynamicRules->evaluate($lead,$key);
            $routes[]=[
                'destination'=>$route['label'],
                'destination_key'=>$key,
                'priority'=>count($routes)+1,
                'deterministic_status'=>$this->routeStatus($basic,$evidence,$property,$special,$dynamic,$voting),
                'basic_requirements'=>$basic,
                'evidence_feasibility'=>$evidence,
                'special_circumstances'=>$special,
                'dynamic_case_checks'=>$dynamic,
                'route_ie'=>[
                    'partner'=>$routeIe['partner'] ?? null,
                    'calculation'=>$routeIe['calculation'] ?? [],
                    'sfs_analysis'=>$routeIe['sfs_analysis'] ?? [],
                    'sfs_expenditure'=>data_get($routeIe,'expenditure.sfs'),
                ],
                'voting_house_exposure'=>$voting['houses'],
                'unresolved_representative_count'=>$voting['unresolved_representative_count'],
                'unresolved_voting_percent'=>$voting['unresolved_voting_percent'],
                'unresolved_voting_debts'=>collect($voting['debts'] ?? [])
                    ->filter(fn($debt)=>($debt['route_source'] ?? null)==='unresolved_conflict')
                    ->map(fn($debt)=>[
                        'debt_id'=>$debt['debt_id'],
                        'creditor'=>$debt['creditor'],
                        'balance'=>$debt['balance'],
                        'conflict_reason'=>$debt['route_conflict_reason'] ?? null,
                        'route_candidates'=>$debt['route_candidates'] ?? [],
                    ])->values()->all(),
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
            $returnsDue=$v('case.self_employed_returns_due');
            $returnsUpToDate=$v('case.tax_returns_up_to_date');

            if ($returnsDue===null) {
                $findings[]=$this->circumstanceFinding(
                    'self_employed_returns_due',
                    'UNKNOWN',
                    'It has not been established whether the client has been trading long enough for tax returns to be due.',
                    $key,
                    'Tax Returns'
                );
            } elseif ($returnsDue===false) {
                $findings[]=$this->circumstanceFinding(
                    'self_employed_returns_due',
                    'FIT_WITH_ACTIONS',
                    'No tax return is recorded as due yet. Apply the route’s sourced new/self-employed trading criteria during final route analysis.',
                    $key,
                    'SELF EMPLOYED'
                );
            } else {
                $status=$returnsUpToDate===null?'UNKNOWN':($returnsUpToDate===true?'SATISFIED':'BLOCKED');
                $findings[]=$this->circumstanceFinding(
                    'tax_returns',
                    $status,
                    $returnsUpToDate===true
                        ? 'All tax returns currently due are recorded as up to date.'
                        : ($returnsUpToDate===false
                            ? 'Tax returns that are due are recorded as outstanding.'
                            : 'Tax-return status has not been recorded.'),
                    $key,
                    'Tax Returns must be up to date'
                );
            }
        }

        if (in_array($key,['zebra','lawson_fox','assure'],true) && is_numeric($gambling)) {
            $contribution=(float)(data_get($review,'ie.calculation.disposable_income') ?? 0);
            if ((float)$gambling > $contribution) {
                $status=$gamstop===true?'SATISFIED':'FIT_WITH_ACTIONS';
                $findings[]=$this->circumstanceFinding(
                    'gambling_gamstop',
                    $status,
                    $gamstop===true
                        ? 'Gambling exceeds the proposed monthly contribution and GAMSTOP is recorded.'
                        : 'Gambling exceeds the proposed monthly contribution; GAMSTOP is required before referral.',
                    $key,
                    'GAMSTOP required'
                );
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
                $returnsDue=$v('case.self_employed_returns_due');
                $returnsUpToDate=$v('case.tax_returns_up_to_date');
                if ($returnsDue===true && $returnsUpToDate===false) {
                    $findings[]=$this->circumstanceFinding(
                        'tax_returns',
                        'BLOCKED',
                        'Tax returns that are due are recorded as outstanding.',
                        $key,
                        'TAX RETURN'
                    );
                } elseif ($returnsDue===false) {
                    $findings[]=$this->circumstanceFinding(
                        'self_employed_new_trader',
                        'FIT_WITH_ACTIONS',
                        'No tax return is due yet; apply TIG’s sourced new-trader requirements during route analysis.',
                        $key,
                        'MINIMUM OF 3 MONTH'
                    );
                }
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

        if ($hasHmrc) {
            $outstanding=$v('case.hmrc_tax_returns_outstanding');
            $failedHmrcIva=$v('case.hmrc_previous_failed_iva');
            $nonCompliance=$v('case.hmrc_prolonged_non_compliance');

            if ($key==='anchorage_chambers') {
                if ($outstanding===true) {
                    $findings[]=$this->circumstanceFinding('hmrc_tax_returns','BLOCKED','Outstanding tax returns/self-assessments are recorded.',$key,'OUTSTANDING TAX RETURNS');
                }
                if ($v('case.joint_iva')===true) {
                    $findings[]=$this->circumstanceFinding('hmrc_joint_iva','BLOCKED','Joint IVA is recorded with HMRC present.',$key,'ALL JOINT IVA');
                }
                if ($failedHmrcIva===true) {
                    $findings[]=$this->circumstanceFinding('hmrc_failed_iva','BLOCKED','HMRC was included in a previous IVA that failed.',$key,'failed previous IVA');
                }
            }

            if ($nonCompliance===true) {
                $findings[]=[
                    'topic'=>'hmrc_compliance_history',
                    'status'=>'FIT_WITH_ACTIONS',
                    'message'=>'Prolonged HMRC non-compliance is recorded. Treat this as a material conduct fact and assess it against the sourced route/HMRC criteria rather than inventing an automatic outcome.',
                    'source'=>null,
                ];
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

    private function routeStatus(array $basic,array $evidence,array $property,array $special,array $dynamic,array $voting): string
    {
        if (in_array('BLOCKED',[$basic['status']??null,$evidence['status']??null,$special['status']??null,$dynamic['status']??null],true)) return 'BLOCKED';
        if (($voting['unresolved_representative_count']??0)>0) return 'UNKNOWN';
        if (($property['status']??null)==='UNKNOWN') return 'UNKNOWN';
        if (in_array('UNKNOWN',[$basic['status']??null,$evidence['status']??null,$special['status']??null,$dynamic['status']??null],true)) return 'UNKNOWN';
        if (in_array($evidence['status']??null,['FIT_WITH_ACTIONS','EXCEPTION_ESCALATION'],true)
            || in_array($special['status']??null,['FIT_WITH_ACTIONS','EXCEPTION_ESCALATION'],true)
            || in_array($dynamic['status']??null,['FIT_WITH_ACTIONS','EXCEPTION_ESCALATION'],true)) return 'FIT_WITH_ACTIONS';
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
        $income=(array)data_get($review,'ie.income',[]);
        $salary=(float)($income['client_salary']??0);
        $selfEmployedIncome=(float)($income['self_employed']??0);
        $uc=(float)($income['universal_credit']??0);
        $partnerIncome=(float)($income['partner_salary']??0);
        $benefitTotal=collect(['child_benefit','pip_dla','esa','carers_allowance','foster_guardianship'])
            ->sum(fn($k)=>(float)($income[$k]??0));
        $otherProofIncome=collect(['child_benefit','maintenance_received','pension','pip_dla','esa','carers_allowance','student','foster_guardianship','other_income'])
            ->sum(fn($k)=>(float)($income[$k]??0));

        $months=$this->factValue($caseFacts,'evidence.bank_statement_months');
        $continuous=$this->factValue($caseFacts,'evidence.bank_statements_continuous');
        $allAccounts=$this->factValue($caseFacts,'evidence.bank_statements_all_accounts');
        $recentStatements=$this->factValue($caseFacts,'evidence.bank_statements_dated_last_3_months');

        if (in_array($key,['zebra','lawson_fox','assure'],true)) {
            $ok=is_numeric($months) && (float)$months>=3 && $continuous===true;
            $items[]=$this->evidenceItem(
                'bank_statements',
                $ok?'SATISFIED':'FIT_WITH_ACTIONS',
                $ok
                    ? 'At least 3 continuous full months of bank statements are recorded.'
                    : 'Obtain/confirm at least 3 continuous full months of bank statements.',
                $key,
                '3 continuous full month'
            );
        } elseif ($key==='tig') {
            $statementsOk=is_numeric($months) && (float)$months>=1 && $allAccounts===true && $recentStatements===true;
            $items[]=$this->evidenceItem(
                'bank_statements',
                $statementsOk?'SATISFIED':'FIT_WITH_ACTIONS',
                $statementsOk
                    ? 'TIG bank-statement requirements are recorded as satisfied.'
                    : 'TIG requires a full month for every account, dated within the last 3 months.',
                $key,
                '1 full month for every account'
            );
        }

        if ($key==='tig' && $salary>0) {
            $proof=$this->factValue($caseFacts,'evidence.income_proof_available');
            $items[]=$this->evidenceItem(
                'client_income_proof',
                $proof===true?'SATISFIED':($proof===false?'EXCEPTION_ESCALATION':'FIT_WITH_ACTIONS'),
                $proof===true
                    ? 'Client wage evidence is recorded as available.'
                    : ($proof===false
                        ? 'TIG wage evidence is recorded unavailable; supplied criteria allow an exceptional alternative with manager sign-off.'
                        : 'Confirm a recent full-month wage slip or the TIG exceptional evidence route.'),
                $key,
                'full month wage slip'
            );
        } elseif (in_array($key,['zebra','lawson_fox','assure'],true) && ($salary+$otherProofIncome)>0) {
            $proof=$this->factValue($caseFacts,'evidence.income_proof_available');
            $items[]=$this->evidenceItem(
                'client_income_proof',
                $proof===true?'SATISFIED':'FIT_WITH_ACTIONS',
                $proof===true?'Current proof for the recorded client income is available.':'Obtain current proof for the recorded client income (for example the applicable wage slip, benefit letter or bank evidence).',
                $key,
                'Proof of all income'
            );
        }

        if ($uc>0) {
            if ($key==='tig') {
                $journal=$this->factValue($caseFacts,'evidence.uc_journal_available');
                $items[]=$this->evidenceItem(
                    'universal_credit_evidence',
                    $journal===true?'SATISFIED':'FIT_WITH_ACTIONS',
                    $journal===true?'Recent Universal Credit journal is recorded as available.':'Obtain the TIG-required full Universal Credit journal dated within the last 3 months.',
                    $key,
                    'Universal Credit need full journal'
                );
            } elseif (in_array($key,['zebra','lawson_fox','assure'],true)) {
                $breakdown=$this->factValue($caseFacts,'evidence.uc_breakdown_available');
                $items[]=$this->evidenceItem(
                    'universal_credit_evidence',
                    $breakdown===true?'SATISFIED':'FIT_WITH_ACTIONS',
                    $breakdown===true?'Universal Credit breakdown is recorded as available.':'Obtain the Universal Credit breakdown.',
                    $key,
                    'Universal Credit'
                );
            }
        }

        if ($key==='tig' && $benefitTotal>0) {
            $proof=$this->factValue($caseFacts,'evidence.benefit_proof_available');
            $items[]=$this->evidenceItem(
                'benefit_evidence',
                $proof===true?'SATISFIED':'FIT_WITH_ACTIONS',
                $proof===true?'Current benefit evidence is recorded as available.':'Obtain current-financial-year benefit evidence or qualifying recent bank-statement evidence.',
                $key,
                'benefit letters'
            );
        }

        if ($key==='tig' && (float)($review['known_debt_total'] ?? 0)>0) {
            $debtProof=$this->factValue($caseFacts,'evidence.debt_proof_complete');
            $creditReport=$this->factValue($caseFacts,'evidence.credit_report_available');
            $items[]=$this->evidenceItem(
                'debt_proof',
                $debtProof===true?'SATISFIED':'FIT_WITH_ACTIONS',
                $debtProof===true
                    ? 'Proof of all debts is recorded as complete.'
                    : ($creditReport===true
                        ? 'A credit report is recorded, but confirm that proof is complete for every debt before referral.'
                        : 'Obtain and verify proof of every debt before referral.'),
                $key,
                'PROOF OF DEBTS'
            );
        }

        $previousIva=$this->factValue($caseFacts,'case.previous_iva');
        $previousIvaFailed=$this->factValue($caseFacts,'case.previous_iva_failed');
        $needsPreviousIvaDocs=$previousIva===true
            && ($key==='tig' || (in_array($key,['zebra','lawson_fox','assure'],true) && $previousIvaFailed===true));
        if ($needsPreviousIvaDocs) {
            $docs=$this->factValue($caseFacts,'evidence.previous_iva_termination_docs');
            $needle=$key==='tig'?'TERMINATION REPORT':'termination docs';
            $items[]=$this->evidenceItem(
                'previous_iva_documents',
                $docs===true?'SATISFIED':'FIT_WITH_ACTIONS',
                $docs===true?'Previous IVA termination evidence is recorded as available.':'Obtain the previous IVA termination documentation before referral.',
                $key,
                $needle
            );
        } elseif ($previousIva===true && in_array($key,['zebra','lawson_fox','assure'],true) && $previousIvaFailed===null) {
            $items[]=$this->evidenceItem(
                'previous_iva_status',
                'FIT_WITH_ACTIONS',
                'Confirm whether the previous IVA terminated/failed; termination documents are required if it did.',
                $key,
                'Previous Debt Solutions'
            );
        }

        if (($this->factValue($caseFacts,'case.self_employed')===true || $selfEmployedIncome>0)
            && in_array($key,['zebra','lawson_fox','assure','tig'],true)) {
            $docs=$this->factValue($caseFacts,'evidence.self_employed_docs_complete');
            $tradingMonths=$this->factValue($caseFacts,'case.self_employed_trading_months');
            if ($key==='tig') {
                $needle='SELF EMPLOYED = TAX RETURN';
                $required='Complete TIG self-employed evidence: tax return and 3 months banking; newly self-employed cases also require the supplied minimum trading history and income confirmation.';
            } elseif (!is_numeric($tradingMonths)) {
                $needle='SELF EMPLOYED CASES - CRITERIA AND REQUIREMENTS';
                $required="Confirm the client's trading duration so the correct self-employed evidence pack can be applied, then complete that pack.";
            } elseif ((float)$tradingMonths<12) {
                $needle='Self-employed less than 1 year';
                $required='Complete the under-one-year self-employed pack: required bank statements, invoices and income breakdown, with tax-return evidence where applicable.';
            } else {
                $needle='Self employed over 1 year';
                $required='Complete the established self-employed pack: latest tax return, required bank statements and income breakdown.';
            }
            $items[]=$this->evidenceItem(
                'self_employed_evidence',
                $docs===true?'SATISFIED':'FIT_WITH_ACTIONS',
                $docs===true?'The route-specific self-employed evidence pack is recorded as complete.':$required,
                $key,
                $needle
            );
        }

        if (in_array($key,['zebra','lawson_fox','assure'],true)) {
            $rent=(float)(data_get($review,'ie.expenditure.housing.rent_mortgage') ?? 0);
            $childcare=(float)(data_get($review,'ie.expenditure.other.childcare') ?? 0);
            $maintenance=(float)(data_get($review,'ie.expenditure.other.maintenance_paid') ?? 0);
            if ($rent>0 || $childcare>0 || $maintenance>0) {
                $outgoingsProof=$this->factValue($caseFacts,'evidence.outgoings_proof_available');
                $items[]=$this->evidenceItem(
                    'outgoings_proof',
                    $outgoingsProof===true?'SATISFIED':'FIT_WITH_ACTIONS',
                    $outgoingsProof===true
                        ? 'Evidence supporting the relevant recorded outgoings is available.'
                        : 'Obtain evidence supporting the recorded rent/mortgage, childcare or maintenance expenditure as applicable, or explain any difference in case notes.',
                    $key,
                    'Evidence of outgoings'
                );
            }
        }

        $partnerEvidence=$this->factValue($caseFacts,'partner.income_evidence_available');
        $partnerDeclaration=$this->factValue($caseFacts,'partner.declaration_available');

        if ($partnerIncome > 0) {
            if ($key==='lawson_fox') {
                if ($partnerEvidence===true) $items[]=$this->evidenceItem('partner_income','SATISFIED','Partner income evidence is recorded as available.',$key,"Partner's income");
                elseif ($partnerDeclaration===true) $items[]=$this->evidenceItem('partner_income','SATISFIED','Lawson Fox operator instruction allows a signed partner declaration instead of partner bank statements/wage slips.',$key,'Partner income can be evidenced');
                elseif ($partnerEvidence===false && $partnerDeclaration===false) $items[]=$this->evidenceItem('partner_income','BLOCKED','No conventional partner evidence and partner declaration is recorded unavailable.',$key,'Partner income can be evidenced');
                else $items[]=$this->evidenceItem('partner_income','FIT_WITH_ACTIONS','Lawson Fox can use a signed partner declaration; confirm it can be obtained.',$key,'Partner income can be evidenced');
            } elseif (in_array($key,['zebra','assure'],true)) {
                if ($partnerEvidence===true) $items[]=$this->evidenceItem('partner_income','SATISFIED','Partner income evidence is recorded as available.',$key,"Partner's income");
                elseif ($partnerEvidence===false) $items[]=$this->evidenceItem('partner_income','BLOCKED','This route requires partner income proof and it is recorded unavailable.',$key,"Partner's income");
                else $items[]=$this->evidenceItem('partner_income','UNKNOWN','Partner income proof availability has not been recorded.',$key,"Partner's income");
            } elseif ($key==='tig') {
                if ($partnerEvidence===true) $items[]=$this->evidenceItem('partner_income','SATISFIED','Partner income evidence is recorded as available.',$key,'Including partners where applicable');
                elseif ($partnerEvidence===false) $items[]=$this->evidenceItem('partner_income','EXCEPTION_ESCALATION','TIG normally requires a recent wage slip for each wage; supplied criteria allow exceptional alternatives with manager sign-off.',$key,'Including partners where applicable');
                else $items[]=$this->evidenceItem('partner_income','UNKNOWN','Partner income evidence availability has not been recorded.',$key,'Including partners where applicable');
            } else {
                $items[]=$this->evidenceItem('partner_income','UNKNOWN','No complete Anchorage Chambers partner-income evidence rule has been supplied.',$key,'partner');
            }
        }

        $immigration=trim((string)($this->factValue($caseFacts,'case.immigration_status') ?? ''));
        if ($immigration !== '' && !in_array(strtolower($immigration),['uk citizen','british','british citizen'],true)) {
            if ($key==='lawson_fox') {
                $licence=$this->factValue($caseFacts,'case.uk_driving_licence');
                if ($licence===true) $items[]=$this->evidenceItem('immigration_id','SATISFIED','Lawson Fox operator instruction permits immigrant cases where the client has a UK driving licence.',$key,'UK driving licence');
                elseif ($licence===false) $items[]=$this->evidenceItem('immigration_id','UNKNOWN','The supplied Lawson Fox operational rule covers immigrant cases with a UK driving licence; no alternative evidence rule has been supplied for this circumstance.',$key,'UK driving licence');
                else $items[]=$this->evidenceItem('immigration_id','FIT_WITH_ACTIONS','Confirm whether the client has a UK driving licence for the Lawson Fox immigrant-case route.',$key,'UK driving licence');
            } else {
                $items[]=$this->evidenceItem('immigration_id','UNKNOWN','No route-specific immigration/ID treatment is recorded for this destination in the current structured facts.',$key,'immigration');
            }
        }

        return [
            'status'=>$this->worstEvidenceStatus($items),
            'items'=>$items,
        ];
    }

    private function evidenceItem(string $topic,string $status,string $reason,string $key,string $needle): array
    {
        $source=DB::table('decision_rules as r')
            ->leftJoin('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('r.partner_key',$key)
            ->where('r.is_active',true)
            ->where('r.requirement_text','like','%'.$needle.'%')
            ->select('r.id','r.requirement_text','r.severity','s.source_type','s.name as source_name','s.sheet','s.location')
            ->first();

        return [
            'topic'=>$topic,
            'status'=>$status,
            'reason'=>$reason,
            'source'=>$source?(array)$source:null,
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
