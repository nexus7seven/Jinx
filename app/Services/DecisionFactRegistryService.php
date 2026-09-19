<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class DecisionFactRegistryService
{
    public function definitions(?string $scope = null, bool $activeOnly = true): array
    {
        if (!Schema::hasTable('decision_fact_definitions')) return [];

        $q = DB::table('decision_fact_definitions')->orderBy('question_priority')->orderBy('id');
        if ($scope !== null) $q->where('scope', $scope);
        if ($activeOnly) $q->where('is_active', true);

        return $q->get()->map(fn($row) => $this->normaliseRow((array) $row))->all();
    }

    public function definition(string $key): ?array
    {
        if (!Schema::hasTable('decision_fact_definitions')) return null;
        $row = DB::table('decision_fact_definitions')->where('fact_key', $key)->first();
        return $row ? $this->normaliseRow((array) $row) : null;
    }

    public function supports(string $key, string $scope): bool
    {
        if (!Schema::hasTable('decision_fact_definitions')) return false;

        return DB::table('decision_fact_definitions')
            ->where('fact_key', $key)
            ->where('scope', $scope)
            ->exists();
    }

    public function define(array $data): array
    {
        if (!Schema::hasTable('decision_fact_definitions')) {
            throw new RuntimeException('Decision fact registry is not available.');
        }

        $scope = strtolower(trim((string) ($data['scope'] ?? '')));
        if (!in_array($scope, ['case','debt'], true)) throw new RuntimeException('Fact scope must be case or debt.');

        $key = trim((string) ($data['fact_key'] ?? ''));
        if (!preg_match('/^(?:case|property|partner|evidence|debt|voting)\.[a-z0-9_]+$/', $key)) {
            throw new RuntimeException('Invalid fact key. Use a stable dot-notated key.');
        }

        $type = strtolower(trim((string) ($data['data_type'] ?? 'text')));
        $allowedTypes = ['boolean','money','money_or_none','integer','date','text','percentage','legacy'];
        if (!in_array($type, $allowedTypes, true)) throw new RuntimeException('Unsupported fact data type.');

        $label = trim((string) ($data['label'] ?? ''));
        $question = trim((string) ($data['question'] ?? ''));
        if ($label === '') throw new RuntimeException('Fact label is required.');
        if (($data['reasoning_required'] ?? false) && $question === '') {
            throw new RuntimeException('A reasoning-required fact needs a questionnaire prompt.');
        }

        $existing = DB::table('decision_fact_definitions')->where('fact_key', $key)->first();
        if ($existing && (bool) $existing->is_system && ($data['allow_system_update'] ?? false) !== true) {
            throw new RuntimeException('System fact definitions cannot be changed through learning without an explicit system-update flag.');
        }

        $payload = [
            'scope' => $scope,
            'label' => Str::limit($label, 160, ''),
            'data_type' => $type,
            'storage_type' => $scope === 'debt' ? 'debt_decision_fact' : 'lead_decision_fact',
            'storage_key' => null,
            'question' => $question === '' ? null : Str::limit($question, 2000, ''),
            'source_hints' => json_encode(array_values(array_filter((array) ($data['source_hints'] ?? ['operator'])))),
            'applicability' => isset($data['applicability']) ? json_encode($data['applicability']) : null,
            'reasoning_required' => (bool) ($data['reasoning_required'] ?? true),
            'question_priority' => max(1, min(9999, (int) ($data['question_priority'] ?? 1000))),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_system' => false,
            'metadata' => json_encode([
                'created_by' => 'jinx_learning',
                'source_detail' => $data['source_detail'] ?? null,
            ]),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('decision_fact_definitions')->where('id', $existing->id)->update($payload);
            $id = (int) $existing->id;
        } else {
            $payload['fact_key'] = $key;
            $payload['created_at'] = now();
            $id = DB::table('decision_fact_definitions')->insertGetId($payload);
        }

        return [
            'success' => true,
            'definition_id' => $id,
            'fact_key' => $key,
            'scope' => $scope,
            'data_type' => $type,
            'reasoning_required' => (bool) $payload['reasoning_required'],
        ];
    }

    public function applicabilityMatches(Lead $lead, mixed $applicability, array $caseValues, array $ieFacts): bool
    {
        if ($applicability === null || $applicability === [] || $applicability === '') return true;
        if (is_string($applicability)) {
            $decoded = json_decode($applicability, true);
            $applicability = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($applicability) || $applicability === []) return true;

        if (isset($applicability['all']) && is_array($applicability['all'])) {
            foreach ($applicability['all'] as $condition) {
                if (!$this->applicabilityMatches($lead, $condition, $caseValues, $ieFacts)) return false;
            }
            return true;
        }

        if (isset($applicability['any']) && is_array($applicability['any'])) {
            foreach ($applicability['any'] as $condition) {
                if ($this->applicabilityMatches($lead, $condition, $caseValues, $ieFacts)) return true;
            }
            return false;
        }

        if (($applicability['has_hmrc_debt'] ?? false) === true) {
            return $this->hasHmrcDebt($lead);
        }

        if (($applicability['partner_income_positive'] ?? false) === true) {
            return (float) data_get($ieFacts, 'income.partner_salary', 0) > 0;
        }

        if (($applicability['uc_positive'] ?? false) === true) {
            return (float) data_get($ieFacts, 'income.universal_credit', 0) > 0;
        }

        if (($applicability['benefits_positive'] ?? false) === true) {
            return collect([
                'income.child_benefit','income.pip_dla','income.esa','income.carers_allowance',
                'income.foster_guardianship','income.pension',
            ])->sum(fn($key) => (float) data_get($ieFacts, $key, 0)) > 0;
        }

        if (($applicability['immigration_non_british'] ?? false) === true) {
            $status = Str::lower(trim((string) ($caseValues['case.immigration_status'] ?? '')));
            return $status !== '' && !in_array($status, ['uk citizen','british','british citizen'], true);
        }

        if (isset($applicability['fact']) && is_array($applicability['fact'])) {
            $condition = $applicability['fact'];
            $key = trim((string) ($condition['key'] ?? ''));
            $operator = strtolower(trim((string) ($condition['operator'] ?? 'equals')));
            $expected = $condition['value'] ?? null;
            $actual = $this->contextValue($lead, $key, $caseValues, $ieFacts);
            return $this->compare($actual, $operator, $expected);
        }

        return true;
    }

    public function contextValue(Lead $lead, string $key, array $caseValues, array $ieFacts): mixed
    {
        if ($key === 'derived.has_hmrc_debt') return $this->hasHmrcDebt($lead);
        if ($key === 'derived.partner_income_positive') return (float) data_get($ieFacts, 'income.partner_salary', 0) > 0;
        if ($key === 'derived.uc_positive') return (float) data_get($ieFacts, 'income.universal_credit', 0) > 0;

        if (array_key_exists($key, $caseValues)) return $caseValues[$key];
        if (data_get($ieFacts, $key) !== null) return data_get($ieFacts, $key);

        $definition = $this->definition($key);
        if (!$definition) return null;

        return match ($definition['storage_type']) {
            'lead_field' => $definition['storage_key'] ? $lead->{$definition['storage_key']} : null,
            'financial_statement' => $definition['storage_key'] ? data_get($lead->financial_statement, $definition['storage_key']) : null,
            default => $caseValues[$key] ?? null,
        };
    }

    public function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'exists' => $actual !== null && $actual !== '',
            'missing' => $actual === null || $actual === '',
            'truthy' => $this->bool($actual) === true,
            'falsy' => $this->bool($actual) === false,
            'not_equals' => $actual != $expected,
            'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            default => $actual == $expected,
        };
    }

    public function hasHmrcDebt(Lead $lead): bool
    {
        return $lead->debts()->whereHas('creditor', function ($q) {
            $q->where('name', 'like', '%HMRC%')->orWhere('name', 'like', '%HM Revenue%');
        })->exists();
    }

    private function normaliseRow(array $row): array
    {
        foreach (['source_hints','applicability','metadata'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : null;
            }
        }
        $row['reasoning_required'] = (bool) ($row['reasoning_required'] ?? false);
        $row['is_active'] = (bool) ($row['is_active'] ?? false);
        $row['is_system'] = (bool) ($row['is_system'] ?? false);
        return $row;
    }

    private function bool(mixed $value): ?bool
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
