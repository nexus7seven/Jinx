<?php

namespace App\Services;

class FinancialStatementV1ToV2Mapper
{
    public function isV2(array $stored): bool
    {
        return (int) ($stored['schema_version'] ?? 0) >= 2
            && isset($stored['expenditure']['housing'])
            && is_array($stored['expenditure']['housing']);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function map(array $stored, array $defaults): array
    {
        if ($this->isV2($stored)) {
            return $this->mergeV2($defaults, $stored);
        }

        return $this->mapFromV1($stored, $defaults);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function mergeV2(array $defaults, array $stored): array
    {
        $merged = array_replace_recursive($defaults, $stored);
        $merged['schema_version'] = 2;
        $merged['income'] = array_merge($defaults['income'], is_array($stored['income'] ?? null) ? $stored['income'] : []);
        $merged['household'] = array_merge($defaults['household'], is_array($stored['household'] ?? null) ? $stored['household'] : []);
        $merged['flags'] = array_merge($defaults['flags'], is_array($stored['flags'] ?? null) ? $stored['flags'] : []);

        if (isset($stored['income']['meta']) && is_array($stored['income']['meta'])) {
            $merged['income']['meta'] = array_replace_recursive(
                $defaults['income']['meta'],
                $stored['income']['meta']
            );
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $v1
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function mapFromV1(array $v1, array $defaults): array
    {
        $v2 = $defaults;
        $v2['schema_version'] = 2;

        $household = is_array($v1['household'] ?? null) ? $v1['household'] : [];
        $adults = max(1, (int) ($household['adults'] ?? 1));
        $under16 = max(0, (int) ($household['children_under_16'] ?? 0));
        $age1618 = max(0, (int) ($household['children_16_18'] ?? 0));

        $v2['household']['adults'] = $adults;
        $v2['household']['children_under_16'] = $under16;
        $v2['household']['children_16_18'] = $age1618;
        $v2['household']['children'] = [];
        $v2['household']['partner_exists'] = $adults > 1;
        $v2['household']['size'] = $adults + $under16 + $age1618;
        $v2['household']['council_tax_counting_adults'] = null;

        $income = is_array($v1['income'] ?? null) ? $v1['income'] : [];
        $incomeMap = [
            'salary' => 'client_salary',
            'client_salary' => 'client_salary',
            'partner_salary' => 'partner_salary',
            'self_employed' => 'self_employed',
            'universal_credit' => 'universal_credit',
            'child_benefit' => 'child_benefit',
            'child_maintenance_in' => 'maintenance_received',
            'maintenance_received' => 'maintenance_received',
            'pensions' => 'pension',
            'pension' => 'pension',
            'pip_dla' => 'pip_dla',
            'esa' => 'esa',
            'carers_allowance' => 'carers_allowance',
            'student' => 'student',
            'foster_guardianship' => 'foster_guardianship',
            'other_income' => 'other_income',
            'uc_advance_add_back' => 'uc_advance_add_back',
        ];

        foreach ($incomeMap as $from => $to) {
            if (array_key_exists($from, $income)) {
                $v2['income'][$to] = round((float) $income[$from], 2);
            }
        }

        $expenditure = is_array($v1['expenditure'] ?? null) ? $v1['expenditure'] : [];

        $v2['expenditure']['housing']['rent_mortgage'] = $this->money($expenditure['rent_mortgage'] ?? 0);
        $v2['expenditure']['housing']['council_tax'] = $this->money($expenditure['council_tax'] ?? 0);
        $v2['expenditure']['housing']['tv_licence'] = $defaults['expenditure']['housing']['tv_licence'];

        $v2['expenditure']['utilities']['electricity'] = $this->money($expenditure['electricity'] ?? $expenditure['electric'] ?? 0);
        $v2['expenditure']['utilities']['gas'] = $this->money($expenditure['gas'] ?? 0);
        $v2['expenditure']['utilities']['water'] = $this->money($expenditure['water'] ?? 0);

        $v2['expenditure']['sfs']['housekeeping'] = $this->money($expenditure['housekeeping'] ?? $expenditure['food'] ?? 0);
        $v2['expenditure']['sfs']['comms']['home_internet_tv'] = $this->money($expenditure['internet_tv'] ?? 0);
        $v2['expenditure']['sfs']['comms']['mobile'] = $this->money($expenditure['mobile_phone'] ?? 0);
        $v2['expenditure']['sfs']['comms']['leisure'] = $this->money($expenditure['hobbies'] ?? 0);
        $v2['expenditure']['sfs']['comms']['total'] = round(
            $v2['expenditure']['sfs']['comms']['home_internet_tv']
            + $v2['expenditure']['sfs']['comms']['mobile']
            + $v2['expenditure']['sfs']['comms']['leisure'],
            2
        );

        $personalTotal = $this->money($expenditure['personal'] ?? 0);
        $v2['expenditure']['sfs']['personal']['total'] = $personalTotal;
        $v2['expenditure']['sfs']['personal']['clothing'] = 0.0;
        $v2['expenditure']['sfs']['personal']['hairdressing'] = 0.0;
        $v2['expenditure']['sfs']['personal']['toiletries'] = 0.0;

        $v2['expenditure']['transport']['household']['public_transport'] = $this->money($expenditure['public_transport'] ?? 0);
        $v2['expenditure']['transport']['household']['car_finance'] = $this->money($expenditure['car_finance'] ?? 0);
        $v2['expenditure']['transport']['household']['car_insurance'] = $this->money($expenditure['car_insurance'] ?? 0);
        $v2['expenditure']['transport']['household']['road_tax'] = $this->money($expenditure['road_tax'] ?? $expenditure['car_tax'] ?? 0);
        $v2['expenditure']['transport']['household']['mot_maintenance'] = $this->money($expenditure['mot_maintenance'] ?? 0);
        $v2['expenditure']['transport']['household']['breakdown_cover'] = $this->money($expenditure['breakdown_cover'] ?? 0);
        $v2['expenditure']['transport']['household']['fuel'] = $this->money($expenditure['fuel'] ?? 0);

        $v2['expenditure']['other']['childcare'] = $this->money($expenditure['childcare'] ?? 0);
        $v2['expenditure']['other']['adult_care'] = $this->money($expenditure['adult_care'] ?? 0);
        $v2['expenditure']['other']['maintenance_paid'] = $this->money($expenditure['maintenance_paid'] ?? $expenditure['child_maintenance_out'] ?? 0);
        $v2['expenditure']['other']['prescriptions'] = $this->money($expenditure['prescriptions'] ?? 0);
        $v2['expenditure']['other']['dentistry'] = $this->money($expenditure['dentistry'] ?? 0);
        $v2['expenditure']['other']['other'] = $this->money($expenditure['other'] ?? 0);

        if (isset($v1['flags']) && is_array($v1['flags'])) {
            $v2['flags'] = array_merge($v2['flags'], $v1['flags']);
        }

        if (isset($v1['facts']['target_di']) || isset($v1['calculation']['target_di']) || isset($v1['target_di'])) {
            $target = $v1['facts']['target_di'] ?? $v1['calculation']['target_di'] ?? $v1['target_di'];
            $v2['facts']['target_di'] = $target === null ? null : (float) $target;
            $v2['calculation']['target_di'] = $v2['facts']['target_di'];
        }

        return $v2;
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
