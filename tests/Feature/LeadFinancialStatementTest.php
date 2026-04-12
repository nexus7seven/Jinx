<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
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

    public function test_patch_financial_statement_persists(): void
    {
        $user = User::factory()->create();
        $lead = Lead::create([
            'vicidial_lead_id' => 'fs-test-'.uniqid(),
        ]);

        $income = $this->emptyIncomePayload();
        $income['salary'] = 1500.55;

        $expenditure = $this->emptyExpenditurePayload();
        $expenditure['food'] = 320.25;
        $expenditure['internet_tv'] = 45;

        $response = $this->actingAs($user)->patchJson("/lead/{$lead->id}/financial-statement", [
            'household' => [
                'adults' => 2,
                'children_under_16' => 1,
                'children_16_18' => 0,
            ],
            'income' => $income,
            'expenditure' => $expenditure,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('financial_statement.household.adults', 2)
            ->assertJsonPath('financial_statement.income.salary', 1500.55)
            ->assertJsonPath('financial_statement.expenditure.food', 320.25);

        $lead->refresh();
        $this->assertSame(2, $lead->financial_statement['household']['adults']);
        $this->assertSame(1500.55, $lead->financial_statement['income']['salary']);
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
}
