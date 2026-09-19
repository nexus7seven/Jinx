<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class DecisionDynamicRuleService
{
    public function __construct(
        private readonly DecisionFactRegistryService $registry,
        private readonly DecisionCaseFactService $facts,
    ) {}

    public function evaluate(Lead $lead, string $destinationKey): array
    {
        if (!Schema::hasTable('decision_dynamic_rules')) {
            return ['status'=>'NOT_APPLICABLE','findings'=>[]];
        }

        $lead->loadMissing('debts.creditor');
        $caseValues = $this->facts->leadValues($lead);
        $statement = is_array($lead->financial_statement) ? $lead->financial_statement : [];

        $rules = DB::table('decision_dynamic_rules')
            ->where('is_active', true)
            ->where(function ($q) use ($destinationKey) {
                $q->whereNull('destination_key')->orWhere('destination_key', $destinationKey);
            })
            ->orderBy('id')
            ->get();

        $findings = [];

        foreach ($rules as $rule) {
            $applicability = $this->decode($rule->applicability);
            if (!$this->registry->applicabilityMatches($lead, $applicability, $caseValues, $statement)) continue;

            $definition = $this->registry->definition((string) $rule->fact_key);
            if (!$definition || ($definition['is_active'] ?? false) !== true) {
                $findings[] = [
                    'rule_id'=>(int)$rule->id,
                    'title'=>$rule->title,
                    'fact_key'=>$rule->fact_key,
                    'status'=>'UNKNOWN',
                    'reason'=>'The rule references an unavailable reasoning fact definition.',
                    'source_type'=>$rule->source_type,
                    'source_detail'=>$rule->source_detail,
                ];
                continue;
            }

            if (($definition['scope'] ?? 'case') === 'debt') {
                $debtFindings = $this->evaluateDebtRule($lead, (array) $rule, $definition);
                array_push($findings, ...$debtFindings);
                continue;
            }

            $actual = $this->registry->contextValue(
                $lead,
                (string) $rule->fact_key,
                $caseValues,
                $statement
            );

            if ($actual === null || (is_string($actual) && trim($actual) === '')) {
                $findings[] = $this->finding((array) $rule, 'UNKNOWN', $actual, $definition);
                continue;
            }

            $expected = $this->decode($rule->expected_value_json);
            $matched = $this->registry->compare($actual, (string) $rule->operator, $expected);
            $findings[] = $this->finding(
                (array) $rule,
                $matched ? (string) $rule->result_status : 'SATISFIED',
                $actual,
                $definition
            );
        }

        return [
            'status'=>$this->worstStatus($findings),
            'findings'=>$findings,
        ];
    }

    public function requiredFacts(Lead $lead): array
    {
        if (!Schema::hasTable('decision_dynamic_rules')) return [];

        $caseValues = $this->facts->leadValues($lead);
        $statement = is_array($lead->financial_statement) ? $lead->financial_statement : [];

        return DB::table('decision_dynamic_rules')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(function ($rule) use ($lead, $caseValues, $statement) {
                return $this->registry->applicabilityMatches(
                    $lead,
                    $this->decode($rule->applicability),
                    $caseValues,
                    $statement
                );
            })
            ->map(function ($rule) {
                $definition = $this->registry->definition((string) $rule->fact_key);
                if (!$definition || ($definition['is_active'] ?? false) !== true) return null;
                return $definition + [
                    'dynamic_rule_id'=>(int)$rule->id,
                    'dynamic_rule_title'=>$rule->title,
                    'dynamic_destination'=>$rule->destination_key,
                ];
            })
            ->filter()
            ->unique('fact_key')
            ->values()
            ->all();
    }

    public function teachRule(array $data): array
    {
        if (!Schema::hasTable('decision_dynamic_rules')) throw new RuntimeException('Dynamic rule registry is not available.');
        if (($data['confirmed_by_operator'] ?? false) !== true) {
            throw new RuntimeException('A new reasoning rule requires explicit operator confirmation.');
        }

        $destination = $this->destinationKey($data['destination'] ?? null);
        $factKey = trim((string) ($data['fact_key'] ?? ''));
        $definition = $this->registry->definition($factKey);

        if (!$definition) {
            $applicability = $this->buildApplicability($data);
            $this->registry->define([
                'fact_key'=>$factKey,
                'scope'=>$data['scope'] ?? 'case',
                'label'=>$data['fact_label'] ?? $factKey,
                'data_type'=>$data['data_type'] ?? 'text',
                'question'=>$data['question'] ?? null,
                'source_hints'=>['operator','future_document_import'],
                'applicability'=>$applicability,
                'reasoning_required'=>true,
                'question_priority'=>$data['question_priority'] ?? 900,
                'source_detail'=>$data['source_detail'] ?? null,
            ]);
            $definition = $this->registry->definition($factKey);
        }

        $operator = strtolower(trim((string) ($data['operator'] ?? 'equals')));
        if (!in_array($operator,['equals','not_equals','gt','gte','lt','lte','truthy','falsy','exists'],true)) {
            throw new RuntimeException('Unsupported dynamic rule operator.');
        }

        $status = strtoupper(trim((string) ($data['result_status'] ?? 'UNKNOWN')));
        if (!in_array($status,['BLOCKED','FIT_WITH_ACTIONS','EXCEPTION_ESCALATION','UNKNOWN'],true)) {
            throw new RuntimeException('Unsupported dynamic rule result status.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($title === '' || $reason === '') throw new RuntimeException('Rule title and reason are required.');

        $expected = $this->normaliseExpected($data['comparison_value'] ?? null, $definition['data_type'] ?? 'text');
        $applicability = $this->buildApplicability($data);

        $id = DB::table('decision_dynamic_rules')->insertGetId([
            'destination_key'=>$destination,
            'title'=>Str::limit($title,255,''),
            'fact_key'=>$factKey,
            'operator'=>$operator,
            'expected_value_json'=>json_encode($expected),
            'result_status'=>$status,
            'reason'=>$reason,
            'applicability'=>$applicability ? json_encode($applicability) : null,
            'source_type'=>'operator_rule',
            'source_detail'=>filled($data['source_detail'] ?? null) ? trim((string)$data['source_detail']) : null,
            'is_active'=>true,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        return [
            'success'=>true,
            'rule_id'=>$id,
            'destination_key'=>$destination,
            'fact_key'=>$factKey,
            'fact_definition_created'=>(bool)($definition['is_system'] ?? false) === false,
            'result_status'=>$status,
            'operator'=>$operator,
            'comparison_value'=>$expected,
        ];
    }

    private function evaluateDebtRule(Lead $lead, array $rule, array $definition): array
    {
        $out = [];
        foreach ($lead->debts as $debt) {
            $values = $this->facts->debtValues($debt);
            $actual = $values[$rule['fact_key']] ?? null;
            if ($actual === null || (is_string($actual) && trim($actual) === '')) {
                $finding = $this->finding($rule, 'UNKNOWN', null, $definition);
            } else {
                $expected = $this->decode($rule['expected_value_json'] ?? null);
                $matched = $this->registry->compare($actual, (string)$rule['operator'], $expected);
                $finding = $this->finding($rule, $matched ? (string)$rule['result_status'] : 'SATISFIED', $actual, $definition);
            }
            $finding['debt_id'] = $debt->id;
            $finding['creditor'] = $debt->creditor?->name;
            $out[] = $finding;
        }
        return $out;
    }

    private function finding(array $rule, string $status, mixed $actual, array $definition): array
    {
        return [
            'rule_id'=>(int)($rule['id'] ?? 0),
            'title'=>$rule['title'] ?? null,
            'fact_key'=>$rule['fact_key'] ?? null,
            'label'=>$definition['label'] ?? null,
            'status'=>$status,
            'actual'=>$actual,
            'reason'=>$status==='SATISFIED'
                ? 'The learned/operator check is not triggered by the recorded fact.'
                : ($rule['reason'] ?? null),
            'question'=>$definition['question'] ?? null,
            'source_type'=>$rule['source_type'] ?? 'operator_rule',
            'source_detail'=>$rule['source_detail'] ?? null,
        ];
    }

    private function buildApplicability(array $data): ?array
    {
        $conditions = [];

        if (($data['requires_hmrc_debt'] ?? false) === true) {
            $conditions[] = ['has_hmrc_debt'=>true];
        }

        $key = trim((string) ($data['applies_when_fact_key'] ?? ''));
        if ($key !== '') {
            $operator = strtolower(trim((string) ($data['applies_when_operator'] ?? 'equals')));
            $value = $this->normaliseLoose($data['applies_when_value'] ?? null);
            $conditions[] = ['fact'=>['key'=>$key,'operator'=>$operator,'value'=>$value]];
        }

        if (!$conditions) return null;
        if (count($conditions) === 1) return $conditions[0];
        return ['all'=>$conditions];
    }

    private function destinationKey(mixed $destination): ?string
    {
        $value = Str::lower(trim((string) $destination));
        if ($value === '' || in_array($value,['all','any','global'],true)) return null;

        return match ($value) {
            'zebra'=>'zebra',
            'lawson fox','lawson','lawson_fox'=>'lawson_fox',
            'ac','anchorage','anchorage chambers','anchorage_chambers'=>'anchorage_chambers',
            'assure'=>'assure',
            'tig'=>'tig',
            default=>throw new RuntimeException('Unsupported destination for learned rule.'),
        };
    }

    private function normaliseExpected(mixed $value, string $type): mixed
    {
        if ($type === 'boolean') {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) throw new RuntimeException('Boolean comparison value must be true or false.');
            return $parsed;
        }
        if (in_array($type,['money','money_or_none','integer','percentage'],true)) {
            if (!is_numeric($value)) throw new RuntimeException('Numeric comparison value required.');
            return $type === 'integer' ? (int)$value : (float)$value;
        }
        return $this->normaliseLoose($value);
    }

    private function normaliseLoose(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $trim = trim($value);
        if (in_array(Str::lower($trim),['true','yes'],true)) return true;
        if (in_array(Str::lower($trim),['false','no'],true)) return false;
        if (is_numeric($trim)) return str_contains($trim,'.') ? (float)$trim : (int)$trim;
        return $trim;
    }

    private function worstStatus(array $findings): string
    {
        if (!$findings) return 'NOT_APPLICABLE';
        $order=['BLOCKED'=>5,'UNKNOWN'=>4,'EXCEPTION_ESCALATION'=>3,'FIT_WITH_ACTIONS'=>2,'SATISFIED'=>1,'NOT_APPLICABLE'=>0];
        return collect($findings)->sortByDesc(fn($x)=>$order[$x['status']]??0)->first()['status'];
    }

    private function decode(mixed $value): mixed
    {
        if ($value === null) return null;
        if (is_array($value) || is_bool($value) || is_numeric($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
