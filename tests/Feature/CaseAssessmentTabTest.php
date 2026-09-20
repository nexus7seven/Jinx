<?php

namespace Tests\Feature;

use App\Models\Creditor;
use App\Models\Debt;
use App\Models\Lead;
use App\Models\User;
use App\Services\DecisionCaseFactService;
use App\Services\JinxAgentIvaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('data-assessment-select="1"', false)
            ->assertSee('data-add-form-field', false)
            ->assertSee('case-assessment-side-nav', false)
            ->assertSee('data-assessment-nav', false)
            ->assertSee('id="caseAssistantLauncher"', false)
            ->assertSee('case-assistant-drawer', false)
            ->assertSee('id="caseAssistantClose"', false)
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

    public function test_applicable_specialist_sections_are_split_for_left_navigation(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $this->debt($lead, 'HMRC', 6000);
        $this->baseIe($lead);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        $facts->setLeadFact($lead, 'property.is_homeowner', true);
        $facts->setLeadFact($lead, 'case.previous_iva', false);
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false);
        $facts->setLeadFact($lead, 'case.self_employed', true);
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0);

        $response = $this->actingAs($user)->getJson('/lead/'.$lead->id.'/case-assessment');
        $response->assertOk();

        $sections = collect($response->json('assessment.form_sections'))->keyBy('key');

        $this->assertArrayHasKey('property', $sections->all());
        $this->assertArrayHasKey('self_employed', $sections->all());
        $this->assertArrayHasKey('hmrc', $sections->all());
        $this->assertArrayHasKey('conduct', $sections->all());
        $this->assertNotEmpty($sections['property']['fields']);
        $this->assertNotEmpty($sections['hmrc']['fields']);
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
        $this->assertArrayNotHasKey('income.universal_credit', $incomeFields->all());
        $this->assertArrayNotHasKey('case.self_employed', $incomeFields->all());
        $this->assertArrayNotHasKey('income.partner_salary', $incomeFields->all());

        $addable = collect($income['addable_fields'])->keyBy('fact_key');
        $this->assertArrayHasKey('income.universal_credit', $addable->all());
        $this->assertSame('Add income source', $income['add_control_label']);
    }

    public function test_postcode_can_populate_jurisdiction_and_manual_override_is_preserved(): void
    {
        Cache::flush();
        Http::fake([
            'api.postcodes.io/*' => Http::response([
                'status' => 200,
                'result' => ['country' => 'England'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $lead = $this->lead();
        $lead->update(['postcode' => 'LS1 1AA']);
        $this->debt($lead, 'Postcode Bank', 9000);
        $this->baseIe($lead);

        $response = $this->actingAs($user)->getJson('/lead/'.$lead->id.'/case-assessment');
        $response->assertOk();

        $stored = app(DecisionCaseFactService::class)->leadFacts($lead);
        $this->assertSame('England', $stored['case.jurisdiction']['value']);
        $this->assertSame('postcode_lookup', $stored['case.jurisdiction']['source_type']);

        $household = collect($response->json('assessment.form_sections'))->firstWhere('key', 'case_household');
        $jurisdiction = collect($household['fields'])->firstWhere('fact_key', 'case.jurisdiction');
        $this->assertSame('select', $jurisdiction['control']);
        $this->assertSame('Postcode', $jurisdiction['source']);
        $this->assertSame('England', $jurisdiction['ui_value']);

        $override = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'case',
            'fact_key' => 'case.jurisdiction',
            'value' => 'Wales',
        ]);
        $override->assertOk();

        $stored = app(DecisionCaseFactService::class)->leadFacts($lead);
        $this->assertSame('Wales', $stored['case.jurisdiction']['value']);
        $this->assertSame('operator', $stored['case.jurisdiction']['source_type']);

        $this->actingAs($user)->getJson('/lead/'.$lead->id.'/case-assessment')->assertOk();
        $stored = app(DecisionCaseFactService::class)->leadFacts($lead);
        $this->assertSame('Wales', $stored['case.jurisdiction']['value']);
    }

    public function test_case_assessment_exposes_agreed_dropdown_controls(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $debt = $this->debt($lead, 'Dropdown Bank', 9000);
        $this->baseIe($lead);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        $facts->setLeadFact($lead, 'case.previous_iva', true);
        $facts->setLeadFact($lead, 'case.previous_bankruptcy', false);
        $facts->setLeadFact($lead, 'case.gambling_monthly', 0);
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setDebtFact($debt, 'debt.product_type', 'Credit card');

        $response = $this->actingAs($user)->getJson('/lead/'.$lead->id.'/case-assessment');
        $response->assertOk();

        $sections = collect($response->json('assessment.form_sections'));
        $household = collect($sections->firstWhere('key', 'case_household')['fields'])->keyBy('fact_key');

        $this->assertSame('select', $household['case.jurisdiction']['control']);
        $this->assertSame('select_custom', $household['client.employment_status']['control']);
        $this->assertSame('select_custom_number', $household['household.children_count']['control']);
        $this->assertSame('select', $household['case.previous_iva_failed']['control']);
        $this->assertSame('Previous IVA outcome', $household['case.previous_iva_failed']['label']);
        $this->assertSame('select_custom', $household['case.immigration_status']['control']);

        $transport = collect($sections->firstWhere('key', 'transport')['fields'])->keyBy('fact_key');
        $this->assertSame('select', $transport['transport.client.mode']['control']);

        $evidence = collect($sections->firstWhere('key', 'evidence_readiness')['fields'])->keyBy('fact_key');
        $this->assertSame('select', $evidence['evidence.bank_statement_months']['control']);

        $product = collect($response->json('assessment.debts.0.facts'))->firstWhere('fact_key', 'debt.product_type');
        $this->assertSame('select_custom', $product['control']);
        $this->assertContains('PCP', collect($product['options'])->pluck('value')->all());
    }

    public function test_dropdowns_drive_self_employed_children_and_transport_applicability(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $this->debt($lead, 'Dynamic Form Bank', 9000);
        $this->baseIe($lead);

        $employment = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'ie',
            'fact_key' => 'client.employment_status',
            'value' => 'Self-employed',
        ]);
        $employment->assertOk();

        $sectionKeys = collect($employment->json('assessment.form_sections'))->pluck('key')->all();
        $this->assertContains('self_employed', $sectionKeys);
        $this->assertTrue((bool) app(DecisionCaseFactService::class)->leadValues($lead)['case.self_employed']);

        $children = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'ie',
            'fact_key' => 'household.children_count',
            'value' => 2,
        ]);
        $children->assertOk();
        $household = collect($children->json('assessment.form_sections'))->firstWhere('key', 'case_household');
        $this->assertContains('household.children_ages', collect($household['fields'])->pluck('fact_key')->all());

        $transport = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'ie',
            'fact_key' => 'transport.client.mode',
            'value' => 'both',
        ]);
        $transport->assertOk();
        $transportSection = collect($transport->json('assessment.form_sections'))->firstWhere('key', 'transport');
        $keys = collect($transportSection['fields'])->pluck('fact_key')->all();
        $this->assertContains('transport.client.public_transport', $keys);
        $this->assertContains('transport.client.fuel', $keys);
        $this->assertContains('transport.client.car_insurance', $keys);
    }

    public function test_optional_income_moves_from_add_source_list_into_live_income_form_when_entered(): void
    {
        $user = User::factory()->create();
        $lead = $this->lead();
        $this->debt($lead, 'Optional Income Bank', 9000);
        $this->baseIe($lead);

        $before = $this->actingAs($user)->getJson('/lead/'.$lead->id.'/case-assessment');
        $before->assertOk();
        $income = collect($before->json('assessment.form_sections'))->firstWhere('key', 'income');
        $this->assertContains('income.universal_credit', collect($income['addable_fields'])->pluck('fact_key')->all());

        $after = $this->actingAs($user)->patchJson('/lead/'.$lead->id.'/case-assessment/fact', [
            'scope' => 'ie',
            'fact_key' => 'income.universal_credit',
            'value' => 450,
        ]);
        $after->assertOk();

        $income = collect($after->json('assessment.form_sections'))->firstWhere('key', 'income');
        $this->assertContains('income.universal_credit', collect($income['fields'])->pluck('fact_key')->all());
        $this->assertNotContains('income.universal_credit', collect($income['addable_fields'])->pluck('fact_key')->all());
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
