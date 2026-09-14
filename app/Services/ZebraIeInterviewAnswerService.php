<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class ZebraIeInterviewAnswerService
{
    public function __construct(private readonly PartnerKnowledgeService $partnerKnowledge) {}

    /**
     * The Zebra I&E chatbot is an explicit state machine.
     * The current state is always the first unresolved required MANUAL_INPUT checkpoint.
     * A checkpoint is resolved only when it has been explicitly confirmed by the chatbot
     * workflow, not merely because the language model returned a similarly named fact.
     */
    public function currentStep(array $facts): ?array
    {
        if (!$this->resolved($facts, 'calculation.target_di')) return $this->step('calculation.target_di', 'money', 'What is the target DI?');
        if (!$this->resolved($facts, 'income.client_salary')) return $this->step('income.client_salary', 'money', "What is the client's monthly take-home salary?");
        if (!$this->resolved($facts, 'household.partner_exists')) return $this->step('household.partner_exists', 'yes_no', 'Does the client have a partner?');
        if ($this->bool($facts, 'household.partner_exists') === true && !$this->resolved($facts, 'income.partner_salary')) return $this->step('income.partner_salary', 'money', "What is the partner's monthly take-home salary?");
        if (!$this->resolved($facts, 'household.children_count')) return $this->step('household.children_count', 'children_count', 'How many children live with the client?');

        $children = (int) ($facts['household.children_count'] ?? 0);
        $ages = $facts['household.children_ages'] ?? [];
        if ($children > 0 && (!$this->resolved($facts, 'household.children_ages') || !is_array($ages) || count($ages) !== $children)) {
            return $this->step('household.children_ages', 'ages', "What are the ages of the {$children} children living with the client?");
        }

        if (!$this->resolved($facts, 'income.universal_credit')) return $this->step('income.universal_credit', 'money', "What is the client's monthly Universal Credit? Enter 0 if none.");
        if (!$this->secondaryComplete($facts)) return $this->step('income.secondary_screen', 'secondary_income', "Does the client or their partner receive any of the following? If yes, state which and the monthly amount: PIP/DLA, ESA, Carer's Allowance, maintenance income, pension income, student loan/grant/bursary, or Foster/Guardianship Allowance. If none, enter 0.");

        if (!$this->resolved($facts, 'housing.rent_mortgage')) return $this->step('housing.rent_mortgage', 'money', 'What is the monthly rent or mortgage?');
        if (!$this->resolved($facts, 'housing.council_tax')) return $this->step('housing.council_tax', 'money', 'What is the monthly Council Tax?');
        if (!$this->resolved($facts, 'transport.client.mode')) return $this->step('transport.client.mode', 'transport', 'Does the client have a car or use public transport?');
        if ($this->isCar($facts['transport.client.mode'] ?? null) && !$this->resolved($facts, 'transport.client.car_insurance')) return $this->step('transport.client.car_insurance', 'money', "What is the client's monthly car insurance?");

        if ($this->bool($facts, 'household.partner_exists') === true) {
            if (!$this->resolved($facts, 'transport.partner.mode')) return $this->step('transport.partner.mode', 'transport', 'Does the partner have a car or use public transport?');
            if ($this->isCar($facts['transport.partner.mode'] ?? null) && !$this->resolved($facts, 'transport.partner.car_insurance')) return $this->step('transport.partner.car_insurance', 'money', "What is the partner's monthly car insurance?");
        }

        if (!$this->resolved($facts, 'other.childcare')) return $this->step('other.childcare', 'money', 'Does the client have any monthly childcare costs? Enter 0 if none.');
        if (!$this->resolved($facts, 'other.maintenance_paid')) return $this->step('other.maintenance_paid', 'money', 'Does the client pay monthly maintenance for children who do not live with them? Enter 0 if none.');

        return null;
    }

    public function nextQuestion(array $facts): ?string
    {
        return $this->currentStep($facts)['question'] ?? null;
    }

    public function incomeComplete(array $facts): bool
    {
        if (!$this->resolved($facts, 'income.client_salary')) return false;
        if (!$this->resolved($facts, 'household.partner_exists')) return false;
        if ($this->bool($facts, 'household.partner_exists') === true && !$this->resolved($facts, 'income.partner_salary')) return false;
        if (!$this->resolved($facts, 'household.children_count')) return false;
        $children = (int) ($facts['household.children_count'] ?? 0);
        $ages = $facts['household.children_ages'] ?? [];
        if ($children > 0 && (!$this->resolved($facts, 'household.children_ages') || !is_array($ages) || count($ages) !== $children)) return false;
        if (!$this->resolved($facts, 'income.universal_credit')) return false;
        return $this->secondaryComplete($facts);
    }

    public function interviewComplete(array $facts): bool
    {
        return $this->currentStep($facts) === null;
    }

    public function extract(Lead $lead, array $facts, string $message): array
    {
        if (($facts['workflow.ie_active'] ?? false) !== true) return [];
        $profile = $this->partnerKnowledge->profile($lead->source, $facts);
        if (($profile['partner'] ?? null) !== 'Zebra') return [];

        $step = $this->currentStep($facts);
        if ($step === null) return [];

        $parsed = $this->parseStep($step, $facts, $message);
        if ($parsed === []) return [];

        $confirmed = $this->confirmedKeys($facts);
        foreach (array_keys($parsed) as $key) {
            if (!str_starts_with($key, 'workflow.')) $confirmed[] = $key;
        }
        $parsed['workflow.ie_confirmed_keys'] = array_values(array_unique($confirmed));

        return $parsed;
    }

    public function manualKeys(): array
    {
        return [
            'calculation.target_di',
            'income.client_salary',
            'household.partner_exists',
            'income.partner_salary',
            'household.children_count',
            'household.children_ages',
            'income.universal_credit',
            'income.pip_dla',
            'income.esa',
            'income.carers_allowance',
            'income.maintenance_received',
            'income.pension',
            'income.student',
            'income.foster_guardianship',
            'housing.rent_mortgage',
            'housing.council_tax',
            'transport.client.mode',
            'transport.client.car_insurance',
            'transport.partner.mode',
            'transport.partner.car_insurance',
            'other.childcare',
            'other.maintenance_paid',
        ];
    }

    private function parseStep(array $step, array $facts, string $message): array
    {
        $key = $step['key'];
        $type = $step['type'];
        $text = trim($message);
        $lower = Str::lower($text);

        if ($type === 'money') {
            $number = $this->number($text);
            return $number === null ? [] : [$key => $number];
        }

        if ($type === 'yes_no') {
            if ($this->isNo($lower)) return [$key => false];
            if ($this->isYes($lower)) return [$key => true];
            return [];
        }

        if ($type === 'children_count') {
            if ($this->isNo($lower)) return ['household.children_count' => 0, 'household.children_ages' => []];
            if (preg_match('/\b(\d+)\b/', $text, $m)) return [$key => (int) $m[1]];
            return [];
        }

        if ($type === 'ages') {
            preg_match_all('/\d+/', $text, $m);
            $ages = array_map('intval', $m[0] ?? []);
            $expected = (int) ($facts['household.children_count'] ?? 0);
            return $expected > 0 && count($ages) === $expected ? [$key => $ages] : [];
        }

        if ($type === 'secondary_income') {
            if ($this->isNo($lower)) {
                return [
                    'income.pip_dla' => 0,
                    'income.esa' => 0,
                    'income.carers_allowance' => 0,
                    'income.maintenance_received' => 0,
                    'income.pension' => 0,
                    'income.student' => 0,
                    'income.foster_guardianship' => 0,
                ];
            }
            return [];
        }

        if ($type === 'transport') {
            if (preg_match('/\b(car|vehicle)\b/i', $text)) return [$key => 'car'];
            if (preg_match('/\b(public\s*transport|public|bus|train|tram|metro)\b/i', $text)) return [$key => 'public transport'];
            return [];
        }

        return [];
    }

    private function step(string $key, string $type, string $question): array
    {
        return ['key' => $key, 'type' => $type, 'question' => $question];
    }

    private function secondaryComplete(array $facts): bool
    {
        foreach (['income.pip_dla','income.esa','income.carers_allowance','income.maintenance_received','income.pension','income.student','income.foster_guardianship'] as $key) {
            if (!$this->resolved($facts, $key)) return false;
        }
        return true;
    }

    private function confirmedKeys(array $facts): array
    {
        $keys = $facts['workflow.ie_confirmed_keys'] ?? [];
        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    private function resolved(array $facts, string $key): bool
    {
        if (!$this->has($facts, $key)) return false;
        if (($facts['workflow.ie_active'] ?? false) !== true) return true;

        // During an active I&E, only facts explicitly confirmed by the deterministic
        // interview are allowed to satisfy a manual checkpoint. This prevents the model
        // or stale Financial Statement data from silently skipping questions.
        return in_array($key, $this->confirmedKeys($facts), true);
    }

    private function number(string $value): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value));
        return $clean !== null && $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    private function isNo(string $value): bool { return in_array(trim($value), ['0','no','n','none','nil','nope'], true); }
    private function isYes(string $value): bool { return in_array(trim($value), ['yes','y','yeah','yep'], true); }
    private function has(array $facts, string $key): bool { return array_key_exists($key, $facts) && $facts[$key] !== null && $facts[$key] !== ''; }
    private function bool(array $facts, string $key): ?bool { return $this->resolved($facts, $key) ? filter_var($facts[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null; }
    private function isCar(mixed $value): bool { return in_array(Str::lower(trim((string) $value)), ['car','vehicle'], true); }
}
