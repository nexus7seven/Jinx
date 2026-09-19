<?php

namespace Tests\Feature;

use App\Models\Creditor;
use App\Models\Debt;
use App\Models\Lead;
use App\Services\DecisionCaseFactService;
use App\Services\DecisionVotingService;
use App\Services\IvaDecisionEngineService;
use App\Services\PropertyDecisionService;
use App\Services\RefreshDmpDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_equity_uses_only_clients_ownership_share(): void
    {
        $lead=$this->lead();
        $facts=app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead,'property.is_homeowner',true);
        $facts->setLeadFact($lead,'property.value',200000);
        $facts->setLeadFact($lead,'property.mortgage_balance',120000);
        $facts->setLeadFact($lead,'property.joint_ownership',true);

        $result=app(PropertyDecisionService::class)->evaluate($lead,'tig');

        $this->assertSame('CALCULATED',$result['status']);
        $this->assertSame(80000.0,$result['calculation']['gross_equity']);
        $this->assertSame(50.0,$result['calculation']['ownership_percent_used']);
        $this->assertSame(40000.0,$result['calculation']['client_attributable_equity']);
    }

    public function test_refresh_dmp_clear_fit_uses_25_percent_contractual_payment_test(): void
    {
        $lead=$this->lead();
        $a=$this->creditor('Decision Test A');
        $b=$this->creditor('Decision Test B');
        $d1=Debt::create(['lead_id'=>$lead->id,'creditor_id'=>$a->id,'balance'=>2500,'source_expected'=>'other']);
        $d2=Debt::create(['lead_id'=>$lead->id,'creditor_id'=>$b->id,'balance'=>2500,'source_expected'=>'other']);

        $facts=app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead,'case.jurisdiction','England');
        foreach([$d1,$d2] as $debt) {
            $facts->setDebtFact($debt,'debt.product_type','credit card');
            $facts->setDebtFact($debt,'debt.contractual_payment',100);
        }

        $result=app(RefreshDmpDecisionService::class)->evaluate($lead,['calculation'=>['disposable_income'=>160]]);

        $this->assertSame('CLEAR_FIT',$result['status']);
        $this->assertSame(50.0,$result['minimum_dmp_payment_25_percent']);
        $this->assertSame([],$result['blockers']);
        $this->assertSame([],$result['unknowns']);
    }

    public function test_debt_level_voting_override_resolves_source_conflict_explicitly(): void
    {
        $lead=$this->lead();
        $creditor=$this->creditor('Decision Test Override','TIX');
        $debt=Debt::create(['lead_id'=>$lead->id,'creditor_id'=>$creditor->id,'balance'=>1000,'source_expected'=>'other']);
        app(DecisionCaseFactService::class)->setDebtFact($debt,'voting.representative_override','WATCH');

        $result=app(DecisionVotingService::class)->analyse($lead,'tig',null);
        $row=collect($result['debts'])->firstWhere('debt_id',$debt->id);

        $this->assertSame('WATCH',$row['voting_house']);
        $this->assertSame('operator_override',$row['route_source']);
        $this->assertFalse($row['route_conflict']);
    }

    public function test_product_specific_route_resolves_a_voting_conflict(): void
    {
        $lead=$this->lead();
        $creditor=$this->creditor('Decision Product Conflict');
        $debt=Debt::create(['lead_id'=>$lead->id,'creditor_id'=>$creditor->id,'balance'=>2000,'source_expected'=>'other','reference'=>'1234567890123456']);

        $watchId=$this->houseId('WATCH');
        $tixId=$this->houseId('TIX');
        $sourceId=\DB::table('decision_rule_sources')->insertGetId([
            'source_type'=>'test','name'=>'decision-test','created_at'=>now(),'updated_at'=>now(),
        ]);
        \DB::table('creditor_voting_routes')->insert([
            [
                'creditor_id'=>$creditor->id,'voting_house_id'=>$watchId,'partner_key'=>'tig',
                'condition_text'=>'Decision Product Conflict - IVA | account_type: Personal Loan',
                'priority'=>15,'is_default'=>false,'is_active'=>true,'source_id'=>$sourceId,'created_at'=>now(),'updated_at'=>now(),
            ],
            [
                'creditor_id'=>$creditor->id,'voting_house_id'=>$tixId,'partner_key'=>'tig',
                'condition_text'=>'Decision Product Conflict | product_type: Credit Card | digits: 16',
                'priority'=>15,'is_default'=>false,'is_active'=>true,'source_id'=>$sourceId,'created_at'=>now(),'updated_at'=>now(),
            ],
        ]);

        app(DecisionCaseFactService::class)->setDebtFact($debt,'debt.product_type','Credit Card');
        $result=app(DecisionVotingService::class)->analyse($lead,'tig',null);
        $row=collect($result['debts'])->firstWhere('debt_id',$debt->id);

        $this->assertSame('TIX',$row['voting_house']);
        $this->assertSame('sourced_route_resolved_by_debt_fact',$row['route_source']);
        $this->assertSame(0,$result['unresolved_representative_count']);
        $this->assertStringContainsString('product/account',$row['route_resolution']);
    }

    public function test_product_specific_mapping_can_override_a_generic_higher_precedence_mapping(): void
    {
        $lead=$this->lead();
        $creditor=$this->creditor('Decision Product Specific');
        $debt=Debt::create(['lead_id'=>$lead->id,'creditor_id'=>$creditor->id,'balance'=>2000,'source_expected'=>'other','reference'=>'12345678901234']);

        $watchId=$this->houseId('WATCH');
        $tixId=$this->houseId('TIX');
        $sourceId=\DB::table('decision_rule_sources')->insertGetId([
            'source_type'=>'test','name'=>'decision-test','created_at'=>now(),'updated_at'=>now(),
        ]);
        \DB::table('creditor_voting_routes')->insert([
            [
                'creditor_id'=>$creditor->id,'voting_house_id'=>$watchId,'partner_key'=>'tig',
                'condition_text'=>'Decision Product Specific is listed under WATCH',
                'priority'=>10,'is_default'=>false,'is_active'=>true,'source_id'=>$sourceId,'created_at'=>now(),'updated_at'=>now(),
            ],
            [
                'creditor_id'=>$creditor->id,'voting_house_id'=>$tixId,'partner_key'=>'tig',
                'condition_text'=>'Decision Product Specific | product_type: Loan | digits: 14',
                'priority'=>15,'is_default'=>false,'is_active'=>true,'source_id'=>$sourceId,'created_at'=>now(),'updated_at'=>now(),
            ],
        ]);

        app(DecisionCaseFactService::class)->setDebtFact($debt,'debt.product_type','Personal Loan');
        $result=app(DecisionVotingService::class)->analyse($lead,'tig',null);
        $row=collect($result['debts'])->firstWhere('debt_id',$debt->id);

        $this->assertSame('TIX',$row['voting_house']);
        $this->assertSame('sourced_route_resolved_by_debt_fact',$row['route_source']);
    }

    public function test_ac_means_anchorage_chambers_and_not_avondale_support_scope(): void
    {
        $lead=$this->lead();
        $iva=app(\App\Services\JinxAgentIvaService::class);

        $this->assertSame('anchorage_chambers',$iva->destinationKey('AC'));
        $this->assertSame('anchorage_chambers',$iva->destinationKey('Anchorage Chambers'));

        $ac=$iva->analyseRoute($lead,'AC');
        $lawson=$iva->analyseRoute($lead,'Lawson Fox');

        $this->assertSame('anchorage_chambers',$ac['destination_key']);
        $this->assertSame([], $lawson['company_supporting_rules']);
        $this->assertFalse(collect($lawson['general_route_rules'])->contains(
            fn($rule)=>($rule['source_name']??null)==='Avondale AC Criteria(1).xlsx'
        ));
    }

    public function test_avondale_routes_use_65_percent_sfs_route_specific_ie(): void
    {
        $lead=$this->lead();
        $iva=app(\App\Services\JinxAgentIvaService::class);

        $zebra=$iva->routeIe($lead,'Zebra');
        $lawson=$iva->routeIe($lead,'Lawson Fox');
        $ac=$iva->routeIe($lead,'AC');

        $this->assertSame('Zebra',$zebra['partner']);
        $this->assertSame('Avondale',$lawson['partner']);
        $this->assertSame('Avondale',$ac['partner']);

        $max=(float)$lawson['ranges']['sfs']['housekeeping']['max'];
        $this->assertSame((float)ceil($max*0.65),(float)$lawson['expenditure']['sfs']['housekeeping']);
        $this->assertSame((float)ceil($max*0.65),(float)$ac['expenditure']['sfs']['housekeeping']);
    }

    public function test_lawson_partner_declaration_can_resolve_partner_income_when_zebra_cannot(): void
    {
        $lead=$this->lead();
        $iva=app(\App\Services\JinxAgentIvaService::class);
        $iva->updateFact($lead,'household.partner_exists',true);
        $iva->updateFact($lead,'income.partner_salary',500);

        $facts=app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead,'property.is_homeowner',false);
        $facts->setLeadFact($lead,'partner.income_evidence_available',false);
        $facts->setLeadFact($lead,'partner.declaration_available',true);

        $result=app(IvaDecisionEngineService::class)->assess($lead);
        $zebra=collect($result['route_overview'])->firstWhere('destination','Zebra');
        $lawson=collect($result['route_overview'])->firstWhere('destination','Lawson Fox');
        $lawsonPartner=collect($lawson['evidence_feasibility']['items'])->firstWhere('topic','partner_income');

        $this->assertSame('BLOCKED',$zebra['evidence_feasibility']['status']);
        $this->assertSame('FIT_WITH_ACTIONS',$lawson['evidence_feasibility']['status']);
        $this->assertSame('SATISFIED',$lawsonPartner['status']);
        $this->assertSame('internal_instruction',$lawsonPartner['source']['source_type']);
    }

    public function test_route_evidence_actions_are_sourced_and_not_silent_unknowns(): void
    {
        $lead=$this->lead();
        app(\App\Services\JinxAgentIvaService::class)->updateFact($lead,'income.client_salary',1800);
        app(DecisionCaseFactService::class)->setLeadFact($lead,'property.is_homeowner',false);

        $result=app(IvaDecisionEngineService::class)->assess($lead);
        $zebra=collect($result['route_overview'])->firstWhere('destination','Zebra');
        $bank=collect($zebra['evidence_feasibility']['items'])->firstWhere('topic','bank_statements');
        $income=collect($zebra['evidence_feasibility']['items'])->firstWhere('topic','client_income_proof');

        $this->assertSame('FIT_WITH_ACTIONS',$bank['status']);
        $this->assertNotNull($bank['source']);
        $this->assertSame('FIT_WITH_ACTIONS',$income['status']);
        $this->assertNotNull($income['source']);
    }

    public function test_zebra_income_evidence_applies_to_benefit_income_without_salary(): void
    {
        $lead=$this->lead();
        $iva=app(\App\Services\JinxAgentIvaService::class);
        $iva->updateFact($lead,'income.pip_dla',420);

        $facts=app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead,'property.is_homeowner',false);

        $result=app(IvaDecisionEngineService::class)->assess($lead);
        $zebra=collect($result['route_overview'])->firstWhere('destination','Zebra');
        $proof=collect($zebra['evidence_feasibility']['items'])->firstWhere('topic','client_income_proof');

        $this->assertSame('FIT_WITH_ACTIONS',$proof['status']);
        $this->assertNotNull($proof['source']);
        $this->assertStringContainsString('Proof of all income',$proof['source']['requirement_text']);
    }

    public function test_tig_requires_complete_debt_proof_even_when_credit_report_exists(): void
    {
        $lead=$this->lead();
        $creditor=$this->creditor('TIG Evidence Bank');
        Debt::create(['lead_id'=>$lead->id,'creditor_id'=>$creditor->id,'balance'=>8000,'source_expected'=>'other']);

        $facts=app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead,'property.is_homeowner',false);
        $facts->setLeadFact($lead,'evidence.credit_report_available',true);
        $facts->setLeadFact($lead,'evidence.debt_proof_complete',false);

        $result=app(IvaDecisionEngineService::class)->assess($lead);
        $tig=collect($result['route_overview'])->firstWhere('destination','TIG');
        $proof=collect($tig['evidence_feasibility']['items'])->firstWhere('topic','debt_proof');

        $this->assertSame('FIT_WITH_ACTIONS',$proof['status']);
        $this->assertStringContainsString('confirm that proof is complete for every debt',$proof['reason']);
        $this->assertNotNull($proof['source']);
        $this->assertStringContainsString('PROOF OF DEBTS',$proof['source']['requirement_text']);
    }

    public function test_avondale_style_routes_require_sourced_outgoings_evidence_when_relevant(): void
    {
        $lead=$this->lead();
        $iva=app(\App\Services\JinxAgentIvaService::class);
        $iva->updateFact($lead,'housing.rent_mortgage',650);

        $facts=app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead,'property.is_homeowner',false);

        $result=app(IvaDecisionEngineService::class)->assess($lead);
        $zebra=collect($result['route_overview'])->firstWhere('destination','Zebra');
        $proof=collect($zebra['evidence_feasibility']['items'])->firstWhere('topic','outgoings_proof');

        $this->assertSame('FIT_WITH_ACTIONS',$proof['status']);
        $this->assertNotNull($proof['source']);
        $this->assertStringContainsString('Evidence of outgoings',$proof['source']['requirement_text']);

        $facts->setLeadFact($lead,'evidence.outgoings_proof_available',true);
        $result=app(IvaDecisionEngineService::class)->assess($lead);
        $zebra=collect($result['route_overview'])->firstWhere('destination','Zebra');
        $proof=collect($zebra['evidence_feasibility']['items'])->firstWhere('topic','outgoings_proof');
        $this->assertSame('SATISFIED',$proof['status']);
    }

    public function test_self_employed_evidence_uses_the_correct_trading_stage_rule(): void
    {
        $lead=$this->lead();
        $facts=app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead,'property.is_homeowner',false);
        $facts->setLeadFact($lead,'case.self_employed',true);
        $facts->setLeadFact($lead,'case.self_employed_trading_months',8);
        $facts->setLeadFact($lead,'evidence.self_employed_docs_complete',false);

        $result=app(IvaDecisionEngineService::class)->assess($lead);
        $zebra=collect($result['route_overview'])->firstWhere('destination','Zebra');
        $evidence=collect($zebra['evidence_feasibility']['items'])->firstWhere('topic','self_employed_evidence');

        $this->assertSame('FIT_WITH_ACTIONS',$evidence['status']);
        $this->assertStringContainsString('under-one-year',$evidence['reason']);
        $this->assertStringContainsString('Self-employed less than 1 year',$evidence['source']['requirement_text']);

        $facts->setLeadFact($lead,'case.self_employed_trading_months',18);
        $result=app(IvaDecisionEngineService::class)->assess($lead);
        $zebra=collect($result['route_overview'])->firstWhere('destination','Zebra');
        $evidence=collect($zebra['evidence_feasibility']['items'])->firstWhere('topic','self_employed_evidence');
        $this->assertStringContainsString('Self employed over 1 year',$evidence['source']['requirement_text']);
    }

    public function test_cross_case_learning_surfaces_only_for_similar_and_route_relevant_cases(): void
    {
        $creditor=$this->creditor('Learning Bank');

        $source=$this->lead();
        $sourceDebt=Debt::create(['lead_id'=>$source->id,'creditor_id'=>$creditor->id,'balance'=>8000,'source_expected'=>'other']);
        $sourceFacts=app(DecisionCaseFactService::class);
        $sourceFacts->setDebtFact($sourceDebt,'debt.product_type','Personal Loan');
        $sourceFacts->setLeadFact($source,'property.is_homeowner',false);
        $sourceFacts->setLeadFact($source,'case.self_employed',false);
        $sourceFacts->setLeadFact($source,'case.previous_iva',false);

        $stored=app(\App\Services\JinxAgentToolService::class)->execute('teach_decision_engine',[
            'lead_id'=>$source->id,
            'knowledge_type'=>'precedent',
            'original_decision'=>'Lawson Fox blocked',
            'corrected_decision'=>'Lawson Fox fit with actions',
            'reason'=>'Lawson Fox accepted this structure after the evidence issue was resolved.',
            'applicability'=>'Lawson Fox cases with a similar creditor/product and non-homeowner profile.',
            'outcome'=>'accepted',
        ]);
        $learningId=$stored['learning_record_id'];

        $similar=$this->lead();
        $similarDebt=Debt::create(['lead_id'=>$similar->id,'creditor_id'=>$creditor->id,'balance'=>8500,'source_expected'=>'other']);
        $similarFacts=app(DecisionCaseFactService::class);
        $similarFacts->setDebtFact($similarDebt,'debt.product_type','Personal Loan');
        $similarFacts->setLeadFact($similar,'property.is_homeowner',false);
        $similarFacts->setLeadFact($similar,'case.self_employed',false);
        $similarFacts->setLeadFact($similar,'case.previous_iva',false);

        $lawson=app(\App\Services\JinxAgentIvaService::class)->analyseRoute($similar,'Lawson Fox');
        $match=collect($lawson['learned_guidance'])->firstWhere('id',$learningId);
        $this->assertNotNull($match);
        $this->assertSame('similar_case',$match['match_scope']);
        $this->assertGreaterThanOrEqual(0.55,$match['match_score']);
        $this->assertNotEmpty($match['match_reasons']);
        $this->assertSame('operator_learning',$match['provenance']);

        $zebra=app(\App\Services\JinxAgentIvaService::class)->analyseRoute($similar,'Zebra');
        $this->assertFalse(collect($zebra['learned_guidance'])->contains('id',$learningId));

        $otherCreditor=$this->creditor('Unrelated Learning Creditor');
        $unrelated=$this->lead();
        $unrelatedDebt=Debt::create(['lead_id'=>$unrelated->id,'creditor_id'=>$otherCreditor->id,'balance'=>22000,'source_expected'=>'other']);
        $unrelatedFacts=app(DecisionCaseFactService::class);
        $unrelatedFacts->setDebtFact($unrelatedDebt,'debt.product_type','Credit Card');
        $unrelatedFacts->setLeadFact($unrelated,'property.is_homeowner',true);
        $unrelatedFacts->setLeadFact($unrelated,'case.self_employed',true);
        $unrelatedFacts->setLeadFact($unrelated,'case.previous_iva',true);

        $unrelatedLawson=app(\App\Services\JinxAgentIvaService::class)->analyseRoute($unrelated,'Lawson Fox');
        $this->assertFalse(collect($unrelatedLawson['learned_guidance'])->contains('id',$learningId));
    }

    public function test_avondale_partner_baseline_is_a_separate_decision_rule(): void
    {
        $rule=\DB::table('decision_rules as r')
            ->join('decision_rule_sources as s','s.id','=','r.source_id')
            ->where('r.partner_key','avondale')
            ->where('r.rule_key','avondale_sfs_65_percent')
            ->first(['r.requirement_text','s.name as source_name']);

        $this->assertNotNull($rule);
        $this->assertStringContainsString('65%', $rule->requirement_text);
        $this->assertSame('resources/assistant/knowledge/avondale/00_partner_baseline.md',$rule->source_name);
    }

    public function test_packaging_planner_asks_for_debt_product_to_resolve_voting_conflict(): void
    {
        $lead=$this->lead();
        $creditor=$this->creditor('Planner Voting Conflict');
        $debt=Debt::create([
            'lead_id'=>$lead->id,
            'creditor_id'=>$creditor->id,
            'balance'=>8000,
            'source_expected'=>'other',
            'reference'=>null,
        ]);

        $watchId=$this->houseId('WATCH');
        $tixId=$this->houseId('TIX');
        $sourceId=\DB::table('decision_rule_sources')->insertGetId([
            'source_type'=>'test','name'=>'decision-test','created_at'=>now(),'updated_at'=>now(),
        ]);
        \DB::table('creditor_voting_routes')->insert([
            [
                'creditor_id'=>$creditor->id,'voting_house_id'=>$watchId,'partner_key'=>'tig',
                'condition_text'=>'Planner Voting Conflict - IVA | account_type: Personal Loan',
                'priority'=>15,'is_default'=>false,'is_active'=>true,'source_id'=>$sourceId,'created_at'=>now(),'updated_at'=>now(),
            ],
            [
                'creditor_id'=>$creditor->id,'voting_house_id'=>$tixId,'partner_key'=>'tig',
                'condition_text'=>'Planner Voting Conflict | product_type: Credit Card | digits: 16',
                'priority'=>15,'is_default'=>false,'is_active'=>true,'source_id'=>$sourceId,'created_at'=>now(),'updated_at'=>now(),
            ],
        ]);

        $iva=app(\App\Services\JinxAgentIvaService::class);
        $iva->updateFact($lead,'income.client_salary',1800);
        $facts=app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead,'case.jurisdiction','England');
        $facts->setLeadFact($lead,'property.is_homeowner',false);
        $facts->setLeadFact($lead,'case.previous_iva',false);
        $facts->setLeadFact($lead,'case.previous_bankruptcy',false);
        $facts->setLeadFact($lead,'case.self_employed',false);
        $facts->setLeadFact($lead,'case.gambling_monthly',0);

        $planner=app(\App\Services\IvaCasePackagingPlannerService::class);
        $plan=$planner->plan($lead);

        $this->assertSame('needs_fact',$plan['state']);
        $this->assertSame('debt.product_type',$plan['question']['fact_key']);
        $this->assertSame('debt',$plan['question']['scope']);
        $this->assertSame($debt->id,$plan['question']['debt_id']);
        $this->assertSame('TIG',$plan['question']['route']);

        $parsed=$planner->parseAnswer($plan['question'],'credit card');
        $this->assertTrue($parsed['valid']);
        $facts->setDebtFact($debt,'debt.product_type',$parsed['value']);

        $needsReference=$planner->plan($lead);
        $this->assertSame('needs_fact',$needsReference['state']);
        $this->assertSame('debt.account_reference',$needsReference['question']['fact_key']);
        $this->assertSame('debt',$needsReference['question']['scope']);
        $this->assertSame($debt->id,$needsReference['question']['debt_id']);
        $tigBeforeReference=collect($needsReference['assessment']['route_overview'])->firstWhere('destination','TIG');
        $this->assertSame(1,$tigBeforeReference['unresolved_representative_count']);

        $parsedReference=$planner->parseAnswer($needsReference['question'],'1234567890123456');
        $this->assertTrue($parsedReference['valid']);
        $facts->setDebtFact($debt,'debt.account_reference',$parsedReference['value']);

        $after=$planner->plan($lead);
        $this->assertSame('ready_for_agent',$after['state']);
        $tig=collect($after['assessment']['route_overview'])->firstWhere('destination','TIG');
        $this->assertSame(0,$tig['unresolved_representative_count']);
    }

    public function test_business_route_order_is_fixed(): void
    {
        $lead=$this->lead();

        $result=app(IvaDecisionEngineService::class)->assess($lead);

        $this->assertSame(['Zebra','Lawson Fox','AC','Assure','TIG'],$result['route_order']);
        $this->assertSame(['Zebra','Lawson Fox','AC','Assure','TIG'],array_column($result['route_overview'],'destination'));
        $this->assertSame('anchorage_chambers',collect($result['route_overview'])->firstWhere('destination','AC')['destination_key']);
    }

    private function houseId(string $key): int
    {
        $id=\DB::table('voting_houses')->where('key',$key)->value('id');
        if ($id) return (int)$id;
        return (int)\DB::table('voting_houses')->insertGetId([
            'key'=>$key,'rules_text'=>null,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function lead(): Lead
    {
        static $id=991000000;
        return Lead::create(['vicidial_lead_id'=>++$id]);
    }

    private function creditor(string $name,string $house='Independent'): Creditor
    {
        return Creditor::create([
            'name'=>$name,
            'voting_house'=>$house,
            'voting_practice1'=>'none',
            'voting_practice2'=>'none',
            'voting_practice3'=>'none',
        ]);
    }
}
