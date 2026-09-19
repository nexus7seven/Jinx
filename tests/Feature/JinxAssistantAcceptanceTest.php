<?php

namespace Tests\Feature;

use App\Models\Creditor;
use App\Models\Debt;
use App\Models\Lead;
use App\Models\User;
use App\Services\DecisionCaseFactService;
use App\Services\IvaDecisionEngineService;
use App\Services\JinxAgentIvaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JinxAssistantAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jinx_assistant.api_key' => 'acceptance-test-key',
            'services.jinx_assistant.model' => 'acceptance-test-model',
        ]);

    }

    public function test_case_chat_runs_a_real_ie_from_natural_checkpoint_answers_and_persists_it(): void
    {
        $this->fakeAssistant([]);
        $user = User::factory()->create();
        $lead = $this->lead('Zebra');

        $messages = [
            'Start an I&E',
            '110',
            '1800',
            'no',
            '2',
            '8 and 3',
            '0',
            '0',
            '550',
            '120',
            'public transport',
            '0',
            '0',
        ];

        $last = null;
        foreach ($messages as $message) {
            $last = $this->actingAs($user)
                ->postJson("/assistant/lead/{$lead->id}/message", ['message' => $message]);
            $last->assertOk()->assertJson(['ok' => true]);
        }

        $last->assertJsonPath('financial_statement_changed', true);
        $lead->refresh();

        $statement = $lead->financial_statement;
        $this->assertSame(110.0, (float) data_get($statement, 'facts.target_di'));
        $this->assertSame(1800.0, (float) data_get($statement, 'income.client_salary'));
        $this->assertFalse((bool) data_get($statement, 'household.partner_exists'));
        $this->assertSame([8, 3], collect(data_get($statement, 'household.children', []))->pluck('age')->all());
        $this->assertSame(550.0, (float) data_get($statement, 'expenditure.housing.rent_mortgage'));
        $this->assertSame(120.0, (float) data_get($statement, 'expenditure.housing.council_tax'));
        $this->assertSame('public transport', data_get($statement, 'facts.client_transport_mode'));
        $this->assertNotNull(data_get($statement, 'calculation.disposable_income'));

        $conversation = DB::table('assistant_conversations')
            ->where('lead_id', $lead->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($conversation);

        $storedMessages = DB::table('assistant_messages')->where('conversation_id', $conversation->id)->count();
        $this->assertSame(count($messages) * 2, $storedMessages);
    }

    public function test_bulk_agent_fact_message_updates_the_case_without_the_old_is_string_failure(): void
    {
        $this->fakeAssistant([
            'calculation.target_di' => 110,
            'income.client_salary' => 1800,
            'housing.rent_mortgage' => 550,
            'housing.council_tax' => 120,
            'transport.client.mode' => 'public transport',
        ], 'I have captured those case facts. Would you like me to carry out the I&E?');

        $user = User::factory()->create();
        $lead = $this->lead('Zebra');

        $response = $this->actingAs($user)->postJson("/assistant/lead/{$lead->id}/message", [
            'message' => 'Target DI 110, salary 1800, no partner, 2 children aged 8 and 3, rent 550, council tax 120, uses public transport',
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('financial_statement_changed', true);

        $lead->refresh();
        $statement = $lead->financial_statement;

        $this->assertSame(110.0, (float) data_get($statement, 'facts.target_di'));
        $this->assertSame(1800.0, (float) data_get($statement, 'income.client_salary'));
        $this->assertFalse((bool) data_get($statement, 'household.partner_exists'));
        $this->assertSame([8, 3], collect(data_get($statement, 'household.children', []))->pluck('age')->all());
        $this->assertSame(550.0, (float) data_get($statement, 'expenditure.housing.rent_mortgage'));
        $this->assertSame(120.0, (float) data_get($statement, 'expenditure.housing.council_tax'));
        $this->assertSame('public transport', data_get($statement, 'facts.client_transport_mode'));
    }

    public function test_straight_zebra_case_flows_from_financial_facts_to_persisted_decision(): void
    {
        $lead = $this->lead('Zebra');
        $debt = $this->debt($lead, 'Acceptance Zebra Bank', 9000);

        $this->baseIe($lead, 3000, 110);
        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'evidence.bank_statement_months', 3);
        $facts->setLeadFact($lead, 'evidence.bank_statements_continuous', true);
        $facts->setLeadFact($lead, 'evidence.income_proof_available', true);
        $facts->setLeadFact($lead, 'evidence.outgoings_proof_available', true);
        $facts->setDebtFact($debt, 'debt.product_type', 'Credit Card');

        $engine = app(IvaDecisionEngineService::class);
        $result = $engine->assess($lead);
        $zebra = collect($result['route_overview'])->firstWhere('destination', 'Zebra');

        $this->assertSame('BASIC_PASS', $zebra['deterministic_status']);
        $this->assertSame(9000.0, (float) $result['case']['known_debt_total']);
        $this->assertSame(3000.0, (float) data_get($zebra, 'route_ie.calculation.income_total'));

        $saved = $engine->recordDecision(
            $lead,
            'Zebra',
            'CLEAR_FIT',
            'Acceptance harness: deterministic Zebra case is clear on the supplied facts.',
            null
        );

        $this->assertTrue($saved['success']);
        $this->assertDatabaseHas('lead_decision_assessments', [
            'id' => $saved['assessment_id'],
            'lead_id' => $lead->id,
            'preferred_route' => 'Zebra',
            'status' => 'CLEAR_FIT',
        ]);
        $this->assertNotNull($saved['voting_snapshot_id']);
    }

    public function test_zebra_partner_evidence_block_can_be_resolved_by_lawson_declaration_route(): void
    {
        $lead = $this->lead('Zebra');
        $this->debt($lead, 'Acceptance Partner Bank', 10000);

        $iva = app(JinxAgentIvaService::class);
        $this->baseIe($lead, 2800, 110);
        $iva->updateFact($lead, 'household.partner_exists', true);
        $iva->updateFact($lead, 'income.partner_salary', 500);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'evidence.bank_statement_months', 3);
        $facts->setLeadFact($lead, 'evidence.bank_statements_continuous', true);
        $facts->setLeadFact($lead, 'evidence.income_proof_available', true);
        $facts->setLeadFact($lead, 'evidence.outgoings_proof_available', true);
        $facts->setLeadFact($lead, 'partner.income_evidence_available', false);
        $facts->setLeadFact($lead, 'partner.declaration_available', true);

        $result = app(IvaDecisionEngineService::class)->assess($lead);
        $zebra = collect($result['route_overview'])->firstWhere('destination', 'Zebra');
        $lawson = collect($result['route_overview'])->firstWhere('destination', 'Lawson Fox');

        $this->assertSame('BLOCKED', $zebra['deterministic_status']);
        $this->assertNotSame('BLOCKED', $lawson['deterministic_status']);
        $partner = collect($lawson['evidence_feasibility']['items'])->firstWhere('topic', 'partner_income');
        $this->assertSame('SATISFIED', $partner['status']);
        $this->assertSame('internal_instruction', data_get($partner, 'source.source_type'));
    }

    public function test_homeowner_case_uses_client_attributable_equity_inside_whole_case_assessment(): void
    {
        $lead = $this->lead('Zebra');
        $this->debt($lead, 'Acceptance Homeowner Bank', 15000);
        $this->baseIe($lead, 3200, 110);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'property.is_homeowner', true);
        $facts->setLeadFact($lead, 'property.value', 200000);
        $facts->setLeadFact($lead, 'property.mortgage_balance', 120000);
        $facts->setLeadFact($lead, 'property.secured_loans_total', 10000);
        $facts->setLeadFact($lead, 'property.ownership_percent', 50);
        $facts->setLeadFact($lead, 'evidence.bank_statement_months', 3);
        $facts->setLeadFact($lead, 'evidence.bank_statements_continuous', true);
        $facts->setLeadFact($lead, 'evidence.income_proof_available', true);
        $facts->setLeadFact($lead, 'evidence.outgoings_proof_available', true);

        $result = app(IvaDecisionEngineService::class)->assess($lead);
        $zebra = collect($result['route_overview'])->firstWhere('destination', 'Zebra');

        $this->assertSame(70000.0, (float) data_get($zebra, 'property.calculation.gross_equity'));
        $this->assertSame(35000.0, (float) data_get($zebra, 'property.calculation.client_attributable_equity'));
        $this->assertSame(50.0, (float) data_get($zebra, 'property.calculation.ownership_percent'));
    }

    public function test_self_employed_hmrc_problem_case_is_blocked_by_the_supplied_special_rules(): void
    {
        $lead = $this->lead('Zebra');
        $this->debt($lead, 'HMRC', 9000);
        $this->baseIe($lead, 3200, 110);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'case.self_employed', true);
        $facts->setLeadFact($lead, 'case.self_employed_trading_months', 4);
        $facts->setLeadFact($lead, 'case.self_employed_profitable', true);
        $facts->setLeadFact($lead, 'case.self_employed_has_employees', false);
        $facts->setLeadFact($lead, 'case.self_employed_partnership', false);
        $facts->setLeadFact($lead, 'case.tax_returns_up_to_date', true);
        $facts->setLeadFact($lead, 'case.hmrc_majority', true);
        $facts->setLeadFact($lead, 'case.hmrc_deduction_from_income', true);
        $facts->setLeadFact($lead, 'evidence.self_employed_docs_complete', true);
        $facts->setLeadFact($lead, 'evidence.bank_statement_months', 3);
        $facts->setLeadFact($lead, 'evidence.bank_statements_all_accounts', true);
        $facts->setLeadFact($lead, 'evidence.bank_statements_dated_last_3_months', true);
        $facts->setLeadFact($lead, 'evidence.income_proof_available', true);
        $facts->setLeadFact($lead, 'evidence.debt_proof_complete', true);

        $result = app(IvaDecisionEngineService::class)->assess($lead);
        $zebra = collect($result['route_overview'])->firstWhere('destination', 'Zebra');
        $tig = collect($result['route_overview'])->firstWhere('destination', 'TIG');

        $this->assertSame('BLOCKED', $zebra['deterministic_status']);
        $this->assertSame('BLOCKED', $tig['deterministic_status']);
        $this->assertNotNull(collect($tig['special_circumstances']['findings'])->firstWhere('topic', 'hmrc_majority_deduction'));
    }

    public function test_tig_can_remain_viable_when_higher_priority_routes_fail_their_minimum_debt(): void
    {
        $lead = $this->lead('Zebra');
        $debt = $this->debt($lead, 'Acceptance TIG Bank', 6500);
        $this->baseIe($lead, 2800, 100);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'evidence.bank_statement_months', 1);
        $facts->setLeadFact($lead, 'evidence.bank_statements_all_accounts', true);
        $facts->setLeadFact($lead, 'evidence.bank_statements_dated_last_3_months', true);
        $facts->setLeadFact($lead, 'evidence.income_proof_available', true);
        $facts->setLeadFact($lead, 'evidence.debt_proof_complete', true);
        $facts->setDebtFact($debt, 'debt.product_type', 'Personal Loan');

        $result = app(IvaDecisionEngineService::class)->assess($lead);
        $zebra = collect($result['route_overview'])->firstWhere('destination', 'Zebra');
        $lawson = collect($result['route_overview'])->firstWhere('destination', 'Lawson Fox');
        $tig = collect($result['route_overview'])->firstWhere('destination', 'TIG');

        $this->assertSame('BLOCKED', $zebra['basic_requirements']['status']);
        $this->assertSame('BLOCKED', $lawson['basic_requirements']['status']);
        $this->assertSame('BASIC_PASS', $tig['deterministic_status']);
        $this->assertSame('SATISFIED', collect($tig['evidence_feasibility']['items'])->firstWhere('topic', 'debt_proof')['status']);
    }

    public function test_refresh_dmp_is_a_real_fallback_when_iva_minimum_debt_routes_are_unsuitable(): void
    {
        $lead = $this->lead('Zebra');
        $first = $this->debt($lead, 'Acceptance DMP Bank A', 2500);
        $second = $this->debt($lead, 'Acceptance DMP Bank B', 2500);
        $this->baseIe($lead, 2600, 110);

        $facts = app(DecisionCaseFactService::class);
        $facts->setLeadFact($lead, 'property.is_homeowner', false);
        $facts->setLeadFact($lead, 'case.jurisdiction', 'England');
        foreach ([$first, $second] as $debt) {
            $facts->setDebtFact($debt, 'debt.product_type', 'Credit Card');
            $facts->setDebtFact($debt, 'debt.contractual_payment', 100);
        }

        $result = app(IvaDecisionEngineService::class)->assess($lead);

        $this->assertSame('BLOCKED', collect($result['route_overview'])->firstWhere('destination', 'Zebra')['basic_requirements']['status']);
        $this->assertSame('BLOCKED', collect($result['route_overview'])->firstWhere('destination', 'TIG')['basic_requirements']['status']);
        $this->assertSame('CLEAR_FIT', $result['dmp_fallback']['status']);
        $this->assertSame(50.0, (float) $result['dmp_fallback']['minimum_dmp_payment_25_percent']);
    }

    private function fakeAssistant(array $factUpdates, string $reply = 'Continue.'): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'reply' => $reply,
                    'fact_updates' => $factUpdates,
                    'suitability_assessment' => null,
                    'proposed_knowledge' => null,
                    'confirm_pending_knowledge' => false,
                    'requested_action' => null,
                    'case_summary' => 'Acceptance test case.',
                ]),
            ], 200),
        ]);
    }

    private function baseIe(Lead $lead, float $salary, float $target): void
    {
        $iva = app(JinxAgentIvaService::class);
        $iva->updateFact($lead, 'calculation.target_di', $target);
        $iva->updateFact($lead, 'income.client_salary', $salary);
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

    private function lead(string $source): Lead
    {
        static $id = 992000000;

        return Lead::create([
            'vicidial_lead_id' => ++$id,
            'source' => $source,
        ]);
    }

    private function debt(Lead $lead, string $creditorName, float $balance): Debt
    {
        $creditor = Creditor::create([
            'name' => $creditorName,
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
