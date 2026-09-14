<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class ProactiveRoutingService
{
    public function evaluate(array $profile, array $facts, ?Lead $lead = null): ?array
    {
        if (! $this->ieIsActive($facts)) {
            return null;
        }

        $destination = $this->currentDestination($profile);
        if (! $destination) {
            return null;
        }

        $rules = $this->hardRules($destination);
        if ($rules === []) {
            return null;
        }

        $failures = [];
        $di = $this->number($facts, ['calculation.disposable_income', 'calculation.di']);
        $debt = $this->number($facts, ['debt.total', 'case.total_debt']) ?? $this->numeric($lead?->estimated_total_debt);
        $income = $this->number($facts, ['income.client_total', 'income.total_client']) ?? $this->numeric($lead?->monthly_income);
        $repayment = $this->number($facts, ['calculation.total_repayment', 'calculation.term_repayment']);

        if (isset($rules['min_di']) && $di !== null && $di < $rules['min_di']) {
            $failures[] = "Disposable income £{$this->money($di)} is below {$destination}'s £{$this->money($rules['min_di'])} minimum.";
        }
        if (isset($rules['min_debt']) && $debt !== null && $debt < $rules['min_debt']) {
            $failures[] = "Total debt £{$this->money($debt)} is below {$destination}'s £{$this->money($rules['min_debt'])} minimum.";
        }
        if (isset($rules['min_income']) && $income !== null && $income < $rules['min_income']) {
            $failures[] = "Client income £{$this->money($income)} is below {$destination}'s £{$this->money($rules['min_income'])} minimum.";
        }
        if (isset($rules['min_repayment']) && $repayment !== null && $repayment <= $rules['min_repayment']) {
            $failures[] = "Total proposed repayment £{$this->money($repayment)} does not exceed {$destination}'s £{$this->money($rules['min_repayment'])} requirement.";
        }

        if ($failures === []) {
            return null;
        }

        return [
            'current_destination' => $destination,
            'hard_failures' => $failures,
            'action' => 'Run a cross-destination suitability comparison and surface a better fit if one is supported by the known criteria.',
        ];
    }

    private function ieIsActive(array $facts): bool
    {
        foreach (array_keys($facts) as $key) {
            if (Str::startsWith((string) $key, ['calculation.', 'income.', 'housing.', 'utilities.', 'sfs.', 'transport.', 'other.'])) {
                return true;
            }
        }

        return false;
    }

    private function currentDestination(array $profile): ?string
    {
        if (($profile['partner'] ?? null) === 'Zebra') {
            return 'Zebra';
        }

        if (($profile['partner'] ?? null) === 'Avondale' && filled($profile['ip'] ?? null)) {
            return (string) $profile['ip'];
        }

        return null;
    }

    private function hardRules(string $destination): array
    {
        return match ($destination) {
            'Zebra' => ['min_di' => 110, 'min_debt' => 7000, 'min_income' => 1000, 'min_repayment' => 6600],
            'Lawson Fox' => ['min_di' => 110, 'min_debt' => 7500, 'min_income' => 1000],
            'Assure' => ['min_di' => 110, 'min_debt' => 7000, 'min_income' => 1000, 'min_repayment' => 6600],
            'TIG' => ['min_di' => 100, 'min_debt' => 6000],
            default => [],
        };
    }

    private function number(array $facts, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $facts) && is_numeric($facts[$key])) {
                return (float) $facts[$key];
            }
        }

        return null;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function money(float|int $value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }
}
