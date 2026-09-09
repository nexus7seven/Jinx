<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\Validator;

class FinancialStatementService
{
    /**
     * Flat I&E card codes → nested v2 expenditure paths.
     *
     * @var array<string, list<string>>
     */
    public const UI_EXPENDITURE_PATHS = [
        'rent_mortgage' => ['housing', 'rent_mortgage'],
        'council_tax' => ['housing', 'council_tax'],
        'tv_licence' => ['housing', 'tv_licence'],
        'electricity' => ['utilities', 'electricity'],
        'gas' => ['utilities', 'gas'],
        'water' => ['utilities', 'water'],
        'internet_tv' => ['sfs', 'comms', 'home_internet_tv'],
        'mobile_phone' => ['sfs', 'comms', 'mobile'],
        'hobbies' => ['sfs', 'comms', 'leisure'],
        'public_transport' => ['transport', 'household', 'public_transport'],
        'car_finance' => ['transport', 'household', 'car_finance'],
        'car_insurance' => ['transport', 'household', 'car_insurance'],
        'road_tax' => ['transport', 'household', 'road_tax'],
        'mot_maintenance' => ['transport', 'household', 'mot_maintenance'],
        'breakdown_cover' => ['transport', 'household', 'breakdown_cover'],
        'fuel' => ['transport', 'household', 'fuel'],
        'housekeeping' => ['sfs', 'housekeeping'],
        'personal' => ['sfs', 'personal', 'total'],
        'childcare' => ['other', 'childcare'],
        'adult_care' => ['other', 'adult_care'],
        'maintenance_paid' => ['other', 'maintenance_paid'],
        'prescriptions' => ['other', 'prescriptions'],
        'dentistry' => ['other', 'dentistry'],
        'other' => ['other', 'other'],
    ];

    public function __construct(
        private readonly FinancialStatementV1ToV2Mapper $v1ToV2Mapper,
        private readonly FinancialStatementCalculationService $calculationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyState(): array
    {
        $income = [];
        foreach (config('financial_statement.income') as $row) {
            $income[$row['code']] = 0.0;
        }

        $income['meta'] = [
            'pip_dla' => ['recipient' => null, 'type' => null],
            'student' => ['type' => null],
        ];

        $zeroTransport = [
            'fuel' => 0.0,
            'mot_maintenance' => 0.0,
            'road_tax' => 0.0,
            'car_insurance' => 0.0,
            'public_transport' => 0.0,
        ];

        return [
            'schema_version' => 2,
            'guidelines_version' => config('sfs_spending_guidelines.version'),
            'status' => 'INCOMPLETE_INFORMATION',
            'flags' => [
                'rule_required' => [],
                'calculator_required' => [],
            ],
            'household' => [
                'partner_exists' => false,
                'adults' => 1,
                'children' => [],
                'children_under_16' => 0,
                'children_16_18' => 0,
                'size' => 1,
                'council_tax_counting_adults' => null,
            ],
            'facts' => [
                'target_di' => null,
                'client_transport_mode' => null,
                'partner_transport_mode' => null,
                'housing_type' => null,
                'council_tax_source' => null,
            ],
            'income' => $income,
            'expenditure' => [
                'housing' => [
                    'rent_mortgage' => 0.0,
                    'council_tax' => 0.0,
                    'tv_licence' => FinancialStatementCalculationService::TV_LICENCE_MONTHLY,
                ],
                'utilities' => [
                    'electricity' => 0.0,
                    'gas' => 0.0,
                    'water' => 0.0,
                ],
                'sfs' => [
                    'housekeeping' => 0.0,
                    'comms' => [
                        'home_internet_tv' => 0.0,
                        'mobile' => 0.0,
                        'leisure' => 0.0,
                        'total' => 0.0,
                    ],
                    'personal' => [
                        'clothing' => 0.0,
                        'hairdressing' => 0.0,
                        'toiletries' => 0.0,
                        'total' => 0.0,
                    ],
                ],
                'transport' => [
                    'household' => [
                        'public_transport' => 0.0,
                        'car_finance' => 0.0,
                        'car_insurance' => 0.0,
                        'road_tax' => 0.0,
                        'mot_maintenance' => 0.0,
                        'breakdown_cover' => 0.0,
                        'fuel' => 0.0,
                    ],
                    'client' => $zeroTransport,
                    'partner' => $zeroTransport,
                ],
                'other' => [
                    'childcare' => 0.0,
                    'maintenance_paid' => 0.0,
                    'dla_care' => 0.0,
                    'pip_care' => 0.0,
                    'student_offset' => 0.0,
                    'adult_care' => 0.0,
                    'prescriptions' => 0.0,
                    'dentistry' => 0.0,
                    'other' => 0.0,
                ],
            ],
            'line_meta' => [
                'expenditure.housing.tv_licence' => [
                    'origin' => 'calculated',
                    'flexible' => false,
                ],
            ],
            'lookups' => [
                'council_tax' => [
                    'job_id' => null,
                    'band' => null,
                    'annual_listed' => null,
                    'single_person_discount_applied' => false,
                    'matched_address' => null,
                    'status' => null,
                ],
            ],
            'calculation' => [
                'income_total' => 0.0,
                'expenditure_total' => 0.0,
                'disposable_income' => 0.0,
                'target_di' => null,
                'required_expenditure' => null,
                'variance_to_target' => null,
                'sfs' => [
                    'housekeeping' => $this->emptySfsAnalysisRow(),
                    'comms' => $this->emptySfsAnalysisRow(),
                    'personal' => $this->emptySfsAnalysisRow(),
                ],
            ],
        ];
    }

    /**
     * Canonical v2 document for the lead. Maps stored v1 in memory only.
     *
     * @return array<string, mixed>
     */
    public function mergeForLead(Lead $lead): array
    {
        $defaults = $this->emptyState();
        $stored = $lead->financial_statement;

        if (! is_array($stored)) {
            return $defaults;
        }

        return $this->v1ToV2Mapper->map($stored, $defaults);
    }

    /**
     * Flattened projection for the existing staff/partner I&E card, including overview fields.
     *
     * @return array<string, mixed>
     */
    public function uiStateForLead(Lead $lead): array
    {
        return $this->flattenForUi($this->mergeForLead($lead));
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public function flattenForUi(array $statement): array
    {
        $expenditure = [];
        foreach (self::UI_EXPENDITURE_PATHS as $code => $path) {
            $expenditure[$code] = $this->valueAtPath($statement['expenditure'] ?? [], $path);
        }

        $calculation = is_array($statement['calculation'] ?? null) ? $statement['calculation'] : [];
        $facts = is_array($statement['facts'] ?? null) ? $statement['facts'] : [];
        $flags = is_array($statement['flags'] ?? null) ? $statement['flags'] : [];

        return [
            'schema_version' => 2,
            'guidelines_version' => $statement['guidelines_version'] ?? config('sfs_spending_guidelines.version'),
            'status' => $statement['status'] ?? null,
            'flags' => [
                'rule_required' => array_values($flags['rule_required'] ?? []),
                'calculator_required' => array_values($flags['calculator_required'] ?? []),
            ],
            'facts' => [
                'target_di' => $facts['target_di'] ?? ($calculation['target_di'] ?? null),
            ],
            'calculation' => [
                'income_total' => $calculation['income_total'] ?? null,
                'expenditure_total' => $calculation['expenditure_total'] ?? null,
                'disposable_income' => $calculation['disposable_income'] ?? null,
                'target_di' => $calculation['target_di'] ?? ($facts['target_di'] ?? null),
                'variance_to_target' => $calculation['variance_to_target'] ?? null,
                'sfs' => $calculation['sfs'] ?? null,
            ],
            'household' => $statement['household'] ?? [],
            'income' => $statement['income'] ?? [],
            'expenditure' => $expenditure,
        ];
    }

    /**
     * Client-side config for the Income & Expenditure UI (must stay aligned with merge/empty state).
     *
     * @return array{income: mixed, expenditure_sections: mixed, guidelineBands: mixed, guidelinesVersion: mixed, household_limits: mixed}
     */
    public function clientViewPayload(): array
    {
        return [
            'income' => config('financial_statement.income'),
            'expenditure_sections' => config('financial_statement.expenditure_sections'),
            'guidelineBands' => $this->uiGuidelineCaps(),
            'guidelinesVersion' => config('sfs_spending_guidelines.version'),
            'household_limits' => config('financial_statement.household_limits'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function persistForLead(Lead $lead, array $input): array
    {
        $base = $this->mergeForLead($lead);
        $payload = $this->normalizeFromRequest($input, $base);
        $payload = $this->calculationService->apply($payload);
        $payload['schema_version'] = 2;
        $payload['guidelines_version'] = config('sfs_spending_guidelines.version');

        $lead->financial_statement = $payload;
        $lead->save();

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $base
     * @return array<string, mixed>
     */
    public function normalizeFromRequest(array $input, ?array $base = null): array
    {
        $base = $base ?? $this->emptyState();

        if ($this->looksLikeNestedV2($input)) {
            return $this->normalizeNestedV2($input, $base);
        }

        return $this->normalizeFlatUi($input, $base);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function looksLikeNestedV2(array $input): bool
    {
        return isset($input['expenditure']['housing']) && is_array($input['expenditure']['housing']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function normalizeNestedV2(array $input, array $base): array
    {
        $limits = config('financial_statement.household_limits');
        $incomeCodes = array_column(config('financial_statement.income'), 'code');

        $rules = [
            'household.adults' => ['nullable', 'integer', 'min:'.$limits['adults_min'], 'max:'.$limits['adults_max']],
            'household.children_under_16' => ['nullable', 'integer', 'min:'.$limits['children_min'], 'max:'.$limits['children_max']],
            'household.children_16_18' => ['nullable', 'integer', 'min:'.$limits['children_min'], 'max:'.$limits['children_max']],
            'household.partner_exists' => ['nullable', 'boolean'],
            'household.council_tax_counting_adults' => ['nullable', 'integer', 'min:0', 'max:'.$limits['adults_max']],
            'household.children' => ['nullable', 'array'],
            'household.children.*.age' => ['nullable', 'integer', 'min:0', 'max:25'],
            'facts.target_di' => ['nullable', 'numeric'],
            'calculation.target_di' => ['nullable', 'numeric'],
            'flags.rule_required' => ['nullable', 'array'],
            'flags.calculator_required' => ['nullable', 'array'],
            'flags.rule_required.*' => ['string'],
            'flags.calculator_required.*' => ['string'],
        ];

        foreach ($incomeCodes as $code) {
            $rules["income.$code"] = ['nullable', 'numeric', 'min:0'];
        }

        foreach (self::UI_EXPENDITURE_PATHS as $path) {
            $rules['expenditure.'.implode('.', $path)] = ['nullable', 'numeric', 'min:0'];
        }

        $rules['expenditure.sfs.comms.total'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.sfs.personal.clothing'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.sfs.personal.hairdressing'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.sfs.personal.toiletries'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.client.fuel'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.client.mot_maintenance'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.client.road_tax'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.client.car_insurance'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.client.public_transport'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.partner.fuel'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.partner.mot_maintenance'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.partner.road_tax'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.partner.car_insurance'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.transport.partner.public_transport'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.other.dla_care'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.other.pip_care'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.other.student_offset'] = ['nullable', 'numeric', 'min:0'];

        Validator::make($input, $rules)->validate();

        $merged = array_replace_recursive($base, $input);
        $merged['schema_version'] = 2;
        $merged['guidelines_version'] = config('sfs_spending_guidelines.version');

        if (isset($input['income']['meta']) && is_array($input['income']['meta'])) {
            $merged['income']['meta'] = array_replace_recursive(
                $base['income']['meta'] ?? [],
                $input['income']['meta']
            );
        } else {
            $merged['income']['meta'] = $base['income']['meta'] ?? [];
        }

        $target = $input['facts']['target_di'] ?? $input['calculation']['target_di'] ?? $base['facts']['target_di'] ?? null;
        $merged['facts']['target_di'] = $target === null || $target === '' ? null : (float) $target;

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function normalizeFlatUi(array $input, array $base): array
    {
        $limits = config('financial_statement.household_limits');
        $incomeCodes = array_column(config('financial_statement.income'), 'code');
        $expenditureCodes = array_keys(self::UI_EXPENDITURE_PATHS);

        $rules = [
            'household.adults' => ['required', 'integer', 'min:'.$limits['adults_min'], 'max:'.$limits['adults_max']],
            'household.children_under_16' => ['required', 'integer', 'min:'.$limits['children_min'], 'max:'.$limits['children_max']],
            'household.children_16_18' => ['required', 'integer', 'min:'.$limits['children_min'], 'max:'.$limits['children_max']],
            'facts.target_di' => ['nullable', 'numeric'],
        ];

        foreach ($incomeCodes as $code) {
            $rules["income.$code"] = ['nullable', 'numeric', 'min:0'];
        }
        $rules['income.salary'] = ['nullable', 'numeric', 'min:0'];
        $rules['income.pensions'] = ['nullable', 'numeric', 'min:0'];
        $rules['income.child_maintenance_in'] = ['nullable', 'numeric', 'min:0'];

        foreach ($expenditureCodes as $code) {
            $rules["expenditure.$code"] = ['nullable', 'numeric', 'min:0'];
        }
        $rules['expenditure.salary'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.food'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.electric'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.car_tax'] = ['nullable', 'numeric', 'min:0'];
        $rules['expenditure.child_maintenance_out'] = ['nullable', 'numeric', 'min:0'];

        $validated = Validator::make($input, $rules)->validate();

        $base['household']['adults'] = (int) $validated['household']['adults'];
        $base['household']['children_under_16'] = (int) $validated['household']['children_under_16'];
        $base['household']['children_16_18'] = (int) $validated['household']['children_16_18'];

        $incomeInput = $validated['income'] ?? [];
        foreach ($incomeCodes as $code) {
            if (array_key_exists($code, $incomeInput)) {
                $base['income'][$code] = round((float) $incomeInput[$code], 2);
            }
        }
        if (array_key_exists('salary', $incomeInput) && ! array_key_exists('client_salary', $incomeInput)) {
            $base['income']['client_salary'] = round((float) $incomeInput['salary'], 2);
        }
        if (array_key_exists('pensions', $incomeInput) && ! array_key_exists('pension', $incomeInput)) {
            $base['income']['pension'] = round((float) $incomeInput['pensions'], 2);
        }
        if (array_key_exists('child_maintenance_in', $incomeInput) && ! array_key_exists('maintenance_received', $incomeInput)) {
            $base['income']['maintenance_received'] = round((float) $incomeInput['child_maintenance_in'], 2);
        }

        $expenditureInput = $validated['expenditure'] ?? [];
        if (array_key_exists('food', $expenditureInput) && ! array_key_exists('housekeeping', $expenditureInput)) {
            $expenditureInput['housekeeping'] = $expenditureInput['food'];
        }
        if (array_key_exists('electric', $expenditureInput) && ! array_key_exists('electricity', $expenditureInput)) {
            $expenditureInput['electricity'] = $expenditureInput['electric'];
        }
        if (array_key_exists('car_tax', $expenditureInput) && ! array_key_exists('road_tax', $expenditureInput)) {
            $expenditureInput['road_tax'] = $expenditureInput['car_tax'];
        }
        if (array_key_exists('child_maintenance_out', $expenditureInput) && ! array_key_exists('maintenance_paid', $expenditureInput)) {
            $expenditureInput['maintenance_paid'] = $expenditureInput['child_maintenance_out'];
        }

        foreach (self::UI_EXPENDITURE_PATHS as $code => $path) {
            if (! array_key_exists($code, $expenditureInput)) {
                continue;
            }
            $this->setAtPath($base['expenditure'], $path, round((float) $expenditureInput[$code], 2));
        }

        if (array_key_exists('target_di', $validated['facts'] ?? [])) {
            $base['facts']['target_di'] = $validated['facts']['target_di'] === null
                ? null
                : (float) $validated['facts']['target_di'];
        }

        $base['schema_version'] = 2;

        return $base;
    }

    /**
     * Maximum-only coeffs in the shape the existing I&E card JavaScript expects.
     *
     * @return array<string, array{base_one_adult: float, additional_adult: float, child_under_16: float, child_16_18: float}>
     */
    private function uiGuidelineCaps(): array
    {
        $aliases = config('sfs_spending_guidelines.ui_cap_aliases', []);
        $caps = [];

        foreach ($aliases as $uiBand => $configBand) {
            $coeffs = config("sfs_spending_guidelines.bands.{$configBand}");
            if (! is_array($coeffs)) {
                continue;
            }
            $caps[$uiBand] = [
                'base_one_adult' => (float) $coeffs['first_adult']['max'],
                'additional_adult' => (float) $coeffs['additional_adult']['max'],
                'child_under_16' => (float) $coeffs['child_under_16']['max'],
                'child_16_18' => (float) $coeffs['child_16_18']['max'],
            ];
        }

        return $caps;
    }

    /**
     * @return array{actual: float, min: float, max: float, pct_min: null, pct_max: null, headroom: float}
     */
    private function emptySfsAnalysisRow(): array
    {
        return [
            'actual' => 0.0,
            'min' => 0.0,
            'max' => 0.0,
            'pct_min' => null,
            'pct_max' => null,
            'headroom' => 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  list<string>  $path
     */
    private function valueAtPath(array $root, array $path): float
    {
        $cursor = $root;
        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return 0.0;
            }
            $cursor = $cursor[$segment];
        }

        return is_numeric($cursor) ? (float) $cursor : 0.0;
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  list<string>  $path
     */
    private function setAtPath(array &$root, array $path, float $value): void
    {
        $cursor =& $root;
        $last = array_pop($path);
        foreach ($path as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor =& $cursor[$segment];
        }
        $cursor[$last] = $value;
    }
}
