<?php

namespace App\Services;

use App\Models\Creditor;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IpCreditorVotingService
{
    public function analyseLead(Lead $lead): array
    {
        $lead->loadMissing('debts.creditor');

        $ipKey = $lead->iva_ip_key;
        $items = [];
        $totalDebt = 0.0;

        foreach ($lead->debts as $debt) {
            $balance = (float) $debt->balance;
            $totalDebt += $balance;

            if (!$ipKey) {
                $assessment = $this->emptyAssessment('ip_not_selected');
            } else {
                $assessment = $this->resolveCreditor($debt->creditor, $ipKey);
            }

            $items[] = array_merge([
                'debt_id' => (int) $debt->id,
                'creditor_id' => (int) $debt->creditor_id,
                'creditor_name' => $debt->creditor?->name,
                'balance' => round($balance, 2),
                'reference' => $debt->reference,
            ], $assessment);
        }

        return [
            'success' => true,
            'lead_id' => (int) $lead->id,
            'ip_key' => $ipKey,
            'ip_label' => $ipKey ? (Lead::IVA_IPS[$ipKey] ?? $ipKey) : null,
            'total_debt' => round($totalDebt, 2),
            'debts' => $items,
            'unresolved' => array_values(array_filter(
                $items,
                fn (array $item) => ($item['needs_input'] ?? false) === true
            )),
        ];
    }

    public function resolveCreditor(?Creditor $creditor, string $ipKey): array
    {
        if (!$creditor) {
            return $this->emptyAssessment('creditor_missing');
        }

        if (!array_key_exists($ipKey, Lead::IVA_IPS)) {
            return $this->emptyAssessment('ip_not_supported');
        }

        if (Schema::hasTable('ip_creditor_voting_overrides')) {
            $manual = DB::table('ip_creditor_voting_overrides')
                ->where('creditor_id', $creditor->id)
                ->where('ip_key', $ipKey)
                ->first();

            if ($manual) {
                return [
                    'outcome' => (string) $manual->outcome,
                    'status_raw' => (string) $manual->status_text,
                    'voting_house' => filled($manual->voting_house) ? (string) $manual->voting_house : null,
                    'notes' => filled($manual->condition_text) ? (string) $manual->condition_text : null,
                    'source_type' => 'manual',
                    'source_label' => 'Manual Jinx entry',
                    'source_rows' => [],
                    'representative_rules' => [],
                    'needs_input' => false,
                    'needs_review' => $manual->outcome === 'unknown',
                    'can_save_override' => true,
                    'reason' => 'manual_override',
                ];
            }
        }

        if (!Schema::hasTable('decision_creditor_source_rows')) {
            return $this->emptyAssessment('source_table_missing');
        }

        $rows = DB::table('decision_creditor_source_rows')
            ->where('creditor_id', $creditor->id)
            ->where('partner_key', $ipKey)
            ->orderBy('source_name')
            ->orderBy('sheet')
            ->orderBy('source_row')
            ->get();

        if ($rows->isEmpty()) {
            $assessment = $this->emptyAssessment('not_in_ip_workbook');
            $assessment['can_save_override'] = strcasecmp(trim((string) $creditor->name), 'Could Not Match') !== 0;
            return $assessment;
        }

        $statusRows = $rows
            ->filter(fn ($row) => filled($row->status_text))
            ->values();

        $representatives = $rows
            ->pluck('representative_key')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->unique(fn ($value) => mb_strtolower($value))
            ->values();

        $distinctStatuses = $statusRows
            ->pluck('status_text')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => mb_strtolower($value))
            ->values();

        $statusRaw = $distinctStatuses->count() === 1
            ? $distinctStatuses->first()
            : ($distinctStatuses->count() > 1 ? $distinctStatuses->implode(' / ') : null);

        $votingHouse = $representatives->count() === 1
            ? $representatives->first()
            : null;

        $notes = $statusRows
            ->pluck('detail_text')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->unique()
            ->implode(' | ');

        $outcome = $this->interpretStatus($statusRaw);
        $reason = 'workbook_status';

        if ($distinctStatuses->count() > 1) {
            $outcome = 'unknown';
            $reason = 'multiple_workbook_statuses';
        } elseif ($outcome === 'unknown' && !$statusRaw && $votingHouse) {
            $outcome = 'represented';
            $reason = 'workbook_representative';
        }

        if (!$statusRaw && !$votingHouse) {
            $assessment = $this->emptyAssessment('workbook_row_without_voting_data');
            $assessment['source_rows'] = $this->serialiseSourceRows($rows);
            $assessment['can_save_override'] = strcasecmp(trim((string) $creditor->name), 'Could Not Match') !== 0;
            return $assessment;
        }

        $sourceRows = $this->serialiseSourceRows($rows);
        $sourceLabel = $this->sourceLabel($rows);
        $representativeRules = $votingHouse
            ? $this->representativeRules($ipKey, $votingHouse)
            : [];

        return [
            'outcome' => $outcome,
            'status_raw' => $statusRaw,
            'voting_house' => $votingHouse,
            'notes' => $notes !== '' ? $notes : null,
            'source_type' => 'workbook',
            'source_label' => $sourceLabel,
            'source_rows' => $sourceRows,
            'representative_rules' => $representativeRules,
            'needs_input' => false,
            'needs_review' => $outcome === 'unknown',
            'can_save_override' => true,
            'reason' => $reason,
        ];
    }

    public function interpretStatus(?string $status): string
    {
        $value = Str::of((string) $status)
            ->lower()
            ->replace(['–', '—'], '-')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        if ($value === '') {
            return 'unknown';
        }

        if ($value === 'accept') {
            return 'accept';
        }

        if (
            str_contains($value, 'accept with condition')
            || str_contains($value, 'accept with modification')
            || $value === 'referral'
            || $value === 'trial @ moc'
            || $value === 'trial at moc'
            || $value === 'moc'
        ) {
            return 'accept_conditional';
        }

        if (
            str_contains($value, 'represented by')
            || str_contains($value, 'represented by')
            || str_contains($value, 'representented by')
            || str_starts_with($value, 'represented ')
            || str_starts_with($value, 'represented')
        ) {
            return 'represented';
        }

        if (
            str_contains($value, 'non-voting')
            || str_contains($value, 'non voting')
            || str_contains($value, 'non-noting')
            || str_contains($value, 'non noting')
            || str_contains($value, 'no vote')
            || str_contains($value, 'do not vote')
        ) {
            if (str_contains($value, ' or reject')) {
                return 'unknown';
            }

            return 'non_voting';
        }

        if ($value === 'reject') {
            return 'reject';
        }

        return 'unknown';
    }

    public function outcomeLabel(string $outcome, ?string $statusRaw = null): string
    {
        return match ($outcome) {
            'accept' => 'ACCEPT',
            'accept_conditional' => 'ACCEPT — CONDITIONS',
            'reject' => 'REJECT',
            'non_voting' => 'NON-VOTING',
            'represented' => 'REPRESENTED',
            'missing' => 'NO IP CRITERIA',
            default => filled($statusRaw) ? mb_strtoupper((string) $statusRaw) : 'REVIEW',
        };
    }

    private function emptyAssessment(string $reason): array
    {
        return [
            'outcome' => 'missing',
            'status_raw' => null,
            'voting_house' => null,
            'notes' => null,
            'source_type' => null,
            'source_label' => null,
            'source_rows' => [],
            'representative_rules' => [],
            'needs_input' => in_array($reason, ['not_in_ip_workbook', 'workbook_row_without_voting_data'], true),
            'needs_review' => false,
            'can_save_override' => true,
            'reason' => $reason,
        ];
    }

    private function serialiseSourceRows(Collection $rows): array
    {
        return $rows->map(function ($row) {
            return [
                'source_name' => $row->source_name,
                'sheet' => $row->sheet,
                'row' => (int) $row->source_row,
                'status' => $row->status_text,
                'detail' => $row->detail_text,
                'voting_house' => $row->representative_key,
            ];
        })->values()->all();
    }

    private function sourceLabel(Collection $rows): ?string
    {
        $first = $rows->first();
        if (!$first) {
            return null;
        }

        return trim((string) $first->source_name)
            .' · '.trim((string) $first->sheet)
            .' · row '.(int) $first->source_row;
    }

    private function representativeRules(string $ipKey, string $house): array
    {
        if (!Schema::hasTable('decision_rules') || !Schema::hasTable('voting_houses')) {
            return [];
        }

        $houseId = DB::table('voting_houses')
            ->whereRaw('LOWER(`key`) = ?', [mb_strtolower($house)])
            ->value('id');

        if (!$houseId) {
            return [];
        }

        return DB::table('decision_rules as r')
            ->leftJoin('decision_rule_sources as s', 's.id', '=', 'r.source_id')
            ->where('r.scope_type', 'voting_house')
            ->where('r.voting_house_id', $houseId)
            ->where('r.partner_key', $ipKey)
            ->where('r.is_active', true)
            ->orderBy('r.id')
            ->get([
                'r.requirement_text',
                's.name as source_name',
                's.sheet as source_sheet',
                's.location as source_location',
            ])
            ->map(fn ($row) => [
                'text' => (string) $row->requirement_text,
                'source_name' => $row->source_name,
                'sheet' => $row->source_sheet,
                'location' => $row->source_location,
            ])
            ->all();
    }
}
