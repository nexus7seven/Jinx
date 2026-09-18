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
        $facts->setLeadFact($lead,'property.secured_loans_total',10000);
        $facts->setLeadFact($lead,'property.ownership_percent',50);

        $result=app(PropertyDecisionService::class)->evaluate($lead,'tig');

        $this->assertSame('CALCULATED',$result['status']);
        $this->assertSame(70000.0,$result['calculation']['gross_equity']);
        $this->assertSame(35000.0,$result['calculation']['client_attributable_equity']);
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

    public function test_business_route_order_is_fixed(): void
    {
        $lead=$this->lead();

        $result=app(IvaDecisionEngineService::class)->assess($lead);

        $this->assertSame(['Zebra','Lawson Fox','AC','Assure','TIG'],$result['route_order']);
        $this->assertSame(['Zebra','Lawson Fox','AC','Assure','TIG'],array_column($result['route_overview'],'destination'));
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
