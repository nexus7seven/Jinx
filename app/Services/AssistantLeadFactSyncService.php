<?php

namespace App\Services;

use App\Models\Lead;

class AssistantLeadFactSyncService
{
    public function __construct(private readonly FinancialStatementService $financialStatements) {}

    public function sync(Lead $lead, array $facts): array
    {
        if ($facts === []) return [];

        $statement = $this->financialStatements->mergeForLead($lead);
        $changed = [];

        $direct = [
            'client.first_name' => 'first_name', 'client.last_name' => 'last_name',
            'client.email' => 'email', 'client.phone_number' => 'phone_number',
            'client.postcode' => 'postcode', 'client.address_line_1' => 'address_line_1',
            'client.employment_status' => 'employment_status',
            'case.estimated_total_debt' => 'estimated_total_debt',
            'housing.rent_mortgage' => 'monthly_housing_cost',
            'housing.council_tax' => 'monthly_council_tax',
        ];

        foreach ($direct as $factKey => $field) {
            if (!array_key_exists($factKey, $facts)) continue;
            $lead->{$field} = $facts[$factKey];
            $changed[] = $field;
        }

        $incomeMap = [
            'income.client_salary'=>'client_salary','income.partner_salary'=>'partner_salary',
            'income.self_employed'=>'self_employed','income.universal_credit'=>'universal_credit',
            'income.child_benefit'=>'child_benefit','income.maintenance_received'=>'maintenance_received',
            'income.pension'=>'pension','income.pip_dla'=>'pip_dla','income.esa'=>'esa',
            'income.carers_allowance'=>'carers_allowance','income.student'=>'student',
            'income.foster_guardianship'=>'foster_guardianship','income.other_income'=>'other_income',
            'income.uc_advance_add_back'=>'uc_advance_add_back',
        ];
        foreach ($incomeMap as $factKey => $code) if (array_key_exists($factKey,$facts)) $statement['income'][$code]=(float)$facts[$factKey];

        $paths = [
            'calculation.target_di'=>'facts.target_di',
            'household.partner_exists'=>'household.partner_exists',
            'housing.rent_mortgage'=>'expenditure.housing.rent_mortgage',
            'housing.council_tax'=>'expenditure.housing.council_tax',
            'utilities.electricity'=>'expenditure.utilities.electricity',
            'utilities.gas'=>'expenditure.utilities.gas', 'utilities.water'=>'expenditure.utilities.water',
            'sfs.housekeeping'=>'expenditure.sfs.housekeeping',
            'sfs.comms.home_internet_tv'=>'expenditure.sfs.comms.home_internet_tv',
            'sfs.comms.mobile'=>'expenditure.sfs.comms.mobile','sfs.comms.leisure'=>'expenditure.sfs.comms.leisure',
            'sfs.personal.clothing'=>'expenditure.sfs.personal.clothing',
            'sfs.personal.hairdressing'=>'expenditure.sfs.personal.hairdressing',
            'sfs.personal.toiletries'=>'expenditure.sfs.personal.toiletries',
            'transport.client.fuel'=>'expenditure.transport.client.fuel',
            'transport.client.mot_maintenance'=>'expenditure.transport.client.mot_maintenance',
            'transport.client.road_tax'=>'expenditure.transport.client.road_tax',
            'transport.client.car_insurance'=>'expenditure.transport.client.car_insurance',
            'transport.client.public_transport'=>'expenditure.transport.client.public_transport',
            'transport.partner.fuel'=>'expenditure.transport.partner.fuel',
            'transport.partner.mot_maintenance'=>'expenditure.transport.partner.mot_maintenance',
            'transport.partner.road_tax'=>'expenditure.transport.partner.road_tax',
            'transport.partner.car_insurance'=>'expenditure.transport.partner.car_insurance',
            'transport.partner.public_transport'=>'expenditure.transport.partner.public_transport',
            'other.childcare'=>'expenditure.other.childcare','other.maintenance_paid'=>'expenditure.other.maintenance_paid',
        ];
        foreach ($paths as $factKey=>$path) if (array_key_exists($factKey,$facts)) $this->set($statement,$path,$facts[$factKey]);

        if (isset($facts['household.children_ages']) && is_array($facts['household.children_ages'])) {
            $statement['household']['children'] = array_map(fn($age)=>['age'=>(int)$age], $facts['household.children_ages']);
        }
        if (array_key_exists('transport.client.mode',$facts)) $statement['facts']['client_transport_mode']=$facts['transport.client.mode'];
        if (array_key_exists('transport.partner.mode',$facts)) $statement['facts']['partner_transport_mode']=$facts['transport.partner.mode'];

        $this->financialStatements->persistForLead($lead, $statement);
        if (isset($statement['income']['client_salary'])) $lead->monthly_income = $statement['income']['client_salary'];
        $lead->save();

        return array_values(array_unique(array_merge($changed, ['financial_statement'])));
    }

    private function set(array &$target, string $path, mixed $value): void
    {
        $parts=explode('.',$path); $ref=&$target;
        foreach ($parts as $part) { if (!isset($ref[$part])||!is_array($ref[$part])) $ref[$part]=[]; $ref=&$ref[$part]; }
        $ref=$value;
    }
}
