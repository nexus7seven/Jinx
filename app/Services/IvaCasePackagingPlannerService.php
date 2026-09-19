<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Support\Str;

class IvaCasePackagingPlannerService
{
    public function __construct(
        private readonly IvaDecisionEngineService $engine,
        private readonly DecisionCaseFactService $facts,
        private readonly DecisionCaseReadinessService $readiness,
        private readonly DecisionDynamicRuleService $dynamicRules,
    ) {}

    public function plan(Lead $lead): array
    {
        $readiness = $this->readiness->check($lead);

        if (($readiness['state'] ?? null) === 'needs_debts') {
            return [
                'state'=>'needs_debts',
                'question'=>null,
                'assessment'=>null,
                'readiness'=>$readiness,
                'message'=>$readiness['message'],
            ];
        }

        if (($readiness['state'] ?? null) === 'needs_fact' && is_array($readiness['next_question'] ?? null)) {
            return [
                'state'=>'needs_fact',
                'question'=>$readiness['next_question'],
                'assessment'=>null,
                'readiness'=>$readiness,
                'message'=>$readiness['message'],
            ];
        }

        $assessment = $this->engine->assess($lead);

        $question = $this->votingConflictQuestion($assessment, $lead);
        if ($question) return $this->questionPlan($assessment, $question, $readiness);

        $question = $this->dynamicDebtQuestion($lead);
        if ($question) return $this->questionPlan($assessment, $question, $readiness);

        return [
            'state'=>'ready_for_agent',
            'question'=>null,
            'assessment'=>$assessment,
            'readiness'=>$readiness,
            'message'=>null,
        ];
    }

    public function parseAnswer(array $question, string $message): array
    {
        $type = (string) ($question['type'] ?? '');
        $key = (string) ($question['fact_key'] ?? '');
        $text = trim($message);

        if ($key === '' || $text === '') return ['valid'=>false,'value'=>null];

        if ($type === 'yes_no' || $type === 'boolean') {
            $lower = Str::lower($text);
            if (preg_match('/\b(?:no|n|nope|false|none|not|doesn[’\']?t|isn[’\']?t|hasn[’\']?t|haven[’\']?t|never)\b/', $lower)) {
                return ['valid'=>true,'value'=>false];
            }
            if (preg_match('/\b(?:yes|y|yeah|yep|true)\b/', $lower)) return ['valid'=>true,'value'=>true];
            if (preg_match('/\b(?:it\s+is|they\s+are|client\s+is|does\s+have|has\s+(?:a|an|one|some)|have\s+(?:a|an|one|some))\b/', $lower)) {
                return ['valid'=>true,'value'=>true];
            }
            return ['valid'=>false,'value'=>null];
        }

        if ($type === 'money_or_none') {
            $lower = Str::lower($text);
            if (preg_match('/\b(?:no|none|nil|zero|0|doesn[’\']?t|never)\b/', $lower)) {
                return ['valid'=>true,'value'=>0.0];
            }
            return $this->numberAnswer($text, 'money');
        }

        if (in_array($type, ['money','integer','percentage'], true)) {
            return $this->numberAnswer($text, $type);
        }

        if ($type === 'date') {
            $lower = Str::lower($text);
            if (in_array($lower, ['unknown','not sure','unsure','dont know',"don't know",'n/a'], true)) {
                return ['valid'=>true,'value'=>'unknown'];
            }
            try {
                $date = Carbon::parse($text, 'Europe/London');
                return ['valid'=>true,'value'=>$date->toDateString()];
            } catch (\Throwable) {
                return ['valid'=>false,'value'=>null];
            }
        }

        if ($type === 'text' || $type === 'legacy') {
            return ['valid'=>true,'value'=>Str::limit($text,1000,'')];
        }

        return ['valid'=>false,'value'=>null];
    }

    public function invalidAnswerMessage(array $question): string
    {
        $type = (string) ($question['type'] ?? '');
        return match ($type) {
            'yes_no','boolean'=>'Please answer yes or no. '.$question['question'],
            'money','money_or_none'=>'Please give the amount as a number, or say none where appropriate. '.$question['question'],
            'integer'=>'Please give the number. '.$question['question'],
            'percentage'=>'Please give a percentage from 0 to 100. '.$question['question'],
            'date'=>'Please give the date, or say unknown if it genuinely is not available. '.$question['question'],
            default=>'I could not use that answer. '.$question['question'],
        };
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

                $needsReference = collect($debt['route_candidates'] ?? [])->contains(function ($candidate) {
                    if (($candidate['debt_fact_missing_required_fact'] ?? false) !== true) return false;
                    return collect($candidate['debt_fact_match_reasons'] ?? [])->contains(
                        fn($reason) => str_contains(Str::lower((string)$reason), 'reference')
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

    private function dynamicDebtQuestion(Lead $lead): ?array
    {
        $definitions = collect($this->dynamicRules->requiredFacts($lead))
            ->filter(fn($definition) => ($definition['scope'] ?? null) === 'debt')
            ->sortBy(fn($definition) => (int)($definition['question_priority'] ?? 1000));

        if ($definitions->isEmpty()) return null;

        $lead->loadMissing('debts.creditor');

        foreach ($definitions as $definition) {
            foreach ($lead->debts as $debt) {
                $values = $this->facts->debtValues($debt);
                $key = (string) $definition['fact_key'];
                if (array_key_exists($key, $values) && $values[$key] !== null && $values[$key] !== '') continue;

                $creditor = $debt->creditor?->name ?: 'this creditor';
                $question = trim((string) ($definition['question'] ?? ''));
                if ($question === '') $question = 'What is the '.$definition['label'].' for '.$creditor.'?';
                else $question .= ' This is for '.$creditor.'.';

                return $this->question(
                    $key,
                    (string) ($definition['data_type'] ?? 'text'),
                    $question,
                    (string) ($definition['dynamic_destination'] ?? 'learned_rule'),
                    'debt',
                    $debt->id
                );
            }
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

    private function questionPlan(array $assessment, array $question, array $readiness): array
    {
        return [
            'state'=>'needs_fact',
            'question'=>$question,
            'assessment'=>$assessment,
            'readiness'=>$readiness,
            'message'=>$question['question'],
        ];
    }

    private function question(string $key, string $type, string $question, string $route, string $scope = 'case', ?int $debtId = null): array
    {
        $out = [
            'fact_key'=>$key,
            'type'=>$type,
            'question'=>$question,
            'route'=>$route,
            'scope'=>$scope,
        ];
        if ($debtId !== null) $out['debt_id'] = $debtId;
        return $out;
    }

    private function numberAnswer(string $text, string $type): array
    {
        $normalised = str_replace([',','£','%'], '', $text);
        if (!preg_match('/-?\d+(?:\.\d+)?/', $normalised, $m)) return ['valid'=>false,'value'=>null];

        $number = (float) $m[0];
        if ($number < 0) return ['valid'=>false,'value'=>null];
        if ($type === 'integer') $number = (int) round($number);
        if ($type === 'percentage' && $number > 100) return ['valid'=>false,'value'=>null];

        return ['valid'=>true,'value'=>$number];
    }
}
