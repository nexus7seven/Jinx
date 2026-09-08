<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Services\FinancialStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadFinancialStatementTest extends TestCase
{
    use RefreshDatabase;

    private function emptyIncomePayload(): array
    {
        $m = [];
        foreach (config('financial_statement.income') as $row) {
            $m[$row['code']] = 0;
        }

        return $m;
    }

    private function emptyExpenditurePayload(): array
    {
        $m = [];
        foreach (config('financial_statement.expenditure_sections') as $section) {
            foreach ($section['lines'] as $line) {
                $m[$line['code']] = 0;
            }
        }

        return $m;
    }

    public function test_empty_state_is_schema_version_2(): void
    {
        $state = app(FinancialStatementService::class)->emptyState();

        $this->assertSame(2, $state['schema_version']);
        $this->assertArrayHasKey('housing', $state['expenditure']);
        $this->assertSame(15.0, $state['expenditure']['housing']['tv_licence']);
        $this->assertArrayHasKey('rule_required', $state['flags']);
        $this->assertArrayHasKey('calculator_required', $state['flags']);
    }

    public function test_reading_v1_does_not_rewrite_storage(): void
    {
        $lead = Lead::create([
            'vicidial_lead_id' => 'fs-v1-read-'.uniqid(),
            'financial_statement' => [
                'schema_version' => 1,
                'household' => ['adults' => 1, 'children_under_16' => 0, 'children_16_18' => 0],
                'income' => ['salary' => 1000],
                'expenditure' => ['food' => 200, 'tv_licence' => 0],
            ],
        ]);

        $mapped = app(FinancialStatementService::class)->mergeForLead($lead);

        $this->assertSame(2, $mapped['schema_version']);
        $this->assertEquals(1000.0, $mapped['income']['client_salary']);
        $this->assertEquals(200.0, $mapped['expenditure']['sfs']['housekeeping']);
        $this->assertSame(1, $lead->fresh()->financial_statement['schema_version']);
        $this->assertSame(1000, $lead->fresh()->financial_statement['income']['salary']);
    }

    public function test_patch_financial_statement_persists_schema_v2(): void
    {
        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'fs-test-'.uniqid(),
        ]);

        $income = $this->emptyIncomePayload();
        $income['client_salary'] = 1500.55;

        $expenditure = $this->emptyExpenditurePayload();
        $expenditure['housekeeping'] = 320.25;
        $expenditure['internet_tv'] = 45;
        $expenditure['tv_licence'] = 0;

        $response = $this->actingAs($user)->patchJson("/lead/{$lead->id}/financial-statement", [
            'household' => [
                'adults' => 2,
                'children_under_16' => 1,
                'children_16_18' => 0,
            ],
            'income' => $income,
            'expenditure' => $expenditure,
            'facts' => [
                'target_di' => 80,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('financial_statement.schema_version', 2)
            ->assertJsonPath('financial_statement.household.adults', 2)
            ->assertJsonPath('financial_statement.income.client_salary', 1500.55)
            ->assertJsonPath('financial_statement.expenditure.sfs.housekeeping', 320.25)
            ->assertJsonPath('financial_statement.expenditure.housing.tv_licence', 15)
            ->assertJsonPath('financial_statement.facts.target_di', 80);

        $lead->refresh();
        $this->assertSame(2, $lead->financial_statement['schema_version']);
        $this->assertSame(2, $lead->financial_statement['household']['adults']);
        $this->assertEquals(1500.55, $lead->financial_statement['income']['client_salary']);
        $this->assertEquals(15.0, $lead->financial_statement['expenditure']['housing']['tv_licence']);
        $this->assertEquals(80.0, $lead->financial_statement['calculation']['target_di']);
        $this->assertEqualsWithDelta(
            1500.55 - (float) $lead->financial_statement['calculation']['expenditure_total'],
            (float) $lead->financial_statement['calculation']['disposable_income'],
            0.001
        );
    }

    public function test_patch_rejects_invalid_household(): void
    {
        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'fs-test-'.uniqid(),
        ]);

        $response = $this->actingAs($user)->patchJson("/lead/{$lead->id}/financial-statement", [
            'household' => [
                'adults' => 0,
                'children_under_16' => 0,
                'children_16_18' => 0,
            ],
            'income' => $this->emptyIncomePayload(),
            'expenditure' => $this->emptyExpenditurePayload(),
        ]);

        $response->assertStatus(422);
    }

    public function test_saving_v1_lead_persists_mapped_v2(): void
    {
        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'fs-v1-save-'.uniqid(),
            'financial_statement' => [
                'schema_version' => 1,
                'household' => ['adults' => 1, 'children_under_16' => 0, 'children_16_18' => 0],
                'income' => ['salary' => 1100],
                'expenditure' => ['food' => 250, 'tv_licence' => 0],
            ],
        ]);

        $this->actingAs($user)->patchJson("/lead/{$lead->id}/financial-statement", [
            'household' => [
                'adults' => 1,
                'children_under_16' => 0,
                'children_16_18' => 0,
            ],
            'income' => array_merge($this->emptyIncomePayload(), ['client_salary' => 1100]),
            'expenditure' => array_merge($this->emptyExpenditurePayload(), ['housekeeping' => 250, 'tv_licence' => 0]),
        ])->assertOk();

        $lead->refresh();
        $this->assertSame(2, $lead->financial_statement['schema_version']);
        $this->assertEquals(1100.0, $lead->financial_statement['income']['client_salary']);
        $this->assertEquals(15.0, $lead->financial_statement['expenditure']['housing']['tv_licence']);
        $this->assertArrayNotHasKey('salary', $lead->financial_statement['income']);
    }
}
