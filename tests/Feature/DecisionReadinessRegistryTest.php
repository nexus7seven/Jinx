<?php

namespace Tests\Feature;

use App\Models\Creditor;
use App\Models\Debt;
use App\Models\Lead;
use App\Services\DecisionCaseFactService;
use App\Services\DecisionCaseReadinessService;
use App\Services\DecisionDynamicRuleService;
use App\Services\IvaDecisionEngineService;
use App\Services\JinxAgentIvaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionReadinessRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_homeowner_case_only_needs_material_core_facts_before_reasoning(): void
    {
        $lead = $this->lead();
        $this->debt($lead, 'Readiness Bank', 9000);
        $this->baseIe($lead);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'case.previous_iva', false);
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false);
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0);

        $result = app(DecisionCaseReadinessService::class)->check($lead->fresh());

        $this->assertSame('ready_for_reasoning', $result['state']);
        $this->assertTrue($result['ready']);
        $missingKeys = collect($result['missing'])->pluck('fact_key')->all();
        $this->assertNotContains('property.value', $missingKeys);
        $this->assertNotContains('property.mortgage_balance', $missingKeys);
        $this->assertNotContains('property.joint_ownership', $missingKeys);
        $this->assertFalse((bool) app(DecisionCaseFactService::class)->leadValues($lead)['case.self_employed']);
    }

    public function test_homeowner_readiness_uses_only_mortgage_value_and_joint_ownership(): void
    {
        $lead = $this->lead();
        $this->debt($lead, 'Homeowner Readiness Bank', 12000);
        $this->baseIe($lead);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        $facts->setLeadFact($lead, 'property.is_homeowner', true);
        $facts->setLeadFact($lead, 'case.previous_iva', false);
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false);
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0);

        $first = app(DecisionCaseReadinessService::class)->check($lead->fresh());
        $this->assertSame('property.mortgage_balance', data_get($first, 'next_question.fact_key'));

        $facts->setLeadFact($lead, 'property.mortgage_balance', 120000);
        $facts->setLeadFact($lead, 'property.value', 200000);
        $facts->setLeadFact($lead, 'property.joint_ownership', true);

        $ready = app(DecisionCaseReadinessService::class)->check($lead->fresh());
        $this->assertSame('ready_for_reasoning', $ready['state']);

        $engine = app(IvaDecisionEngineService::class)->assess($lead->fresh());
        $zebra = collect($engine['route_overview'])->firstWhere('destination', 'Zebra');
        $this->assertSame(80000.0, (float) data_get($zebra, 'property.calculation.gross_equity'));
        $this->assertSame(40000.0, (float) data_get($zebra, 'property.calculation.client_attributable_equity'));
    }

    public function test_hmrc_debt_activates_only_the_agreed_hmrc_questions(): void
    {
        $lead = $this->lead();
        $this->debt($lead, 'HMRC', 9000);
        $this->baseIe($lead);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'case.previous_iva', false);
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false);
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0);

        $result = app(DecisionCaseReadinessService::class)->check($lead->fresh());
        $missing = collect($result['missing'])->pluck('fact_key')->all();

        $this->assertContains('case.hmrc_tax_returns_outstanding', $missing);
        $this->assertContains('case.hmrc_previous_failed_iva', $missing);
        $this->assertContains('case.hmrc_prolonged_non_compliance', $missing);
        $this->assertContains('case.joint_iva', $missing);
        $this->assertNotContains('case.seiss_debt', $missing);
        $this->assertNotContains('case.vat_debt', $missing);
    }

    public function test_confirmed_new_rule_can_create_a_fact_and_make_it_part_of_readiness_and_reasoning(): void
    {
        $lead = $this->lead();
        $this->debt($lead, 'Dynamic Rule Bank', 10000);
        $this->baseIe($lead);
        $this->completeCoreFacts($lead);

        $rules = app(DecisionDynamicRuleService::class);
        $created = $rules->teachRule([
            'confirmed_by_operator'=>true,
            'destination'=>'Assure',
            'title'=>'Recent arrangement breach check',
            'fact_key'=>'case.recent_arrangement_breach',
            'fact_label'=>'Recent arrangement breach',
            'scope'=>'case',
            'data_type'=>'boolean',
            'question'=>'Has the client broken the relevant arrangement recently?',
            'requires_hmrc_debt'=>false,
            'applies_when_fact_key'=>null,
            'applies_when_operator'=>'equals',
            'applies_when_value'=>null,
            'operator'=>'equals',
            'comparison_value'=>'true',
            'result_status'=>'BLOCKED',
            'reason'=>'Operator-confirmed test rule: a recent arrangement breach blocks this route.',
            'source_detail'=>'Acceptance test operator rule',
            'question_priority'=>280,
        ]);

        $this->assertTrue($created['success']);

        $readiness = app(DecisionCaseReadinessService::class)->check($lead->fresh());
        $this->assertSame('case.recent_arrangement_breach', data_get($readiness, 'next_question.fact_key'));

        app(DecisionCaseFactService::class)->setLeadFact(
            $lead,
            'case.recent_arrangement_breach',
            true,
            'operator',
            'Acceptance test'
        );

        $ready = app(DecisionCaseReadinessService::class)->check($lead->fresh());
        $this->assertSame('ready_for_reasoning', $ready['state']);

        $assessment = app(IvaDecisionEngineService::class)->assess($lead->fresh());
        $assure = collect($assessment['route_overview'])->firstWhere('destination', 'Assure');
        $this->assertSame('BLOCKED', data_get($assure, 'dynamic_case_checks.status'));
        $this->assertSame('BLOCKED', $assure['deterministic_status']);
    }

    private function completeCoreFacts(Lead $lead): void
    {
        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'case.previous_iva', false);
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false);
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0);
        $facts->setLeadFact($lead, 'case.self_employed', false);
    }

    private function baseIe(Lead $lead): void
    {
        $iva = app(JinxAgentIvaService::class);
        $iva->updateFact($lead, 'calculation.target_di', 110);
        $iva->updateFact($lead, 'income.client_salary', 3000);
        $iva->updateFact($lead, 'household.partner_exists', false);
        $iva->updateFact($lead, 'household.children_count', 0);
        $iva->updateFact($lead, 'income.universal_credit', 0);
        $iva->updateFact($lead, 'housing.rent_mortgage', 550);
        $iva->updateFact($lead, 'housing.council_tax', 120);
        $iva->updateFact($lead, 'transport.client.mode', 'public transport');
        $iva->updateFact($lead, 'other.childcare', 0);
        $iva->updateFact($lead, 'other.maintenance_paid', 0);
        $iva->calculate($lead);
    }

    private function lead(): Lead
    {
        static $id = 993000000;

        return Lead::create([
            'vicidial_lead_id'=>++$id,
            'source'=>'Zebra',
            'employment_status'=>'Employed',
        ]);
    }

    private function debt(Lead $lead, string $name, float $balance): Debt
    {
        $creditor = Creditor::create([
            'name'=>$name,
            'voting_house'=>'Independent',
            'voting_practice1'=>'none',
            'voting_practice2'=>'none',
            'voting_practice3'=>'none',
        ]);

        return Debt::create([
            'lead_id'=>$lead->id,
            'creditor_id'=>$creditor->id,
            'balance'=>$balance,
            'source_expected'=>'other',
        ]);
    }
}
