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
            'client.title' => 'title', 'client.first_name' => 'first_name', 'client.middle_name' => 'middle_name', 'client.last_name' => 'last_name',
            'client.dob' => 'dob', 'client.email' => 'email', 'client.phone_number' => 'phone_number',
            'client.house_number' => 'house_number', 'client.house_name' => 'house_name', 'client.building_number' => 'building_number',
            'client.postcode' => 'postcode', 'client.address_line_1' => 'address_line_1', 'client.employment_status' => 'employment_status',
            'case.estimated_total_debt' => 'estimated_total_debt', 'housing.rent_mortgage' => 'monthly_housing_cost',
            'housing.council_tax' => 'monthly_council_tax',
        ];
        foreach ($direct as $factKey => $field) {
            if (!array_key_exists($factKey, $facts)) continue;
            $lead->{$field} = $facts[$factKey];
            $changed[] = $field;
        }

        $incomeMap = [
            'income.client_salary'=>'client_salary','income.partner_salary'=>'partner_salary','income.self_employed'=>'self_employed',
            'income.universal_credit'=>'universal_credit','income.child_benefit'=>'child_benefit','income.maintenance_received'=>'maintenance_received',
            'income.pension'=>'pension','income.pip_dla'=>'pip_dla','income.esa'=>'esa','income.carers_allowance'=>'carers_allowance',
            'income.student'=>'student','income.foster_guardianship'=>'foster_guardianship','income.other_income'=>'other_income','income.uc_advance_add_back'=>'uc_advance_add_back',
        ];
        foreach ($incomeMap as $factKey => $code) if (array_key_exists($factKey,$facts)) $statement['income'][$code]=(float)$facts[$factKey];

        $paths = [
            'calculation.target_di'=>'facts.target_di','household.partner_exists'=>'household.partner_exists',
            'housing.rent_mortgage'=>'expenditure.housing.rent_mortgage','housing.council_tax'=>'expenditure.housing.council_tax',
            'utilities.electricity'=>'expenditure.utilities.electricity','utilities.gas'=>'expenditure.utilities.gas','utilities.water'=>'expenditure.utilities.water',
            'sfs.housekeeping'=>'expenditure.sfs.housekeeping','sfs.comms.home_internet_tv'=>'expenditure.sfs.comms.home_internet_tv',
            'sfs.comms.mobile'=>'expenditure.sfs.comms.mobile','sfs.comms.leisure'=>'expenditure.sfs.comms.leisure',
            'sfs.personal.clothing'=>'expenditure.sfs.personal.clothing','sfs.personal.hairdressing'=>'expenditure.sfs.personal.hairdressing',
            'sfs.personal.toiletries'=>'expenditure.sfs.personal.toiletries','transport.client.fuel'=>'expenditure.transport.client.fuel',
            'transport.client.mot_maintenance'=>'expenditure.transport.client.mot_maintenance','transport.client.road_tax'=>'expenditure.transport.client.road_tax',
            'transport.client.car_insurance'=>'expenditure.transport.client.car_insurance','transport.client.public_transport'=>'expenditure.transport.client.public_transport',
            'transport.partner.fuel'=>'expenditure.transport.partner.fuel','transport.partner.mot_maintenance'=>'expenditure.transport.partner.mot_maintenance',
            'transport.partner.road_tax'=>'expenditure.transport.partner.road_tax','transport.partner.car_insurance'=>'expenditure.transport.partner.car_insurance',
            'transport.partner.public_transport'=>'expenditure.transport.partner.public_transport','other.childcare'=>'expenditure.other.childcare',
            'other.maintenance_paid'=>'expenditure.other.maintenance_paid','other.dla_care'=>'expenditure.other.dla_care',
            'other.pip_care'=>'expenditure.other.pip_care','other.student_offset'=>'expenditure.other.student_offset',
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

    public function syncDeterministicIe(Lead $lead, array $snapshot): array
    {
        if (!isset($snapshot['expenditure'], $snapshot['income'])) return [];
        $statement = $this->financialStatements->mergeForLead($lead);
        foreach ($snapshot['income'] as $code=>$value) if (array_key_exists($code,$statement['income']) && is_numeric($value)) $statement['income'][$code]=(float)$value;
        $statement['expenditure'] = array_replace_recursive($statement['expenditure'], $snapshot['expenditure']);
        $household=$snapshot['household']??[];
        foreach (['adults','children_under_16','children_16_18','size'] as $key) if (($household[$key]??null)!==null) $statement['household'][$key]=$household[$key];
        if (($snapshot['calculation']['target_di']??null)!==null) $statement['facts']['target_di']=$snapshot['calculation']['target_di'];
        $persisted=$this->financialStatements->persistForLead($lead,$statement);
        $lead->monthly_utilities_cost = round(array_sum($persisted['expenditure']['utilities'] ?? []),2);
        $lead->save();
        return ['financial_statement','monthly_utilities_cost'];
    }

    private function set(array &$target, string $path, mixed $value): void
    {
        $parts=explode('.',$path); $ref=&$target;
        foreach ($parts as $part) { if (!isset($ref[$part])||!is_array($ref[$part])) $ref[$part]=[]; $ref=&$ref[$part]; }
        $ref=$value;
    }
}
