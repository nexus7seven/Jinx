<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class IvaCasePackagingPlannerService
{
    public function __construct(
        private readonly IvaDecisionEngineService $engine,
        private readonly DecisionCaseFactService $facts,
    ) {}

    public function plan(Lead $lead): array
    {
        $this->syncKnownDecisionFacts($lead);
        $assessment = $this->engine->assess($lead);
        $values = $this->facts->leadValues($lead);
        $debtTotal = (float) data_get($assessment, 'case.known_debt_total', 0);

        if ($debtTotal <= 0) {
            return [
                'state' => 'needs_debts',
                'question' => null,
                'assessment' => $assessment,
                'message' => 'The I&E is ready, but there are no debts recorded yet. Add or import the debts before I make the IVA routing decision.',
            ];
        }

        $question = $this->propertyQuestion($values);
        if ($question) return $this->questionPlan($assessment, $question);

        $question = $this->votingConflictQuestion($assessment, $lead);
        if ($question) return $this->questionPlan($assessment, $question);

        $question = $this->specialCircumstanceQuestion($assessment, $values);
        if ($question) return $this->questionPlan($assessment, $question);

        $question = $this->partnerEvidenceQuestion($assessment, $values);
        if ($question) return $this->questionPlan($assessment, $question);

        $question = $this->immigrationQuestion($assessment, $values);
        if ($question) return $this->questionPlan($assessment, $question);

        return [
            'state' => 'ready_for_agent',
            'question' => null,
            'assessment' => $assessment,
            'message' => null,
        ];
    }

    public function parseAnswer(array $question, string $message): array
    {
        $type = (string) ($question['type'] ?? '');
        $key = (string) ($question['fact_key'] ?? '');
        $text = trim($message);

        if ($key === '' || $text === '') return ['valid' => false, 'value' => null];

        if ($type === 'yes_no') {
            $lower = Str::lower($text);
            if (preg_match('/\b(?:no|n|nope|false|none|not|doesn[’\']?t|isn[’\']?t|hasn[’\']?t|haven[’\']?t)\b/', $lower)) return ['valid' => true, 'value' => false];
            if (preg_match('/\b(?:yes|y|yeah|yep|true)\b/', $lower)) return ['valid' => true, 'value' => true];
            if (preg_match('/\b(?:it\s+is|they\s+are|client\s+is|does\s+have|has\s+(?:a|an|one|some)|have\s+(?:a|an|one|some))\b/', $lower)) return ['valid' => true, 'value' => true];
            return ['valid' => false, 'value' => null];
        }

        if (in_array($type, ['money','integer','percentage'], true)) {
            $normalised = str_replace([',','£','%'], '', $text);
            if (!preg_match('/-?\d+(?:\.\d+)?/', $normalised, $m)) return ['valid' => false, 'value' => null];
            $number = (float) $m[0];
            if ($number < 0) return ['valid' => false, 'value' => null];
            if ($type === 'integer') $number = (int) round($number);
            if ($type === 'percentage' && $number > 100) return ['valid' => false, 'value' => null];
            return ['valid' => true, 'value' => $number];
        }

        if ($type === 'text') return ['valid' => true, 'value' => Str::limit($text, 1000, '')];

        return ['valid' => false, 'value' => null];
    }

    public function invalidAnswerMessage(array $question): string
    {
        $type = (string) ($question['type'] ?? '');
        return match ($type) {
            'yes_no' => 'Please answer yes or no. '.$question['question'],
            'money' => 'Please give the monthly/financial amount as a number. '.$question['question'],
            'integer' => 'Please give the number. '.$question['question'],
            'percentage' => 'Please give the client’s ownership percentage from 0 to 100. '.$question['question'],
            default => 'I could not use that answer. '.$question['question'],
        };
    }

    private function syncKnownDecisionFacts(Lead $lead): void
    {
        $values = $this->facts->leadValues($lead);
        if (array_key_exists('case.self_employed', $values)) return;

        $statement = $lead->financial_statement;
        $selfEmployedIncome = is_array($statement) ? (float) data_get($statement, 'income.self_employed', 0) : 0.0;
        $employment = Str::lower(trim((string) $lead->employment_status));

        if ($selfEmployedIncome > 0 || str_contains($employment, 'self employ')) {
            $this->facts->setLeadFact(
                $lead,
                'case.self_employed',
                true,
                'crm',
                $selfEmployedIncome > 0 ? 'Financial Statement self-employed income' : 'Lead employment status'
            );
        }
    }

    private function propertyQuestion(array $values): ?array
    {
        if (!array_key_exists('property.is_homeowner', $values)) {
            return $this->question(
                'property.is_homeowner',
                'yes_no',
                'Is the client a homeowner or do they have a legal/beneficial ownership interest in a property?',
                'property'
            );
        }

        if ($this->bool($values['property.is_homeowner']) !== true) return null;

        $questions = [
            'property.value' => ['money', 'What is the current property value?'],
            'property.mortgage_balance' => ['money', 'What is the current mortgage balance? Enter 0 if the property is owned outright.'],
            'property.secured_loans_total' => ['money', 'What is the total of any secured loans against the property, excluding the mortgage? Enter 0 if none.'],
            'property.ownership_percent' => ['percentage', 'What percentage of the property does the client legally/beneficially own?'],
        ];

        foreach ($questions as $key => [$type, $question]) {
            if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
                return $this->question($key, $type, $question, 'property');
            }
        }

        return null;
    }

    private function votingConflictQuestion(array $assessment, Lead $lead): ?array
    {
        $lead->loadMissing('debts.creditor');

        foreach ($this->seriousRoutes($assessment) as $route) {
            if ((int) ($route['unresolved_representative_count'] ?? 0) <= 0) continue;

            foreach (($route['unresolved_voting_debts'] ?? []) as $debt) {
                $debtId = (int) ($debt['debt_id'] ?? 0);
                if ($debtId <= 0) continue;

                $debtModel = $lead->debts->firstWhere('id', $debtId);
                if (!$debtModel) continue;

                $debtFacts = $this->facts->debtValues($debtModel);
                $creditor = trim((string) ($debt['creditor'] ?? $debtModel->creditor?->name ?? 'this creditor'));

                if (!array_key_exists('debt.product_type', $debtFacts)) {
                    return $this->question(
                        'debt.product_type',
                        'text',
                        'What type of debt/account is the '.$creditor.' debt (for example credit card, personal loan, overdraft or catalogue)?',
                        (string) ($route['destination'] ?? ''),
                        'debt',
                        $debtId
                    );
                }

                $needsReference = collect($debt['route_candidates'] ?? [])->contains(function($candidate) {
                    if (($candidate['debt_fact_missing_required_fact'] ?? false) !== true) return false;
                    return collect($candidate['debt_fact_match_reasons'] ?? [])->contains(
                        fn($reason)=>str_contains(Str::lower((string)$reason),'reference')
                    );
                });

                $knownReference = trim((string) ($debtFacts['debt.account_reference'] ?? $debtModel->reference ?? ''));
                if ($needsReference && $knownReference === '') {
                    return $this->question(
                        'debt.account_reference',
                        'text',
                        'What is the account/reference number for the '.$creditor.' debt? It is needed to resolve which voting representative applies. If it is not available, reply unknown.',
                        (string) ($route['destination'] ?? ''),
                        'debt',
                        $debtId
                    );
                }
            }
        }

        return null;
    }

    private function specialCircumstanceQuestion(array $assessment, array $values): ?array
    {
        $map = [
            'self_employed_trading_months' => ['case.self_employed_trading_months', 'integer', 'How many months has the client been trading as self-employed?'],
            'self_employed_profitability' => ['case.self_employed_profitable', 'yes_no', 'Is the self-employed business currently making a profit?'],
            'self_employed_employees' => ['case.self_employed_has_employees', 'yes_no', 'Does the self-employed business have any employees?'],
            'self_employed_partnership' => ['case.self_employed_partnership', 'yes_no', 'Is the self-employed business a partnership?'],
            'tax_returns' => ['case.tax_returns_up_to_date', 'yes_no', 'Are the client’s required tax returns up to date?'],
        ];

        foreach ($this->seriousRoutes($assessment) as $route) {
            foreach (($route['special_circumstances']['findings'] ?? []) as $finding) {
                if (($finding['status'] ?? null) !== 'UNKNOWN') continue;
                $topic = $finding['topic'] ?? null;
                if (!$topic || !isset($map[$topic])) continue;
                [$key, $type, $question] = $map[$topic];
                if (array_key_exists($key, $values)) continue;
                return $this->question($key, $type, $question, (string) ($route['destination'] ?? ''));
            }
        }

        return null;
    }

    private function partnerEvidenceQuestion(array $assessment, array $values): ?array
    {
        $partnerIncome = (float) data_get($assessment, 'case.ie.income.partner_salary', 0);
        if ($partnerIncome <= 0) return null;

        $zebra = collect($assessment['route_overview'] ?? [])->firstWhere('destination_key', 'zebra');
        if (is_array($zebra) && ($zebra['basic_requirements']['status'] ?? null) !== 'BLOCKED') {
            if (!array_key_exists('partner.income_evidence_available', $values)) {
                return $this->question(
                    'partner.income_evidence_available',
                    'yes_no',
                    'Is normal evidence of the partner’s income available (for example wage slips/bank evidence)?',
                    'Zebra'
                );
            }
        }

        if (($values['partner.income_evidence_available'] ?? null) === false) {
            $lawson = collect($assessment['route_overview'] ?? [])->firstWhere('destination_key', 'lawson_fox');
            if (is_array($lawson) && ($lawson['basic_requirements']['status'] ?? null) !== 'BLOCKED'
                && !array_key_exists('partner.declaration_available', $values)) {
                return $this->question(
                    'partner.declaration_available',
                    'yes_no',
                    'If normal partner income evidence is unavailable, can the partner provide the signed declaration used for the Lawson Fox route?',
                    'Lawson Fox'
                );
            }
        }

        return null;
    }

    private function immigrationQuestion(array $assessment, array $values): ?array
    {
        $status = trim((string) ($values['case.immigration_status'] ?? ''));
        if ($status === '' || in_array(Str::lower($status), ['uk citizen','british','british citizen'], true)) return null;

        $lawson = collect($assessment['route_overview'] ?? [])->firstWhere('destination_key', 'lawson_fox');
        if (!is_array($lawson) || ($lawson['basic_requirements']['status'] ?? null) === 'BLOCKED') return null;

        if (!array_key_exists('case.uk_driving_licence', $values)) {
            return $this->question(
                'case.uk_driving_licence',
                'yes_no',
                'Does the client have a UK driving licence for the Lawson Fox immigration-evidence route?',
                'Lawson Fox'
            );
        }

        return null;
    }

    private function seriousRoutes(array $assessment): array
    {
        return collect($assessment['route_overview'] ?? [])
            ->filter(function ($route) {
                if (($route['basic_requirements']['status'] ?? null) === 'BLOCKED') return false;
                if (($route['special_circumstances']['status'] ?? null) === 'BLOCKED') return false;
                return true;
            })
            ->values()
            ->all();
    }

    private function questionPlan(array $assessment, array $question): array
    {
        return [
            'state' => 'needs_fact',
            'question' => $question,
            'assessment' => $assessment,
            'message' => $question['question'],
        ];
    }

    private function question(string $key, string $type, string $question, string $route, string $scope = 'case', ?int $debtId = null): array
    {
        $out = [
            'fact_key' => $key,
            'type' => $type,
            'question' => $question,
            'route' => $route,
            'scope' => $scope,
        ];
        if ($debtId !== null) $out['debt_id'] = $debtId;
        return $out;
    }

    private function bool(mixed $value): ?bool
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
