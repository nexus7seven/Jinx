<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\CaseAssessmentViewService;
use App\Services\DecisionCaseFactService;
use App\Services\DecisionFactRegistryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CaseAssessmentController extends Controller
{
    public function show(Lead $lead, CaseAssessmentViewService $view): JsonResponse
    {
        return response()->json([
            'success' => true,
            'assessment' => $view->forLead($lead),
        ]);
    }

    public function updateFact(
        Request $request,
        Lead $lead,
        DecisionFactRegistryService $registry,
        DecisionCaseFactService $facts,
        CaseAssessmentViewService $view
    ): JsonResponse {
        $validated = $request->validate([
            'scope' => ['required', 'in:case,debt'],
            'fact_key' => ['required', 'string', 'max:120'],
            'debt_id' => ['nullable', 'integer'],
            'value' => ['present'],
        ]);

        $definition = $registry->definition($validated['fact_key']);
        if (!$definition || ($definition['is_active'] ?? false) !== true) {
            throw ValidationException::withMessages(['fact_key' => 'This reasoning fact is not active.']);
        }
        if (($definition['scope'] ?? null) !== $validated['scope']) {
            throw ValidationException::withMessages(['fact_key' => 'The fact scope does not match this field.']);
        }

        $storageType = $definition['storage_type'] ?? null;
        if ($validated['scope'] === 'case' && $storageType !== 'lead_decision_fact') {
            throw ValidationException::withMessages(['fact_key' => 'This value is derived from another part of the case and cannot be edited here.']);
        }
        if ($validated['scope'] === 'debt' && $storageType !== 'debt_decision_fact') {
            throw ValidationException::withMessages(['fact_key' => 'This debt value is derived from the debt record and cannot be edited here.']);
        }

        $value = $this->normalise($validated['value'], (string) ($definition['data_type'] ?? 'text'));

        if ($validated['scope'] === 'case') {
            $facts->setLeadFact($lead, $validated['fact_key'], $value, 'operator', 'Edited in Case Assessment tab');
        } else {
            $debtId = (int) ($validated['debt_id'] ?? 0);
            $debt = $lead->debts()->whereKey($debtId)->first();
            if (!$debt) {
                throw ValidationException::withMessages(['debt_id' => 'Debt was not found on this case.']);
            }
            $facts->setDebtFact($debt, $validated['fact_key'], $value, 'operator', 'Edited in Case Assessment tab');
        }

        return response()->json([
            'success' => true,
            'assessment' => $view->forLead($lead->fresh()),
        ]);
    }

    private function normalise(mixed $value, string $type): mixed
    {
        if ($type === 'boolean') {
            if (is_bool($value)) return $value;
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                throw ValidationException::withMessages(['value' => 'Enter yes or no.']);
            }
            return $parsed;
        }

        if (in_array($type, ['money', 'money_or_none', 'percentage'], true)) {
            if ($type === 'money_or_none' && is_string($value) && in_array(Str::lower(trim($value)), ['none','no','nil','zero'], true)) {
                return 0.0;
            }
            $normalised = is_string($value) ? str_replace([',','£','%'], '', trim($value)) : $value;
            if (!is_numeric($normalised) || (float) $normalised < 0) {
                throw ValidationException::withMessages(['value' => 'Enter a valid non-negative number.']);
            }
            $number = (float) $normalised;
            if ($type === 'percentage' && $number > 100) {
                throw ValidationException::withMessages(['value' => 'Percentage must be between 0 and 100.']);
            }
            return $number;
        }

        if ($type === 'integer') {
            if (!is_numeric($value) || (int) $value < 0) {
                throw ValidationException::withMessages(['value' => 'Enter a valid whole number.']);
            }
            return (int) $value;
        }

        if ($type === 'date') {
            try {
                return Carbon::parse((string) $value, 'Europe/London')->toDateString();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['value' => 'Enter a valid date.']);
            }
        }

        if ($type === 'legacy') {
            throw new RuntimeException('Legacy fields are not editable from Case Assessment.');
        }

        $text = trim((string) $value);
        if ($text === '') {
            throw ValidationException::withMessages(['value' => 'Enter a value.']);
        }
        return Str::limit($text, 2000, '');
    }
}
