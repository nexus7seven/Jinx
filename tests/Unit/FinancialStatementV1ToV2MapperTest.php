<?php

namespace Tests\Unit;

use App\Services\FinancialStatementService;
use App\Services\FinancialStatementV1ToV2Mapper;
use Tests\TestCase;

class FinancialStatementV1ToV2MapperTest extends TestCase
{
    public function test_maps_v1_keys_into_nested_v2(): void
    {
        $service = app(FinancialStatementService::class);
        $mapper = app(FinancialStatementV1ToV2Mapper::class);

        $v2 = $mapper->map([
            'schema_version' => 1,
            'household' => [
                'adults' => 2,
                'children_under_16' => 1,
                'children_16_18' => 0,
            ],
            'income' => [
                'salary' => 1500.55,
                'pensions' => 200,
                'child_maintenance_in' => 80,
            ],
            'expenditure' => [
                'electric' => 40,
                'food' => 320.25,
                'personal' => 90,
                'tv_licence' => 0,
                'rent_mortgage' => 900,
                'internet_tv' => 44,
                'car_tax' => 20,
                'fuel' => 150,
            ],
        ], $service->emptyState());

        $this->assertSame(2, $v2['schema_version']);
        $this->assertSame(1500.55, $v2['income']['client_salary']);
        $this->assertSame(200.0, $v2['income']['pension']);
        $this->assertSame(80.0, $v2['income']['maintenance_received']);
        $this->assertSame(40.0, $v2['expenditure']['utilities']['electricity']);
        $this->assertSame(320.25, $v2['expenditure']['sfs']['housekeeping']);
        $this->assertSame(90.0, $v2['expenditure']['sfs']['personal']['total']);
        $this->assertSame(15.0, $v2['expenditure']['housing']['tv_licence']);
        $this->assertSame(900.0, $v2['expenditure']['housing']['rent_mortgage']);
        $this->assertSame(44.0, $v2['expenditure']['sfs']['comms']['home_internet_tv']);
        $this->assertSame(2, $v2['household']['adults']);
        $this->assertTrue($v2['household']['partner_exists']);
        $this->assertSame(3, $v2['household']['size']);
        $this->assertSame(20.0, $v2['expenditure']['transport']['household']['road_tax']);
        $this->assertSame(150.0, $v2['expenditure']['transport']['household']['fuel']);
        $this->assertSame(0.0, $v2['expenditure']['transport']['client']['fuel']);
        $this->assertSame(0.0, $v2['expenditure']['transport']['partner']['fuel']);
    }
}
