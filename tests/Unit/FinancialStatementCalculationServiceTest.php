<?php

namespace Tests\Unit;

use App\Services\FinancialStatementCalculationService;
use App\Services\FinancialStatementService;
use Tests\TestCase;

class FinancialStatementCalculationServiceTest extends TestCase
{
    public function test_child_age_bands(): void
    {
        $service = app(FinancialStatementCalculationService::class);
        $bands = $service->childBandsFromAges([
            ['age' => 3],
            ['age' => 15],
            ['age' => 16],
            ['age' => 18],
            ['age' => 19],
        ]);

        $this->assertSame(2, $bands['children_under_16']);
        $this->assertSame(2, $bands['children_16_18']);
        $this->assertSame(5, $bands['counted']);
    }

    public function test_household_size_from_counts_when_no_ages(): void
    {
        $service = app(FinancialStatementCalculationService::class);
        $statement = app(FinancialStatementService::class)->emptyState();
        $statement['household']['adults'] = 1;
        $statement['household']['children_under_16'] = 2;
        $statement['household']['children_16_18'] = 1;

        $applied = $service->apply($statement);

        $this->assertSame(4, $applied['household']['size']);
        $this->assertSame(2, $applied['household']['children_under_16']);
        $this->assertSame(1, $applied['household']['children_16_18']);
    }

    public function test_partner_exists_raises_adults_to_at_least_two(): void
    {
        $service = app(FinancialStatementCalculationService::class);
        $statement = app(FinancialStatementService::class)->emptyState();
        $statement['household']['adults'] = 1;
        $statement['household']['partner_exists'] = true;
        $statement['household']['council_tax_counting_adults'] = 1;

        $applied = $service->apply($statement);

        $this->assertTrue($applied['household']['partner_exists']);
        $this->assertSame(2, $applied['household']['adults']);
        $this->assertSame(1, $applied['household']['council_tax_counting_adults']);

        $oneAdult = $service->apply(array_replace_recursive(
            app(FinancialStatementService::class)->emptyState(),
            ['household' => ['adults' => 1, 'partner_exists' => false]]
        ));
        $this->assertEqualsWithDelta(
            179.0,
            $applied['calculation']['sfs']['comms']['max'] - $oneAdult['calculation']['sfs']['comms']['max'],
            0.0001
        );
    }

    public function test_tv_licence_is_always_15(): void
    {
        $service = app(FinancialStatementCalculationService::class);
        $statement = app(FinancialStatementService::class)->emptyState();
        $statement['expenditure']['housing']['tv_licence'] = 0;

        $applied = $service->apply($statement);

        $this->assertSame(15.0, $applied['expenditure']['housing']['tv_licence']);
        $this->assertSame('calculated', $applied['line_meta']['expenditure.housing.tv_licence']['origin']);
        $this->assertFalse($applied['line_meta']['expenditure.housing.tv_licence']['flexible']);
    }

    public function test_sfs_min_max_and_headroom_for_one_adult(): void
    {
        $applied = app(FinancialStatementCalculationService::class)->apply(
            app(FinancialStatementService::class)->emptyState()
        );

        $this->assertEqualsWithDelta(317.80, $applied['calculation']['sfs']['housekeeping']['min'], 0.0001);
        $this->assertEqualsWithDelta(454.00, $applied['calculation']['sfs']['housekeeping']['max'], 0.0001);
        $this->assertEqualsWithDelta(175.00, $applied['calculation']['sfs']['comms']['min'], 0.0001);
        $this->assertEqualsWithDelta(250.00, $applied['calculation']['sfs']['comms']['max'], 0.0001);
        $this->assertEqualsWithDelta(66.50, $applied['calculation']['sfs']['personal']['min'], 0.0001);
        $this->assertEqualsWithDelta(95.00, $applied['calculation']['sfs']['personal']['max'], 0.0001);
        $this->assertEqualsWithDelta(454.00, $applied['calculation']['sfs']['housekeeping']['headroom'], 0.0001);
        $this->assertSame('calculated', $applied['line_meta']['calculation.sfs.housekeeping']['origin']);
    }

    public function test_comms_two_adults_one_teen_maximum_is_569(): void
    {
        $statement = app(FinancialStatementService::class)->emptyState();
        $statement['household']['adults'] = 2;
        $statement['household']['children_16_18'] = 1;

        $applied = app(FinancialStatementCalculationService::class)->apply($statement);

        $this->assertEqualsWithDelta(569.0, $applied['calculation']['sfs']['comms']['max'], 0.0001);
    }

    public function test_totals_disposable_income_and_target_di(): void
    {
        $statement = app(FinancialStatementService::class)->emptyState();
        $statement['income']['client_salary'] = 2000;
        $statement['facts']['target_di'] = 150;
        $statement['expenditure']['housing']['rent_mortgage'] = 800;
        $statement['expenditure']['housing']['council_tax'] = 100;
        $statement['expenditure']['sfs']['housekeeping'] = 400;

        $applied = app(FinancialStatementCalculationService::class)->apply($statement);

        $this->assertEqualsWithDelta(2000.0, $applied['calculation']['income_total'], 0.0001);
        $this->assertEqualsWithDelta(1315.0, $applied['calculation']['expenditure_total'], 0.0001);
        $this->assertEqualsWithDelta(685.0, $applied['calculation']['disposable_income'], 0.0001);
        $this->assertEqualsWithDelta(150.0, $applied['calculation']['target_di'], 0.0001);
        $this->assertEqualsWithDelta(1850.0, $applied['calculation']['required_expenditure'], 0.0001);
        $this->assertEqualsWithDelta(535.0, $applied['calculation']['variance_to_target'], 0.0001);
        $this->assertEqualsWithDelta(400.0, $applied['calculation']['sfs']['housekeeping']['actual'], 0.0001);
        $this->assertEqualsWithDelta(400 / 317.80 * 100, $applied['calculation']['sfs']['housekeeping']['pct_min'], 0.0001);
        $this->assertEqualsWithDelta(400 / 454.00 * 100, $applied['calculation']['sfs']['housekeeping']['pct_max'], 0.0001);
    }

    public function test_flags_are_preserved(): void
    {
        $statement = app(FinancialStatementService::class)->emptyState();
        $statement['flags']['rule_required'] = ['HOUSING_BENEFIT_TREATMENT'];
        $statement['flags']['calculator_required'] = ['WATCH_PARTNER_EXPENSE_ALLOWANCE'];

        $applied = app(FinancialStatementCalculationService::class)->apply($statement);

        $this->assertSame(['HOUSING_BENEFIT_TREATMENT'], $applied['flags']['rule_required']);
        $this->assertSame(['WATCH_PARTNER_EXPENSE_ALLOWANCE'], $applied['flags']['calculator_required']);
    }
}
