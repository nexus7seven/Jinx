<?php

namespace Tests\Feature;

use App\Models\Creditor;
use App\Models\Debt;
use App\Models\Lead;
use App\Models\User;
use App\Services\DecisionCaseFactService;
use App\Services\JinxAgentIvaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseAssessmentTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_page_contains_case_assessment_tab_next_to_debts(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();

        $response = $this->actingAs($user)->get('/lead/'.$lead->id);

        $response->assertOk()
            ->assertSee('data-case-tab="debts"', false)
            ->assertSee('data-case-tab="case-assessment"', false)
            ->assertSee('data-assessment-boolean="1"', false)
            ->assertSee('data-assessment-input="1"', false)
            ->assertSee('Full Financial Statement')
            ->assertSee('>Yes</button>', false)
            ->assertSee('>No</button>', false)
            ->assertSee('Case Assessment');
    }

    public function test_case_assessment_endpoint_returns_fact_provenance_routes_and_debt_reasoning(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $debt = $this->debt($lead, 'Assessment Bank', 9000);
        $this->baseIe($lead);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England', 'operator', 'Packager confirmed');
        $facts->setLeadFact($lead, 'property.is_homeowner', false, 'operator', 'Packager confirmed');
        $facts->setLeadFact($lead, 'case.previous_iva', false, 'operator', 'Packager confirmed');
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false, 'operator', 'Packager confirmed');
        $facts->setLeadFact($lead, 'case.self_employed', false, 'derived', 'Lead employment status');
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0, 'operator', 'Packager confirmed');
        $facts->setDebtFact($debt, 'debt.product_type', 'credit card', 'operator', 'Packager confirmed');

        $response = $this->actingAs($user)->getJson('/lead/'.$lead->id.'/case-assessment');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('assessment.summary.ready', true)
            ->assertJsonPath('assessment.groups.0.label', 'Case & household')
            ->assertJsonPath('assessment.debts.0.creditor', 'Assessment Bank')
            ->assertJsonPath('assessment.debts.0.balance', 9000);

        $assessment = $response->json('assessment');
        $jurisdiction = collect($assessment['groups'])
            ->flatMap(fn($group) => $group['facts'])
            ->firstWhere('fact_key', 'case.jurisdiction');

        $this->assertSame('England', $jurisdiction['value']);
        $this->assertSame('Packager', $jurisdiction['source']);
        $this->assertSame('Packager confirmed', $jurisdiction['source_detail']);
        $this->assertNotEmpty($jurisdiction['assessment']);

        $product = collect($assessment['debts'][0]['facts'])->firstWhere('fact_key', 'debt.product_type');
        $this->assertSame('credit card', $product['value']);
        $this->assertSame('Packager', $product['source']);
        $this->assertCount(5, $assessment['routes']);
    }

    public function test_non_applicable_facts_are_hidden_and_reappear_when_their_trigger_becomes_applicable(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $this->debt($lead, 'Applicability Bank', 9000);
        $this->baseIe($lead);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'case.previous_iva', false);
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false);
        $facts->setLeadFact($lead, 'case.self_employed', false);
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0);

        $first = $this->actingAs($user)->getJson('/lead/'.$lead->id.'/case-assessment');
        $first->assertOk();

        $firstKeys = collect($first->json('assessment.groups'))
            ->flatMap(fn($group) => $group['facts'])
            ->pluck('fact_key')
            ->all();

        $this->assertNotContains('property.mortgage_balance', $firstKeys);
        $this->assertNotContains('property.value', $firstKeys);
        $this->assertNotContains('property.joint_ownership', $firstKeys);
        $this->assertGreaterThan(0, (int) $first->json('assessment.summary.not_applicable'));

        $changed = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'case',
            'fact_key' => 'property.is_homeowner',
            'value' => 'yes',
        ]);

        $changed->assertOk();

        $changedKeys = collect($changed->json('assessment.groups'))
            ->flatMap(fn($group) => $group['facts'])
            ->pluck('fact_key')
            ->all();

        $this->assertContains('property.mortgage_balance', $changedKeys);
        $this->assertContains('property.value', $changedKeys);
        $this->assertContains('property.joint_ownership', $changedKeys);
    }

    public function test_case_assessment_exposes_income_as_a_fillable_form_from_the_canonical_financial_statement(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $this->debt($lead, 'Income Form Bank', 9000);
        $this->baseIe($lead);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'case.previous_iva', false);
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false);
        $facts->setLeadFact($lead, 'case.self_employed', false);
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0);

        $response = $this->actingAs($user)->getJson('/lead/'.$lead->id.'/case-assessment');
        $response->assertOk();

        $sections = collect($response->json('assessment.form_sections'));
        $income = $sections->firstWhere('key', 'income');

        $this->assertNotNull($income);
        $incomeFields = collect($income['fields'])->keyBy('fact_key');

        $this->assertSame(3000.0, (float) $incomeFields['income.client_salary']['value']);
        $this->assertSame('ie', $incomeFields['income.client_salary']['scope']);
        $this->assertTrue((bool) $incomeFields['income.client_salary']['editable']);
        $this->assertArrayHasKey('income.universal_credit', $incomeFields->all());
        $this->assertArrayHasKey('case.self_employed', $incomeFields->all());
        $this->assertArrayNotHasKey('income.partner_salary', $incomeFields->all());
    }

    public function test_editing_income_in_case_assessment_updates_the_financial_statement_and_recalculates(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $this->debt($lead, 'Income Edit Bank', 9000);
        $this->baseIe($lead);

        $before = app(JinxAgentIvaService::class)->review($lead);
        $beforeDi = (float) data_get($before, 'ie.calculation.disposable_income');

        $response = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'ie',
            'fact_key' => 'income.client_salary',
            'value' => 3200,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $ivaFacts = app(JinxAgentIvaService::class)->facts($lead->fresh());
        $this->assertSame(3200.0, (float) $ivaFacts['income.client_salary']);

        $after = app(JinxAgentIvaService::class)->review($lead->fresh());
        $this->assertGreaterThan($beforeDi, (float) data_get($after, 'ie.calculation.disposable_income'));

        $income = collect($response->json('assessment.form_sections'))->firstWhere('key', 'income');
        $salary = collect($income['fields'])->firstWhere('fact_key', 'income.client_salary');
        $this->assertSame(3200.0, (float) $salary['value']);
    }

    public function test_partner_income_field_appears_immediately_when_partner_becomes_applicable(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $this->debt($lead, 'Partner Form Bank', 9000);
        $this->baseIe($lead);

        $changed = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'ie',
            'fact_key' => 'household.partner_exists',
            'value' => true,
        ]);

        $changed->assertOk();

        $income = collect($changed->json('assessment.form_sections'))->firstWhere('key', 'income');
        $keys = collect($income['fields'])->pluck('fact_key')->all();

        $this->assertContains('income.partner_salary', $keys);
    }

    public function test_packager_can_edit_a_case_reasoning_fact_from_assessment_tab(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $this->debt($lead, 'Editable Assessment Bank', 9000);
        $this->baseIe($lead);

        $response = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'case',
            'fact_key' => 'case.previous_iva',
            'value' => 'yes',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $stored = app(DecisionCaseFactService::class)->leadFacts($lead);
        $this->assertTrue($stored['case.previous_iva']['value']);
        $this->assertSame('operator', $stored['case.previous_iva']['source_type']);
        $this->assertSame('Edited in Case Assessment tab', $stored['case.previous_iva']['source_detail']);
    }

    public function test_packager_can_edit_a_debt_reasoning_fact_only_on_a_debt_in_the_case(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $debt = $this->debt($lead, 'Debt Fact Bank', 5000);

        $response = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'debt',
            'fact_key' => 'debt.is_joint',
            'debt_id' => $debt->id,
            'value' => 'no',
        ]);

        $response->assertOk();

        $stored = app(DecisionCaseFactService::class)->debtFacts($debt);
        $this->assertFalse($stored['debt.is_joint']['value']);

        $otherLead = $this->lead();
        $otherDebt = $this->debt($otherLead, 'Other Lead Bank', 1000);

        $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'debt',
            'fact_key' => 'debt.is_joint',
            'debt_id' => $otherDebt->id,
            'value' => 'yes',
        ])->assertStatus(422);
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
        static $id = 994000000;

        return Lead::create([
            'vicidial_lead_id' => ++$id,
            'source' => 'Zebra',
            'employment_status' => 'Employed',
        ]);
    }

    private function debt(Lead $lead, string $name, float $balance): Debt
    {
        $creditor = Creditor::create([
            'name' => $name,
            'voting_house' => 'Independent',
            'voting_practice1' => 'none',
            'voting_practice2' => 'none',
            'voting_practice3' => 'none',
        ]);

        return Debt::create([
            'lead_id' => $lead->id,
            'creditor_id' => $creditor->id,
            'balance' => $balance,
            'source_expected' => 'other',
        ]);
    }
}
