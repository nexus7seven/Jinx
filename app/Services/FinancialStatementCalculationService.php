<?php

namespace App\Services;

class FinancialStatementCalculationService
{
    public const TV_LICENCE_MONTHLY = 15.0;

    public function __construct(
        private readonly SpendingGuidelineService $spendingGuidelineService
    ) {
    }

    /**
     * Apply deterministic Phase 1 calculations. Does not optimise toward target DI.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public function apply(array $statement): array
    {
        $statement = $this->applyHouseholdDerived($statement);
        $statement = $this->applyTvLicence($statement);
        $statement = $this->syncSfsSectionTotals($statement);
        $analysis = $this->sfsAnalysis($statement);
        $statement['calculation']['sfs'] = $analysis;
        foreach (array_keys($analysis) as $band) {
            $this->markDerived($statement, "calculation.sfs.{$band}");
        }
        $statement = $this->applyTotals($statement);
        $statement = $this->ensureFlags($statement);

        return $statement;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public function applyHouseholdDerived(array $statement): array
    {
        $household = is_array($statement['household'] ?? null) ? $statement['household'] : [];
        $adults = max(1, (int) ($household['adults'] ?? 1));
        $children = is_array($household['children'] ?? null) ? $household['children'] : [];
        $partnerExists = array_key_exists('partner_exists', $household)
            ? (bool) $household['partner_exists']
            : $adults > 1;

        if ($partnerExists) {
            $adults = max($adults, 2);
        }

        $fromAges = $this->childBandsFromAges($children);

        if ($fromAges['counted'] > 0) {
            $under16 = $fromAges['children_under_16'];
            $age1618 = $fromAges['children_16_18'];
            $childCount = count($children);
            $this->markDerived($statement, 'household.children_under_16');
            $this->markDerived($statement, 'household.children_16_18');
        } else {
            $under16 = max(0, (int) ($household['children_under_16'] ?? 0));
            $age1618 = max(0, (int) ($household['children_16_18'] ?? 0));
            $childCount = $under16 + $age1618;
        }

        $statement['household'] = array_merge($household, [
            'adults' => $adults,
            'children' => $children,
            'children_under_16' => $under16,
            'children_16_18' => $age1618,
            'size' => $adults + $childCount,
            'partner_exists' => $partnerExists,
            'council_tax_counting_adults' => $household['council_tax_counting_adults'] ?? null,
        ]);

        $this->markDerived($statement, 'household.size');

        return $statement;
    }

    /**
     * @param  array<int, mixed>  $children
     * @return array{children_under_16: int, children_16_18: int, counted: int}
     */
    public function childBandsFromAges(array $children): array
    {
        $under16 = 0;
        $age1618 = 0;
        $counted = 0;

        foreach ($children as $child) {
            if (! is_array($child) || ! array_key_exists('age', $child) || $child['age'] === null || $child['age'] === '') {
                continue;
            }

            if (! is_numeric($child['age'])) {
                continue;
            }

            $age = (int) $child['age'];
            $counted++;

            if ($age < 16) {
                $under16++;
            } elseif ($age <= 18) {
                $age1618++;
            }
        }

        return [
            'children_under_16' => $under16,
            'children_16_18' => $age1618,
            'counted' => $counted,
        ];
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function applyTvLicence(array $statement): array
    {
        $statement['expenditure']['housing']['tv_licence'] = self::TV_LICENCE_MONTHLY;
        $this->markLine($statement, 'expenditure.housing.tv_licence', origin: 'calculated', flexible: false);

        return $statement;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function syncSfsSectionTotals(array $statement): array
    {
        $comms = $statement['expenditure']['sfs']['comms'] ?? [];
        $lineSum = round(
            (float) ($comms['home_internet_tv'] ?? 0)
            + (float) ($comms['mobile'] ?? 0)
            + (float) ($comms['leisure'] ?? 0),
            2
        );
        $statement['expenditure']['sfs']['comms']['total'] = $lineSum > 0.0
            ? $lineSum
            : round((float) ($comms['total'] ?? 0), 2);

        $personal = $statement['expenditure']['sfs']['personal'] ?? [];
        $personalLines = round(
            (float) ($personal['clothing'] ?? 0)
            + (float) ($personal['hairdressing'] ?? 0)
            + (float) ($personal['toiletries'] ?? 0),
            2
        );
        if ($personalLines > 0.0) {
            $statement['expenditure']['sfs']['personal']['total'] = $personalLines;
        } else {
            $statement['expenditure']['sfs']['personal']['total'] = round((float) ($personal['total'] ?? 0), 2);
        }

        return $statement;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, array{actual: float, min: float, max: float, pct_min: float|null, pct_max: float|null, headroom: float}>
     */
    public function sfsAnalysis(array $statement): array
    {
        $adults = (int) ($statement['household']['adults'] ?? 1);
        $under16 = (int) ($statement['household']['children_under_16'] ?? 0);
        $age1618 = (int) ($statement['household']['children_16_18'] ?? 0);

        $actuals = [
            'housekeeping' => round((float) ($statement['expenditure']['sfs']['housekeeping'] ?? 0), 2),
            'comms' => round((float) ($statement['expenditure']['sfs']['comms']['total'] ?? 0), 2),
            'personal' => round((float) ($statement['expenditure']['sfs']['personal']['total'] ?? 0), 2),
        ];

        $analysis = [];
        foreach ($actuals as $band => $actual) {
            $bounds = $this->spendingGuidelineService->bounds($band, $adults, $under16, $age1618);
            $min = $bounds['min'];
            $max = $bounds['max'];
            $analysis[$band] = [
                'actual' => $actual,
                'min' => $min,
                'max' => $max,
                'pct_min' => $min > 0 ? ($actual / $min) * 100 : null,
                'pct_max' => $max > 0 ? ($actual / $max) * 100 : null,
                'headroom' => $max - $actual,
            ];
        }

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function applyTotals(array $statement): array
    {
        $incomeTotal = $this->incomeTotal($statement);
        $expenditureTotal = $this->expenditureTotal($statement);
        $disposable = $incomeTotal - $expenditureTotal;

        $target = $statement['facts']['target_di'] ?? $statement['calculation']['target_di'] ?? null;
        $targetDi = $target === null || $target === '' ? null : (float) $target;

        $required = $targetDi === null ? null : $incomeTotal - $targetDi;
        $variance = $targetDi === null ? null : $disposable - $targetDi;

        $statement['calculation']['income_total'] = $incomeTotal;
        $statement['calculation']['expenditure_total'] = $expenditureTotal;
        $statement['calculation']['disposable_income'] = $disposable;
        $statement['calculation']['target_di'] = $targetDi;
        $statement['calculation']['required_expenditure'] = $required;
        $statement['calculation']['variance_to_target'] = $variance;
        $statement['facts']['target_di'] = $targetDi;

        $this->markDerived($statement, 'calculation.income_total');
        $this->markDerived($statement, 'calculation.expenditure_total');
        $this->markDerived($statement, 'calculation.disposable_income');

        return $statement;
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    public function incomeTotal(array $statement): float
    {
        $sum = 0.0;
        $income = is_array($statement['income'] ?? null) ? $statement['income'] : [];

        foreach ($income as $key => $value) {
            if ($key === 'meta' || is_array($value)) {
                continue;
            }
            $sum += (float) $value;
        }

        return round($sum, 2);
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    public function expenditureTotal(array $statement): float
    {
        $expenditure = is_array($statement['expenditure'] ?? null) ? $statement['expenditure'] : [];
        $sum = 0.0;

        $sum += (float) ($expenditure['housing']['rent_mortgage'] ?? 0);
        $sum += (float) ($expenditure['housing']['council_tax'] ?? 0);
        $sum += (float) ($expenditure['housing']['tv_licence'] ?? 0);

        $sum += (float) ($expenditure['utilities']['electricity'] ?? 0);
        $sum += (float) ($expenditure['utilities']['gas'] ?? 0);
        $sum += (float) ($expenditure['utilities']['water'] ?? 0);

        $sum += (float) ($expenditure['sfs']['housekeeping'] ?? 0);
        $sum += (float) ($expenditure['sfs']['comms']['total'] ?? 0);
        $sum += (float) ($expenditure['sfs']['personal']['total'] ?? 0);

        foreach (['household', 'client', 'partner'] as $who) {
            $bucket = $expenditure['transport'][$who] ?? [];
            if (! is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $value) {
                if (! is_array($value)) {
                    $sum += (float) $value;
                }
            }
        }

        $other = $expenditure['other'] ?? [];
        if (is_array($other)) {
            foreach ($other as $value) {
                if (! is_array($value)) {
                    $sum += (float) $value;
                }
            }
        }

        return round($sum, 2);
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function ensureFlags(array $statement): array
    {
        $flags = is_array($statement['flags'] ?? null) ? $statement['flags'] : [];
        $statement['flags']['rule_required'] = array_values(array_filter(
            $flags['rule_required'] ?? [],
            fn ($item) => is_string($item) && $item !== ''
        ));
        $statement['flags']['calculator_required'] = array_values(array_filter(
            $flags['calculator_required'] ?? [],
            fn ($item) => is_string($item) && $item !== ''
        ));

        return $statement;
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function markLine(array &$statement, string $path, string $origin, bool $flexible): void
    {
        $statement['line_meta'][$path] = [
            'origin' => $origin,
            'flexible' => $flexible,
        ];
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function markDerived(array &$statement, string $path): void
    {
        $statement['line_meta'][$path] = [
            'origin' => 'calculated',
            'flexible' => false,
        ];
    }
}
