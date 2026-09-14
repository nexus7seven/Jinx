<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class ZebraIeInterviewAnswerService
{
    public function __construct(private readonly PartnerKnowledgeService $partnerKnowledge) {}

    public function extract(Lead $lead, array $facts, string $message): array
    {
        if (($facts['workflow.ie_active'] ?? false) !== true) return [];

        $profile = $this->partnerKnowledge->profile($lead->source, $facts);
        if (($profile['partner'] ?? null) !== 'Zebra') return [];

        $key = $this->nextExpectedKey($facts);
        if ($key === null) return [];

        $text = trim($message);
        $lower = Str::lower($text);

        if (in_array($key, [
            'calculation.target_di','income.client_salary','income.partner_salary','income.universal_credit',
            'housing.rent_mortgage','housing.council_tax','transport.client.car_insurance',
            'transport.partner.car_insurance','other.childcare','other.maintenance_paid',
        ], true)) {
            $number = $this->number($text);
            return $number === null ? [] : [$key => $number];
        }

        if ($key === 'household.partner_exists') {
            if ($this->isNo($lower)) return [$key => false];
            if ($this->isYes($lower)) return [$key => true];
            return [];
        }

        if ($key === 'household.children_count') {
            if ($this->isNo($lower)) return ['household.children_count' => 0, 'household.children_ages' => []];
            if (preg_match('/\b(\d+)\b/', $text, $m)) return [$key => (int) $m[1]];
            return [];
        }

        if ($key === 'household.children_ages') {
            preg_match_all('/\d+/', $text, $m);
            $ages = array_map('intval', $m[0] ?? []);
            $expected = (int) ($facts['household.children_count'] ?? 0);
            return $expected > 0 && count($ages) === $expected ? [$key => $ages] : [];
        }

        if ($key === 'income.secondary_screen') {
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

        if (in_array($key, ['transport.client.mode','transport.partner.mode'], true)) {
            if (preg_match('/\b(car|vehicle)\b/i', $text)) return [$key => 'car'];
            if (preg_match('/\b(public|bus|train|tram)\b/i', $text)) return [$key => 'public transport'];
            return [];
        }

        return [];
    }

    private function nextExpectedKey(array $facts): ?string
    {
        if (!$this->has($facts,'calculation.target_di')) return 'calculation.target_di';
        if (!$this->has($facts,'income.client_salary')) return 'income.client_salary';
        if (!$this->has($facts,'household.partner_exists')) return 'household.partner_exists';
        if ($this->bool($facts,'household.partner_exists') === true && !$this->has($facts,'income.partner_salary')) return 'income.partner_salary';
        if (!$this->has($facts,'household.children_count')) return 'household.children_count';

        $children = (int) ($facts['household.children_count'] ?? 0);
        $ages = $facts['household.children_ages'] ?? [];
        if ($children > 0 && (!is_array($ages) || count($ages) !== $children)) return 'household.children_ages';

        if (!$this->has($facts,'income.universal_credit')) return 'income.universal_credit';
        if (!$this->secondaryComplete($facts)) return 'income.secondary_screen';
        if (!$this->has($facts,'housing.rent_mortgage')) return 'housing.rent_mortgage';
        if (!$this->has($facts,'housing.council_tax')) return 'housing.council_tax';
        if (!$this->has($facts,'transport.client.mode')) return 'transport.client.mode';
        if ($this->isCar($facts['transport.client.mode'] ?? null) && !$this->has($facts,'transport.client.car_insurance')) return 'transport.client.car_insurance';

        if ($this->bool($facts,'household.partner_exists') === true) {
            if (!$this->has($facts,'transport.partner.mode')) return 'transport.partner.mode';
            if ($this->isCar($facts['transport.partner.mode'] ?? null) && !$this->has($facts,'transport.partner.car_insurance')) return 'transport.partner.car_insurance';
        }

        if (!$this->has($facts,'other.childcare')) return 'other.childcare';
        if (!$this->has($facts,'other.maintenance_paid')) return 'other.maintenance_paid';
        return null;
    }

    private function secondaryComplete(array $facts): bool
    {
        foreach (['income.pip_dla','income.esa','income.carers_allowance','income.maintenance_received','income.pension','income.student','income.foster_guardianship'] as $key) {
            if (!$this->has($facts,$key)) return false;
        }
        return true;
    }

    private function number(string $value): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value));
        return $clean !== null && $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    private function isNo(string $value): bool { return in_array(trim($value), ['0','no','n','none','nil','nope'], true); }
    private function isYes(string $value): bool { return in_array(trim($value), ['yes','y','yeah','yep'], true); }
    private function has(array $facts,string $key): bool { return array_key_exists($key,$facts) && $facts[$key] !== null && $facts[$key] !== ''; }
    private function bool(array $facts,string $key): ?bool { return $this->has($facts,$key) ? filter_var($facts[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null; }
    private function isCar(mixed $value): bool { return in_array(Str::lower(trim((string) $value)), ['car','vehicle'], true); }
}
