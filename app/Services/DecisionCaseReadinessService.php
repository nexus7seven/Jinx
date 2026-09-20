<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class DecisionCaseReadinessService
{
    public function __construct(
        private readonly DecisionCaseFactService $facts,
        private readonly DecisionFactRegistryService $registry,
        private readonly DecisionDynamicRuleService $dynamicRules,
        private readonly PostcodeJurisdictionService $postcodeJurisdiction,
    ) {}

    public function check(Lead $lead): array
    {
        $lead->loadMissing('debts.creditor');
        $this->syncDerivedFacts($lead);

        $caseValues = $this->facts->leadValues($lead);
        $statement = is_array($lead->financial_statement) ? $lead->financial_statement : [];
        $debtTotal = (float) $lead->debts->sum('balance');

        if ($debtTotal <= 0) {
            return [
                'state' => 'needs_debts',
                'ready' => false,
                'known' => [],
                'missing' => [],
                'not_applicable' => [],
                'next_question' => null,
                'message' => 'The I&E is complete, but no debts are recorded yet. Add or import the debts before I run case reasoning.',
            ];
        }

        $known = [];
        $missing = [];
        $notApplicable = [];

        $definitions = collect($this->registry->definitions('case', true))
            ->filter(fn($definition) => ($definition['reasoning_required'] ?? false) === true)
            ->keyBy('fact_key');

        foreach ($this->dynamicRules->requiredFacts($lead) as $definition) {
            if (($definition['scope'] ?? 'case') !== 'case') continue;
            $definitions->put($definition['fact_key'], $definition);
        }

        foreach ($definitions->sortBy(fn($definition) => (int)($definition['question_priority'] ?? 1000))->values() as $definition) {
            if (!$this->registry->applicabilityMatches(
                $lead,
                $definition['applicability'] ?? null,
                $caseValues,
                $statement
            )) {
                $notApplicable[] = $this->summary($definition, null);
                continue;
            }

            $value = $this->registry->contextValue(
                $lead,
                (string) $definition['fact_key'],
                $caseValues,
                $statement
            );

            if ($this->isKnown($value)) {
                $known[] = $this->summary($definition, $value);
                continue;
            }

            $missing[] = $this->summary($definition, null) + [
                'question' => $definition['question'] ?? null,
                'type' => $definition['data_type'] ?? 'text',
                'scope' => 'case',
                'dynamic_rule_id' => $definition['dynamic_rule_id'] ?? null,
            ];
        }

        $next = $missing[0] ?? null;

        return [
            'state' => $next ? 'needs_fact' : 'ready_for_reasoning',
            'ready' => $next === null,
            'known' => $known,
            'missing' => $missing,
            'not_applicable' => $notApplicable,
            'next_question' => $next ? [
                'fact_key' => $next['fact_key'],
                'type' => $next['type'],
                'question' => $next['question'],
                'label' => $next['label'],
                'scope' => 'case',
                'route' => 'case_readiness',
            ] : null,
            'message' => $next
                ? (string) $next['question']
                : 'The material case facts needed for the reasoning pass are complete.',
        ];
    }

    public function syncDerivedFacts(Lead $lead): void
    {
        $lead->loadMissing('debts.creditor');
        $this->postcodeJurisdiction->syncIfNeeded($lead, $this->facts);

        $factRows = $this->facts->leadFacts($lead);
        $values = collect($factRows)->mapWithKeys(fn($row, $key) => [$key => $row['value'] ?? null])->all();
        $statement = is_array($lead->financial_statement) ? $lead->financial_statement : [];

        $selfEmploymentSource = $factRows['case.self_employed']['source_type'] ?? null;
        if (!array_key_exists('case.self_employed', $values) || $selfEmploymentSource === 'derived') {
            $selfEmployedIncome = (float) data_get($statement, 'income.self_employed', 0);
            $employment = Str::lower(trim((string) $lead->employment_status));

            if ($selfEmployedIncome > 0 || str_contains($employment, 'self employ')) {
                $this->facts->setLeadFact(
                    $lead,
                    'case.self_employed',
                    true,
                    'derived',
                    $selfEmployedIncome > 0 ? 'Financial Statement self-employed income' : 'Lead employment status'
                );
            } elseif ($employment !== '') {
                $this->facts->setLeadFact(
                    $lead,
                    'case.self_employed',
                    false,
                    'derived',
                    'Lead employment status'
                );
            }
        }

        $hasHmrc = $this->registry->hasHmrcDebt($lead);
        if ($hasHmrc) {
            $total = (float) $lead->debts->sum('balance');
            $hmrc = (float) $lead->debts
                ->filter(fn($debt) => $this->isHmrcName((string) ($debt->creditor?->name ?? '')))
                ->sum('balance');

            if ($total > 0) {
                $this->facts->setLeadFact(
                    $lead,
                    'case.hmrc_majority',
                    $hmrc > ($total / 2),
                    'derived',
                    'Derived from current debt balances'
                );
            }

            $hmrcAttachment = $lead->debts
                ->filter(fn($debt) => $this->isHmrcName((string) ($debt->creditor?->name ?? '')))
                ->contains(function ($debt) {
                    $debtValues = $this->facts->debtValues($debt);
                    return ($debtValues['debt.attachment_of_earnings'] ?? false) === true
                        || ($debtValues['debt.attachment_of_benefit'] ?? false) === true;
                });

            if ($hmrcAttachment) {
                $this->facts->setLeadFact(
                    $lead,
                    'case.hmrc_deduction_from_income',
                    true,
                    'derived',
                    'Derived from HMRC debt attachment facts'
                );
            }
        }

        if (!array_key_exists('case.benefits_only', $values)) {
            $salary = (float) data_get($statement, 'income.client_salary', 0)
                + (float) data_get($statement, 'income.partner_salary', 0)
                + (float) data_get($statement, 'income.self_employed', 0);
            $benefits = collect([
                'income.universal_credit','income.child_benefit','income.pip_dla','income.esa',
                'income.carers_allowance','income.foster_guardianship',
            ])->sum(fn($key) => (float) data_get($statement, $key, 0));

            if ($salary > 0 || $benefits > 0) {
                $this->facts->setLeadFact(
                    $lead,
                    'case.benefits_only',
                    $salary <= 0 && $benefits > 0,
                    'derived',
                    'Derived from current Financial Statement income'
                );
            }
        }
    }

    private function summary(array $definition, mixed $value): array
    {
        return [
            'fact_key' => $definition['fact_key'],
            'label' => $definition['label'],
            'value' => $value,
            'source_hints' => $definition['source_hints'] ?? [],
            'priority' => (int) ($definition['question_priority'] ?? 1000),
        ];
    }

    private function isKnown(mixed $value): bool
    {
        if ($value === null) return false;
        if (is_string($value) && trim($value) === '') return false;
        return true;
    }

    private function isHmrcName(string $name): bool
    {
        $name = Str::lower($name);
        return str_contains($name, 'hmrc') || str_contains($name, 'hm revenue');
    }
}
