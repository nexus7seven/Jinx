<?php

namespace App\Services;

class ZebraIeCalculatorService
{
    public function snapshot(array $facts): array
    {
        $partnerExists = $this->boolFact($facts, 'household.partner_exists');
        $children = $this->numberFact($facts, 'household.children_count');
        $ages = $this->arrayFact($facts, 'household.children_ages');

        $result = [
            'housing.tv_licence' => 15,
        ];

        if ($partnerExists !== null && $children !== null) {
            $adults = $partnerExists ? 2 : 1;
            $householdSize = $adults + (int) $children;
            $result['household.adults'] = $adults;
            $result['household.size'] = $householdSize;
            $result['utilities'] = $this->utilityRanges($householdSize);
        }

        if ($partnerExists !== null && $children !== null && count($ages) === (int) $children) {
            $under16 = count(array_filter($ages, fn ($age) => is_numeric($age) && (int) $age < 16));
            $age16to18 = count(array_filter($ages, fn ($age) => is_numeric($age) && (int) $age >= 16 && (int) $age <= 18));
            $result['household.child_under_16'] = $under16;
            $result['household.child_16_18'] = $age16to18;
            $result['sfs'] = $this->sfsRanges($partnerExists ? 2 : 1, $under16, $age16to18);
        }

        $qualifyingChildren = $this->numberFact($facts, 'income.child_benefit_qualifying_children');
        if ($qualifyingChildren !== null) {
            $result['income.child_benefit'] = $this->childBenefit((int) $qualifyingChildren);
        }

        $clientTransport = strtolower((string) ($facts['transport.client.mode'] ?? ''));
        if ($clientTransport === 'car') {
            $result['transport.client'] = $this->carDefaults();
        } elseif ($clientTransport === 'public transport') {
            $result['transport.client'] = ['public_transport' => ['min' => 0, 'max' => 120]];
        }

        $partnerTransport = strtolower((string) ($facts['transport.partner.mode'] ?? ''));
        if ($partnerExists === true && $partnerTransport === 'car') {
            $result['transport.partner'] = $this->carDefaults();
        } elseif ($partnerExists === true && $partnerTransport === 'public transport') {
            $result['transport.partner'] = ['public_transport' => ['min' => 0, 'max' => 120]];
        }

        $targetDi = $this->numberFact($facts, 'calculation.target_di');
        $totalIncome = $this->numberFact($facts, 'income.total');
        if ($targetDi !== null && $totalIncome !== null) {
            $result['calculation.target_di'] = $targetDi;
            $result['calculation.required_total_expenditure'] = $totalIncome - $targetDi;
        }

        return $result;
    }

    public function childBenefit(int $qualifyingChildren): int
    {
        if ($qualifyingChildren <= 0) {
            return 0;
        }

        $weekly = 27.05 + (17.90 * max(0, $qualifyingChildren - 1));
        return (int) ceil($weekly * 52 / 12);
    }

    public function utilityRanges(int $householdSize): array
    {
        $householdSize = max(1, $householdSize);
        $energy = match (true) {
            $householdSize === 1 => [30, 80],
            $householdSize === 2 => [50, 100],
            $householdSize === 3 => [75, 120],
            $householdSize === 4 => [80, 160],
            default => [80 + (15 * ($householdSize - 4)), 160 + (25 * ($householdSize - 4))],
        };
        $water = match (true) {
            $householdSize === 1 => [30, 70],
            $householdSize === 2 => [40, 80],
            $householdSize === 3 => [50, 90],
            $householdSize === 4 => [60, 100],
            default => [60 + (10 * ($householdSize - 4)), 100 + (10 * ($householdSize - 4))],
        };

        return [
            'electricity' => ['min' => $energy[0], 'max' => $energy[1]],
            'gas' => ['min' => $energy[0], 'max' => $energy[1]],
            'water' => ['min' => $water[0], 'max' => $water[1]],
        ];
    }

    public function sfsRanges(int $adults, int $childUnder16, int $child16to18): array
    {
        $additionalAdults = max(0, $adults - 1);

        return [
            'housekeeping' => $this->sfsSection([317.80, 454.00], [233.10, 333.00], [137.90, 197.00], [164.50, 235.00], $additionalAdults, $childUnder16, $child16to18),
            'communication_leisure' => $this->sfsSection([175.00, 250.00], [125.30, 179.00], [60.90, 87.00], [98.00, 140.00], $additionalAdults, $childUnder16, $child16to18),
            'personal' => $this->sfsSection([66.50, 95.00], [46.90, 67.00], [32.90, 47.00], [73.50, 105.00], $additionalAdults, $childUnder16, $child16to18),
        ];
    }

    private function sfsSection(array $first, array $additional, array $under16, array $age16to18, int $additionalAdults, int $childrenUnder16, int $children16to18): array
    {
        $min = $first[0] + ($additional[0] * $additionalAdults) + ($under16[0] * $childrenUnder16) + ($age16to18[0] * $children16to18);
        $max = $first[1] + ($additional[1] * $additionalAdults) + ($under16[1] * $childrenUnder16) + ($age16to18[1] * $children16to18);

        return ['min' => (int) ceil($min), 'max' => (int) ceil($max)];
    }

    private function carDefaults(): array
    {
        return [
            'fuel' => 150,
            'mot_maintenance' => ['min' => 15, 'max' => 25],
            'road_tax' => ['min' => 15, 'max' => 25],
        ];
    }

    private function boolFact(array $facts, string $key): ?bool
    {
        if (! array_key_exists($key, $facts)) {
            return null;
        }
        return filter_var($facts[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function numberFact(array $facts, string $key): ?float
    {
        return array_key_exists($key, $facts) && is_numeric($facts[$key]) ? (float) $facts[$key] : null;
    }

    private function arrayFact(array $facts, string $key): array
    {
        return isset($facts[$key]) && is_array($facts[$key]) ? array_values($facts[$key]) : [];
    }
}
