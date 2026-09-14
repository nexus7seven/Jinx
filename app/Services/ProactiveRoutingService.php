<?php

namespace App\Services;

use App\Models\Lead;

class ProactiveRoutingService
{
    public function evaluate(array $profile, array $facts, ?Lead $lead = null): ?array
    {
        // Never interrupt an I&E with routing advice. Finish the complete I&E first,
        // including all required income/manual questions and target optimisation.
        // Only consider another destination when the finished I&E cannot reach target DI.
        if (! $this->ieIsComplete($facts)) return null;

        $di = $this->number($facts, ['calculation.disposable_income', 'calculation.di']);
        $targetDi = $this->number($facts, ['calculation.target_di', 'target_di']);
        if ($di === null || $targetDi === null || $di >= $targetDi) return null;

        $destination = $this->currentDestination($profile);
        if (! $destination) return null;
        $rules = $this->hardRules($destination);
        if ($rules === []) return null;

        $failures = ["Completed I&E disposable income £{$this->money($di)} is below the requested target DI of £{$this->money($targetDi)}."];
        $debt = $this->number($facts, ['debt.total', 'case.total_debt']) ?? $this->numeric($lead?->estimated_total_debt);
        $income = $this->number($facts, ['income.client_total', 'income.total_client']) ?? $this->numeric($lead?->monthly_income);
        $repayment = $this->number($facts, ['calculation.total_repayment', 'calculation.term_repayment']);

        if (isset($rules['min_di']) && $di < $rules['min_di']) $failures[] = "Disposable income £{$this->money($di)} is below {$destination}'s £{$this->money($rules['min_di'])} minimum.";
        if (isset($rules['min_debt']) && $debt !== null && $debt < $rules['min_debt']) $failures[] = "Total debt £{$this->money($debt)} is below {$destination}'s £{$this->money($rules['min_debt'])} minimum.";
        if (isset($rules['min_income']) && $income !== null && $income < $rules['min_income']) $failures[] = "Client income £{$this->money($income)} is below {$destination}'s £{$this->money($rules['min_income'])} minimum.";
        if (isset($rules['min_repayment']) && $repayment !== null && $repayment <= $rules['min_repayment']) $failures[] = "Total proposed repayment £{$this->money($repayment)} does not exceed {$destination}'s £{$this->money($rules['min_repayment'])} requirement.";

        return ['current_destination'=>$destination,'hard_failures'=>$failures,'action'=>'The I&E is complete and target DI was not reached. Now run a cross-destination suitability comparison and surface a better fit if supported by the known criteria.'];
    }

    private function ieIsComplete(array $facts): bool
    {
        return ($facts['workflow.ie_active'] ?? false) === true
            && ($facts['workflow.income_complete'] ?? false) === true
            && ($facts['workflow.ie_complete'] ?? false) === true;
    }

    private function currentDestination(array $profile): ?string
    {
        if (($profile['partner'] ?? null) === 'Zebra') return 'Zebra';
        if (($profile['partner'] ?? null) === 'Avondale' && filled($profile['ip'] ?? null)) return (string)$profile['ip'];
        return null;
    }

    private function hardRules(string $destination): array
    {
        return match ($destination) {
            'Zebra' => ['min_di'=>110,'min_debt'=>7000,'min_income'=>1000,'min_repayment'=>6600],
            'Lawson Fox' => ['min_di'=>110,'min_debt'=>7500,'min_income'=>1000],
            'Assure' => ['min_di'=>110,'min_debt'=>7000,'min_income'=>1000,'min_repayment'=>6600],
            'TIG' => ['min_di'=>100,'min_debt'=>6000],
            default => [],
        };
    }

    private function number(array $facts,array $keys): ?float { foreach($keys as $key) if(array_key_exists($key,$facts)&&is_numeric($facts[$key])) return(float)$facts[$key]; return null; }
    private function numeric(mixed $value): ?float { return is_numeric($value)?(float)$value:null; }
    private function money(float|int $value): string { return number_format((float)$value,0,'.',','); }
}
