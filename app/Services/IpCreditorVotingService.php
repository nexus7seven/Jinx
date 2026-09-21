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
    public const MANUAL_STATUS_OPTIONS = [
        'Accept',
        'Accept - via house vote',
        'Accept - with conditions',
        'Accept - Trial @ MOC',
        'Accept - Referral',
        'Reject',
        'Non-vote',
    ];

    public function analyseLead(Lead $lead): array
    {
        $lead->loadMissing('debts.creditor');

        $ipKey = $lead->iva_ip_key;
        $items = [];
        $routesByDebt = [];

        if ($ipKey && Schema::hasTable('creditor_voting_routes')) {
            $routeAnalysis = app(DecisionVotingService::class)->analyse($lead, $ipKey, null);

            foreach ($routeAnalysis['debts'] ?? [] as $routeDebt) {
                $routesByDebt[(int) ($routeDebt['debt_id'] ?? 0)] = $routeDebt;
            }
        }

        foreach ($lead->debts as $debt) {
            if (!$ipKey) {
                $assessment = $this->emptyAssessment('ip_not_selected');
            } else {
                $assessment = $this->resolveCreditor($debt->creditor, $ipKey);

                if (($assessment['source_type'] ?? null) === 'workbook') {
                    $routeDebt = $routesByDebt[(int) $debt->id] ?? null;
                    $routeSource = (string) ($routeDebt['route_source'] ?? '');

                    if (str_starts_with($routeSource, 'sourced_route')) {
                        $resolvedHouse = trim((string) ($routeDebt['voting_house'] ?? ''));

                        if ($resolvedHouse !== '' && $resolvedHouse !== 'Unresolved representative') {
                            $assessment['voting_house'] = $resolvedHouse;
                            $assessment['representative_rules'] = $this->representativeRules($ipKey, $resolvedHouse);
                        }
                    } elseif ($routeSource === 'unresolved_conflict') {
                        $assessment['voting_house'] = null;
                        $assessment['needs_review'] = true;
                        $assessment['reason'] = 'representative_route_conflict';
                        $assessment['route_candidates'] = $routeDebt['route_candidates'] ?? [];
                        $assessment['route_conflict_reason'] = $routeDebt['route_conflict_reason'] ?? null;
                    }
                }
            }

            $assessment = $this->withPresentation($assessment);

            $items[] = array_merge([
                'debt_id' => (int) $debt->id,
                'creditor_id' => (int) $debt->creditor_id,
                'creditor_name' => $debt->creditor?->name,
                'balance' => round((float) $debt->balance, 2),
                'reference' => $debt->reference,
            ], $assessment);
        }

        $summary = $this->summarise($items);

        return [
            'success' => true,
            'lead_id' => (int) $lead->id,
            'ip_key' => $ipKey,
            'ip_label' => $ipKey ? (Lead::IVA_IPS[$ipKey] ?? $ipKey) : null,
            'total_debt' => $summary['total_debt'],
            'summary' => $summary,
            'debts' => $items,
            'unresolved' => array_values(array_filter(
                $items,
                fn (array $item) => ($item['needs_input'] ?? false) === true || ($item['needs_review'] ?? false) === true
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
                $statusRaw = (string) $manual->status_text;
                $outcome = $this->interpretStatus($statusRaw);

                return $this->withPresentation([
                    'outcome' => $outcome,
                    'status_raw' => $statusRaw,
                    'voting_house' => filled($manual->voting_house) ? (string) $manual->voting_house : null,
                    'notes' => filled($manual->condition_text) ? (string) $manual->condition_text : null,
                    'source_type' => 'manual',
                    'source_label' => 'Manual Jinx entry',
                    'source_rows' => [],
                    'representative_rules' => [],
                    'needs_input' => false,
                    'needs_review' => $outcome === 'unknown',
                    'can_save_override' => true,
                    'reason' => 'manual_override',
                ]);
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

        $interpretedStatuses = $distinctStatuses
            ->map(fn ($status) => $this->interpretStatus((string) $status))
            ->unique()
            ->values();

        $reason = 'workbook_status';

        if ($distinctStatuses->isEmpty() && $votingHouse) {
            // A creditor routed to a voting house is a hard accept for the headline vote.
            $outcome = 'accept';
            $reason = 'workbook_house_vote';
        } elseif ($interpretedStatuses->count() === 1 && $interpretedStatuses->first() !== 'unknown') {
            // Multiple raw workbook rows are fine when they all resolve to the same hard bucket.
            $outcome = (string) $interpretedStatuses->first();
        } else {
            $outcome = 'unknown';
            $reason = $distinctStatuses->count() > 1
                ? 'multiple_workbook_statuses'
                : 'unrecognised_workbook_status';
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

        return $this->withPresentation([
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
        ]);
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

        $nonVote = str_contains($value, 'non-voting')
            || str_contains($value, 'non voting')
            || str_contains($value, 'non-noting')
            || str_contains($value, 'non noting')
            || str_contains($value, 'non-vote')
            || str_contains($value, 'non vote')
            || str_contains($value, 'no vote')
            || str_contains($value, 'do not vote');

        $reject = $value === 'reject';

        $accept = $value === 'accept'
            || $value === 'accept - via house vote'
            || $value === 'accept - with conditions'
            || $value === 'accept - trial @ moc'
            || $value === 'accept - referral'
            || str_contains($value, 'accept with condition')
            || str_contains($value, 'accept with modification')
            || $value === 'referral'
            || $value === 'trial @ moc'
            || $value === 'trial at moc'
            || $value === 'represented'
            || str_contains($value, 'represented by')
            || str_contains($value, 'representented by')
            || str_starts_with($value, 'represented ');

        // Never guess when a single source phrase explicitly mixes hard buckets.
        if (
            ($nonVote && (str_contains($value, 'reject') || $accept))
            || (str_contains($value, 'reject') && $accept)
        ) {
            return 'unknown';
        }

        if ($nonVote) {
            return 'non_voting';
        }

        if ($reject) {
            return 'reject';
        }

        if ($accept) {
            return 'accept';
        }

        return 'unknown';
    }

    public function acceptKind(?string $statusRaw, ?string $votingHouse = null): ?string
    {
        if ($this->interpretStatus($statusRaw) !== 'accept' && !blank($statusRaw)) {
            return null;
        }

        $value = Str::of((string) $statusRaw)
            ->lower()
            ->replace(['–', '—'], '-')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        if (str_contains($value, 'referral')) {
            return 'referral';
        }

        if (str_contains($value, 'trial @ moc') || str_contains($value, 'trial at moc')) {
            return 'trial_moc';
        }

        if (str_contains($value, 'condition') || str_contains($value, 'modification')) {
            return 'conditions';
        }

        if (
            str_contains($value, 'via house vote')
            || str_contains($value, 'represented')
            || filled($votingHouse)
        ) {
            return 'via_house';
        }

        return 'direct';
    }

    public function displayLabel(string $outcome, ?string $statusRaw = null, ?string $votingHouse = null): string
    {
        if ($outcome === 'accept') {
            return match ($this->acceptKind($statusRaw, $votingHouse)) {
                'via_house' => 'ACCEPT · VIA HOUSE',
                'conditions' => 'ACCEPT · WITH CONDITIONS',
                'trial_moc' => 'ACCEPT · TRIAL @ MOC',
                'referral' => 'ACCEPT · REFERRAL',
                default => 'ACCEPT',
            };
        }

        return match ($outcome) {
            'reject' => 'REJECT',
            'non_voting' => 'NON-VOTE',
            'missing' => 'NO IP CRITERIA',
            default => filled($statusRaw) ? mb_strtoupper((string) $statusRaw).' · REVIEW' : 'REVIEW',
        };
    }

    public function summarise(array $items): array
    {
        $totalDebt = 0.0;
        $acceptBalance = 0.0;
        $rejectBalance = 0.0;
        $nonVoteBalance = 0.0;
        $unresolvedBalance = 0.0;

        foreach ($items as $item) {
            $balance = (float) ($item['balance'] ?? 0);
            $outcome = (string) ($item['outcome'] ?? 'unknown');
            $totalDebt += $balance;

            if ($outcome === 'accept') {
                $acceptBalance += $balance;
            } elseif ($outcome === 'reject') {
                $rejectBalance += $balance;
            } elseif ($outcome === 'non_voting') {
                $nonVoteBalance += $balance;
            } else {
                $unresolvedBalance += $balance;
            }
        }

        $votingBalance = $acceptBalance + $rejectBalance;

        if ($votingBalance > 0) {
            $acceptPercent = round(($acceptBalance / $votingBalance) * 100, 1);
            // There are only two voting directions, so force the displayed pair to total 100.0.
            $rejectPercent = round(100 - $acceptPercent, 1);
        } else {
            $acceptPercent = 0.0;
            $rejectPercent = 0.0;
        }

        return [
            'total_debt' => round($totalDebt, 2),
            'voting_balance' => round($votingBalance, 2),
            'accept_balance' => round($acceptBalance, 2),
            'reject_balance' => round($rejectBalance, 2),
            'non_vote_balance' => round($nonVoteBalance, 2),
            'unresolved_balance' => round($unresolvedBalance, 2),
            'accept_percent' => $acceptPercent,
            'reject_percent' => $rejectPercent,
        ];
    }

    private function withPresentation(array $assessment): array
    {
        $outcome = (string) ($assessment['outcome'] ?? 'unknown');
        $statusRaw = $assessment['status_raw'] ?? null;
        $votingHouse = $assessment['voting_house'] ?? null;

        $assessment['accept_kind'] = $outcome === 'accept'
            ? $this->acceptKind($statusRaw, $votingHouse)
            : null;

        $assessment['display_label'] = $this->displayLabel($outcome, $statusRaw, $votingHouse);

        if (
            $outcome === 'accept'
            && $assessment['accept_kind'] === 'via_house'
            && blank($votingHouse)
        ) {
            $assessment['needs_review'] = true;

            if (($assessment['reason'] ?? null) === 'workbook_status') {
                $assessment['reason'] = 'house_vote_missing_house';
            }
        }

        return $assessment;
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
            'accept_kind' => null,
            'display_label' => 'NO IP CRITERIA',
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
