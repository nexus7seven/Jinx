<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class CaseAssessmentViewService
{
    public function __construct(
        private readonly DecisionFactRegistryService $registry,
        private readonly DecisionCaseFactService $facts,
        private readonly DecisionCaseReadinessService $readiness,
        private readonly IvaDecisionEngineService $engine,
        private readonly DecisionVotingService $voting,
    ) {}

    public function forLead(Lead $lead): array
    {
        $lead->loadMissing('debts.creditor');

        $readiness = $this->readiness->check($lead);
        $caseFacts = $this->facts->leadFacts($lead);
        $caseValues = collect($caseFacts)->mapWithKeys(fn($row, $key) => [$key => $row['value'] ?? null])->all();
        $statement = is_array($lead->financial_statement) ? $lead->financial_statement : [];

        $currentAssessment = $this->engine->assess($lead);
        $latestSaved = collect($this->engine->assessments($lead, 1))->first();
        $voting = $this->voting->analyse($lead, null, null);

        $caseRows = [];
        foreach ($this->registry->definitions('case', true) as $definition) {
            $applicable = $this->registry->applicabilityMatches(
                $lead,
                $definition['applicability'] ?? null,
                $caseValues,
                $statement
            );

            $value = $applicable
                ? $this->registry->contextValue($lead, (string) $definition['fact_key'], $caseValues, $statement)
                : null;

            $meta = $caseFacts[$definition['fact_key']] ?? null;
            $status = $this->factStatus($definition, $applicable, $value, $meta);

            $caseRows[] = [
                'fact_key' => $definition['fact_key'],
                'label' => $definition['label'],
                'group' => $this->groupFor((string) $definition['fact_key']),
                'data_type' => $definition['data_type'],
                'value' => $value,
                'display_value' => $this->displayValue($value, (string) $definition['data_type']),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'source' => $this->sourceLabel($definition, $meta),
                'source_detail' => $meta['source_detail'] ?? null,
                'recorded_at' => $meta['recorded_at'] ?? null,
                'reasoning_required' => (bool) ($definition['reasoning_required'] ?? false),
                'question' => $definition['question'] ?? null,
                'source_hints' => $definition['source_hints'] ?? [],
                'assessment' => $this->assessmentForCaseFact(
                    (string) $definition['fact_key'],
                    $status,
                    $value,
                    $currentAssessment,
                    $lead
                ),
                'editable' => ($definition['storage_type'] ?? null) === 'lead_decision_fact',
            ];
        }

        $debtRows = $lead->debts->map(function ($debt) use ($voting) {
            $facts = $this->facts->debtFacts($debt);
            $values = collect($facts)->mapWithKeys(fn($row, $key) => [$key => $row['value'] ?? null])->all();
            $votingDebt = collect($voting['debts'] ?? [])->firstWhere('debt_id', $debt->id);

            $rows = [];
            foreach ($this->registry->definitions('debt', true) as $definition) {
                $key = (string) $definition['fact_key'];
                $meta = $facts[$key] ?? null;
                $value = match ($definition['storage_type'] ?? null) {
                    'debt_column' => $this->debtColumnValue($debt, $definition['storage_key'] ?? null),
                    'derived' => $key === 'voting.representative'
                        ? ($votingDebt['voting_house'] ?? null)
                        : null,
                    default => $values[$key] ?? null,
                };

                $status = $this->known($value)
                    ? (($definition['storage_type'] ?? null) === 'derived' ? 'derived' : 'known')
                    : (($definition['reasoning_required'] ?? false) ? 'missing' : 'not_recorded');

                $rows[] = [
                    'fact_key' => $key,
                    'label' => $definition['label'],
                    'data_type' => $definition['data_type'],
                    'value' => $value,
                    'display_value' => $this->displayValue($value, (string) $definition['data_type']),
                    'status' => $status,
                    'status_label' => $this->statusLabel($status),
                    'source' => $this->debtSourceLabel($definition, $meta),
                    'source_detail' => $meta['source_detail'] ?? null,
                    'recorded_at' => $meta['recorded_at'] ?? null,
                    'assessment' => $this->assessmentForDebtFact($key, $status, $votingDebt),
                    'editable' => ($definition['storage_type'] ?? null) === 'debt_decision_fact',
                ];
            }

            return [
                'debt_id' => $debt->id,
                'creditor' => $debt->creditor?->name ?: 'Unknown creditor',
                'balance' => (float) $debt->balance,
                'balance_display' => '£'.number_format((float) $debt->balance, 2),
                'voting_house' => $votingDebt['voting_house'] ?? ($debt->creditor?->voting_house ?: 'Independent / ungrouped'),
                'voting_percent' => (float) ($votingDebt['percent_of_known_debt'] ?? 0),
                'voting_source' => $votingDebt['route_source'] ?? 'legacy_creditor_field',
                'voting_conflict' => (bool) ($votingDebt['route_conflict'] ?? false),
                'voting_conflict_reason' => $votingDebt['route_conflict_reason'] ?? null,
                'facts' => $rows,
            ];
        })->values()->all();

        $counts = collect($caseRows)->countBy('status');

        // Keep N/A facts in the internal count so the assessment remains auditable,
        // but do not render them in the working UI. As soon as a trigger changes
        // and the fact becomes applicable, a refreshed assessment automatically
        // includes it again.
        $grouped = collect($caseRows)
            ->reject(fn($row) => ($row['status'] ?? null) === 'not_applicable')
            ->groupBy('group')
            ->map(fn($rows) => $rows->values()->all())
            ->all();

        return [
            'lead_id' => $lead->id,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'readiness_state' => $readiness['state'] ?? 'unknown',
                'ready' => (bool) ($readiness['ready'] ?? false),
                'known' => (int) (($counts['known'] ?? 0) + ($counts['derived'] ?? 0)),
                'missing' => (int) ($counts['missing'] ?? 0),
                'not_applicable' => (int) ($counts['not_applicable'] ?? 0),
                'not_recorded' => (int) ($counts['not_recorded'] ?? 0),
                'next_question' => data_get($readiness, 'next_question.question'),
                'next_fact_key' => data_get($readiness, 'next_question.fact_key'),
                'latest_saved_assessment' => $latestSaved ? [
                    'preferred_route' => $latestSaved['preferred_route'] ?? null,
                    'status' => $latestSaved['status'] ?? null,
                    'rationale' => $latestSaved['rationale'] ?? null,
                    'actions' => $latestSaved['actions'] ?? null,
                    'assessed_at' => $latestSaved['assessed_at'] ?? null,
                ] : null,
            ],
            'groups' => [
                ['key' => 'case_household', 'label' => 'Case & household', 'facts' => $grouped['case_household'] ?? []],
                ['key' => 'income_affordability', 'label' => 'Income & affordability', 'facts' => $grouped['income_affordability'] ?? []],
                ['key' => 'property_hmrc_conduct', 'label' => 'Property, HMRC & conduct', 'facts' => $grouped['property_hmrc_conduct'] ?? []],
                ['key' => 'evidence_readiness', 'label' => 'Evidence & referral readiness', 'facts' => $grouped['evidence_readiness'] ?? []],
            ],
            'debts' => $debtRows,
            'routes' => collect($currentAssessment['route_overview'] ?? [])->map(fn($route) => $this->routeView($route))->values()->all(),
            'dmp_fallback' => $this->dmpView($currentAssessment['dmp_fallback'] ?? null),
        ];
    }

    private function routeView(array $route): array
    {
        $findings = [];

        foreach ((array) data_get($route, 'basic_requirements.checks', []) as $check) {
            $findings[] = [
                'status' => $check['status'] ?? 'UNKNOWN',
                'message' => $this->basicCheckMessage($check),
                'source' => $this->sourceFromRule($check['source'] ?? null),
            ];
        }

        foreach ((array) data_get($route, 'special_circumstances.findings', []) as $finding) {
            $findings[] = [
                'status' => $finding['status'] ?? 'UNKNOWN',
                'message' => $finding['message'] ?? $finding['reason'] ?? 'Special circumstance check',
                'source' => $this->sourceFromRule($finding['source'] ?? null),
            ];
        }

        foreach ((array) data_get($route, 'dynamic_case_checks.findings', []) as $finding) {
            $findings[] = [
                'status' => $finding['status'] ?? 'UNKNOWN',
                'message' => $finding['reason'] ?? $finding['title'] ?? 'Operator rule check',
                'source' => trim(implode(' · ', array_filter([
                    'Operator rule',
                    $finding['source_detail'] ?? null,
                ]))),
            ];
        }

        foreach ((array) data_get($route, 'evidence_feasibility.items', []) as $finding) {
            $findings[] = [
                'status' => $finding['status'] ?? 'UNKNOWN',
                'message' => $finding['message'] ?? 'Evidence action',
                'source' => $this->sourceFromRule($finding['source'] ?? null),
            ];
        }

        $property = $route['property'] ?? [];
        if (($property['status'] ?? null) === 'CALCULATED') {
            $calc = $property['calculation'] ?? [];
            $findings[] = [
                'status' => 'SATISFIED',
                'message' => 'Property equity calculated: gross £'.number_format((float) ($calc['gross_equity'] ?? 0), 2)
                    .'; client attributable £'.number_format((float) ($calc['client_attributable_equity'] ?? 0), 2).'.',
                'source' => 'Derived by Jinx',
            ];
        } elseif (($property['status'] ?? null) === 'UNKNOWN') {
            $findings[] = [
                'status' => 'UNKNOWN',
                'message' => 'Property assessment is waiting for: '.implode(', ', (array) ($property['missing_facts'] ?? [])).'.',
                'source' => 'Case facts',
            ];
        }

        if ((int) ($route['unresolved_representative_count'] ?? 0) > 0) {
            $findings[] = [
                'status' => 'UNKNOWN',
                'message' => (int) $route['unresolved_representative_count'].' debt(s) still have an unresolved voting representative.',
                'source' => 'Creditor / voting intelligence',
            ];
        }

        return [
            'destination' => $route['destination'] ?? null,
            'destination_key' => $route['destination_key'] ?? null,
            'priority' => $route['priority'] ?? null,
            'status' => $route['deterministic_status'] ?? 'UNKNOWN',
            'findings' => collect($findings)
                ->filter(fn($finding) => ($finding['status'] ?? null) !== 'SATISFIED')
                ->take(8)
                ->values()
                ->all(),
            'passed_checks' => collect($findings)->where('status', 'SATISFIED')->count(),
            'unresolved_voting_percent' => (float) ($route['unresolved_voting_percent'] ?? 0),
        ];
    }

    private function dmpView(mixed $dmp): ?array
    {
        if (!is_array($dmp)) return null;

        return [
            'status' => $dmp['status'] ?? null,
            'message' => $dmp['message'] ?? $dmp['reason'] ?? null,
        ];
    }

    private function factStatus(array $definition, bool $applicable, mixed $value, ?array $meta): string
    {
        if (!$applicable) return 'not_applicable';
        if ($this->known($value)) {
            if (($meta['source_type'] ?? null) === 'derived' || ($definition['storage_type'] ?? null) === 'derived') {
                return 'derived';
            }
            return 'known';
        }
        return ($definition['reasoning_required'] ?? false) ? 'missing' : 'not_recorded';
    }

    private function groupFor(string $key): string
    {
        if (Str::startsWith($key, 'evidence.')) return 'evidence_readiness';
        if (
            Str::startsWith($key, ['income.', 'household.', 'calculation.', 'client.'])
            || in_array($key, ['case.self_employed'], true)
        ) return 'income_affordability';
        if (
            Str::startsWith($key, ['property.', 'partner.'])
            || Str::startsWith($key, 'case.hmrc_')
            || in_array($key, [
                'case.gambling_monthly',
                'case.gamstop_registered',
                'case.joint_iva',
                'case.self_employed_returns_due',
                'case.tax_returns_up_to_date',
            ], true)
        ) return 'property_hmrc_conduct';

        return 'case_household';
    }

    private function assessmentForCaseFact(string $key, string $status, mixed $value, array $assessment, Lead $lead): string
    {
        if ($status === 'not_applicable') return 'Not applicable to this case under the current trigger conditions.';
        if ($status === 'missing') return 'Material fact still required before the full case-reasoning pass can complete.';
        if ($status === 'not_recorded') return 'Not currently recorded. It is not required by the present readiness path.';

        if ($key === 'property.is_homeowner' && $value === false) {
            return 'No property interest is recorded, so the property/equity branch is excluded.';
        }

        if (Str::startsWith($key, 'property.')) {
            $property = collect($assessment['route_overview'] ?? [])->pluck('property')->first(fn($x) => is_array($x) && ($x['status'] ?? null) === 'CALCULATED');
            if (is_array($property)) {
                return 'Used in the equity calculation. Current client-attributable equity is £'
                    .number_format((float) data_get($property, 'calculation.client_attributable_equity', 0), 2).'.';
            }
            return 'Available to the property/equity branch of route assessment.';
        }

        if ($key === 'case.self_employed' && $value === false) {
            return 'Self-employed tax-return checks are excluded for this case.';
        }

        if (Str::startsWith($key, 'case.hmrc_') || $key === 'case.joint_iva') {
            return $this->hasHmrcDebt($lead)
                ? 'HMRC is present, so this fact is included in the HMRC-specific route checks.'
                : 'No HMRC debt is currently recorded; this fact has no active HMRC route effect.';
        }

        if (in_array($key, ['case.gambling_monthly', 'case.gamstop_registered'], true)) {
            return 'Used by the applicable gambling/GAMSTOP checks in the route engine.';
        }

        if (in_array($key, ['case.previous_iva', 'case.previous_iva_failed', 'case.previous_bankruptcy'], true)) {
            return 'Used in previous-insolvency checks and any route-specific HMRC history checks.';
        }

        if (in_array($key, ['case.self_employed_returns_due', 'case.tax_returns_up_to_date'], true)) {
            return 'Used in the self-employed tax-return branch when self-employment is applicable.';
        }

        if (Str::startsWith($key, 'partner.')) {
            return 'Used in partner-evidence treatment for the routes where partner income is material.';
        }

        if (Str::startsWith($key, 'evidence.')) {
            return 'Used as referral-readiness evidence where the applicable route asks for it.';
        }

        if (Str::startsWith($key, ['income.', 'calculation.'])) {
            return 'Used in affordability and route minimum-threshold checks.';
        }

        if (Str::startsWith($key, 'household.')) {
            return 'Used to build the household profile and route-specific Financial Statement.';
        }

        return $status === 'derived'
            ? 'Derived from existing case data and available to the decision engine.'
            : 'Recorded and available to the case-reasoning engine.';
    }

    private function assessmentForDebtFact(string $key, string $status, ?array $votingDebt): string
    {
        if ($status === 'missing') return 'Material debt fact is still required for an applicable case check.';
        if ($status === 'not_recorded') return 'Not currently recorded; no active rule requires it at this point.';

        return match ($key) {
            'debt.creditor', 'debt.balance' => 'Forms part of the debt total and creditor/voting exposure.',
            'voting.representative' => ($votingDebt['route_conflict'] ?? false)
                ? 'Voting representative is unresolved because the creditor mapping has a conflict.'
                : 'Voting representative resolved from creditor/account intelligence.',
            'debt.product_type', 'debt.account_reference' => 'Used where product/reference details are needed to resolve creditor or voting-house treatment.',
            'debt.is_joint' => 'Records whether liability is joint and is available to debt-specific checks.',
            'debt.last_spend_date' => 'Raw recent-use date is available to any applicable creditor recent-spend window checks.',
            'debt.attachment_of_earnings', 'debt.attachment_of_benefit' => 'Used where deductions/attachments affect HMRC or debt treatment.',
            'debt.keep_vehicle' => 'Used where retained HP/vehicle finance affects route treatment.',
            default => 'Recorded and available to debt-level reasoning.',
        };
    }

    private function sourceLabel(array $definition, ?array $meta): string
    {
        if ($meta) return $this->normaliseSource((string) ($meta['source_type'] ?? 'operator'));
        return match ($definition['storage_type'] ?? null) {
            'financial_statement' => 'I&E',
            'lead_field' => 'CRM',
            'derived' => 'Derived by Jinx',
            default => $this->normaliseSource((string) (($definition['source_hints'][0] ?? '') ?: 'Not recorded')),
        };
    }

    private function debtSourceLabel(array $definition, ?array $meta): string
    {
        if ($meta) return $this->normaliseSource((string) ($meta['source_type'] ?? 'operator'));
        return match ($definition['storage_type'] ?? null) {
            'debt_column' => 'Debt record',
            'derived' => 'Derived by Jinx',
            default => $this->normaliseSource((string) (($definition['source_hints'][0] ?? '') ?: 'Not recorded')),
        };
    }

    private function normaliseSource(string $source): string
    {
        return match (Str::lower(trim($source))) {
            'operator', 'manual' => 'Packager',
            'derived' => 'Derived by Jinx',
            'ie' => 'I&E',
            'crm' => 'CRM',
            'credit_report' => 'Credit report',
            'documents', 'document' => 'Document',
            'creditor_intelligence' => 'Creditor intelligence',
            'property_data_future' => 'Property data',
            'future_document_import', 'documents_future' => 'Document importer',
            '' => 'Not recorded',
            default => Str::headline(str_replace('_', ' ', $source)),
        };
    }

    private function debtColumnValue($debt, ?string $storageKey): mixed
    {
        return match ($storageKey) {
            'creditor_id' => $debt->creditor?->name,
            'balance' => (float) $debt->balance,
            default => $storageKey ? $debt->{$storageKey} : null,
        };
    }

    private function displayValue(mixed $value, string $type): string
    {
        if ($value === null || $value === '') return '—';
        if (is_bool($value)) return $value ? 'Yes' : 'No';
        if ($type === 'money' || $type === 'money_or_none') return '£'.number_format((float) $value, 2);
        if ($type === 'percentage') return number_format((float) $value, 2).'%';
        if (is_array($value)) return implode(', ', array_map(fn($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        return (string) $value;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'known' => 'Known',
            'derived' => 'Derived',
            'missing' => 'Missing',
            'not_applicable' => 'N/A',
            'not_recorded' => 'Not collected',
            default => Str::headline($status),
        };
    }

    private function known(mixed $value): bool
    {
        if ($value === null) return false;
        return !(is_string($value) && trim($value) === '');
    }

    private function hasHmrcDebt(Lead $lead): bool
    {
        return $lead->debts->contains(function ($debt) {
            $name = Str::lower((string) ($debt->creditor?->name ?? ''));
            return str_contains($name, 'hmrc') || str_contains($name, 'hm revenue');
        });
    }

    private function basicCheckMessage(array $check): string
    {
        $label = Str::headline((string) ($check['requirement'] ?? 'requirement'));
        $required = $check['required'] ?? null;
        $actual = $check['actual'] ?? null;

        $format = function ($value) use ($check) {
            if ($value === null) return 'unknown';
            if (in_array($check['requirement'] ?? null, ['minimum_debt','minimum_di','minimum_income','minimum_term_repayment'], true)) {
                return '£'.number_format((float) $value, 2);
            }
            return (string) $value;
        };

        return $label.': '.$format($actual).' recorded; '.$format($required).' required.';
    }

    private function sourceFromRule(mixed $source): ?string
    {
        if (!is_array($source)) return null;
        return trim(implode(' · ', array_filter([
            $source['source_name'] ?? null,
            $source['sheet'] ?? $source['source_sheet'] ?? null,
            $source['location'] ?? $source['source_location'] ?? null,
        ]))) ?: null;
    }
}
