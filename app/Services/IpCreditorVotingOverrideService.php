<?php

namespace App\Services;

use App\Models\Creditor;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IpCreditorVotingOverrideService
{
    private const IP_ALIASES = [
        'tig' => 'tig',
        'theinsolvencygroup' => 'tig',
        'assure' => 'assure',
        'zebra' => 'zebra',
        'lawsonfox' => 'lawson_fox',
        'lawson_fox' => 'lawson_fox',
        'lawson' => 'lawson_fox',
        'anchoragechambers' => 'anchorage_chambers',
        'anchorage_chambers' => 'anchorage_chambers',
        'anchorage' => 'anchorage_chambers',
        'ac' => 'anchorage_chambers',
    ];

    public function __construct(private readonly IpCreditorVotingService $voting)
    {
    }

    public function normaliseIpKey(?string $value): ?string
    {
        $raw = Str::lower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        if (array_key_exists($raw, Lead::IVA_IPS)) {
            return $raw;
        }

        $normalised = preg_replace('/[^a-z0-9_]+/', '', str_replace(['&', '-'], ['and', '_'], $raw)) ?? '';
        $collapsed = str_replace('_', '', $normalised);

        return self::IP_ALIASES[$normalised]
            ?? self::IP_ALIASES[$collapsed]
            ?? null;
    }

    public function normaliseStatusText(?string $value): ?string
    {
        $raw = Str::lower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        $simple = preg_replace('/\s+/', ' ', str_replace(['–', '—', '_'], ['-', '-', ' '], $raw)) ?? $raw;

        return match (true) {
            in_array($simple, ['accept', 'accepted'], true) => 'Accept',
            in_array($simple, ['reject', 'rejected'], true) => 'Reject',
            in_array($simple, ['non vote', 'non-vote', 'non voting', 'non-voting', 'no vote', 'do not vote'], true) => 'Non-vote',
            str_contains($simple, 'referral') => 'Accept - Referral',
            str_contains($simple, 'trial') && str_contains($simple, 'moc') => 'Accept - Trial @ MOC',
            $simple === 'moc' => 'Accept - Trial @ MOC',
            str_contains($simple, 'condition') || str_contains($simple, 'modification') => 'Accept - with conditions',
            str_contains($simple, 'house') || str_contains($simple, 'represented') => 'Accept - via house vote',
            default => null,
        };
    }

    public function prepare(array $action, string $sourceMessage): array
    {
        $operation = Str::lower(trim((string) ($action['operation'] ?? 'set')));
        $operation = in_array($operation, ['revert', 'restore', 'remove'], true) ? 'revert' : 'set';

        $ipKey = $this->normaliseIpKey($action['ip'] ?? $action['ip_key'] ?? null);
        if (!$ipKey) {
            return [
                'status' => 'invalid',
                'message' => 'I could not match that insolvency practitioner. Use TIG, Assure, Zebra, Lawson Fox or Anchorage Chambers.',
            ];
        }

        $creditorQuery = trim((string) ($action['creditor'] ?? ''));
        if ($creditorQuery === '') {
            return ['status' => 'invalid', 'message' => 'I need the creditor name before I can change a voting rule.'];
        }

        $match = $this->matchCreditor($creditorQuery);
        if ($match['status'] !== 'matched') {
            return $match;
        }

        /** @var Creditor $creditor */
        $creditor = $match['creditor'];
        $existing = $this->overrideRow((int) $creditor->id, $ipKey);
        $workbook = $this->workbookBaseline((int) $creditor->id, $ipKey);

        if ($operation === 'revert') {
            if (!$existing) {
                return [
                    'status' => 'no_change',
                    'message' => (Lead::IVA_IPS[$ipKey] ?? $ipKey).' / '.$creditor->name.' is already using the workbook rule; there is no manual override to remove.',
                ];
            }

            $pending = [
                'operation' => 'revert',
                'ip_key' => $ipKey,
                'ip_label' => Lead::IVA_IPS[$ipKey] ?? $ipKey,
                'creditor_id' => (int) $creditor->id,
                'creditor_name' => $creditor->name,
                'source_message' => $sourceMessage,
                'before' => $this->overrideState($existing),
                'workbook' => $workbook,
            ];

            return [
                'status' => 'ready',
                'pending' => $pending,
                'message' => $this->confirmationText($pending),
            ];
        }

        $statusText = $this->normaliseStatusText($action['voting'] ?? $action['status'] ?? $action['status_text'] ?? null);
        if (!$statusText) {
            return [
                'status' => 'invalid',
                'message' => 'I could not safely map that voting result. Use Accept, Reject, Non-vote, Accept with conditions, Trial @ MOC, Referral, or Accept via house vote.',
            ];
        }

        $house = trim((string) ($action['voting_house'] ?? $action['house'] ?? ''));
        $house = $house !== '' ? $house : null;
        $notes = trim((string) ($action['notes'] ?? $action['condition_text'] ?? ''));
        $notes = $notes !== '' ? $notes : null;

        if ($statusText === 'Accept - via house vote' && !$house) {
            return [
                'status' => 'invalid',
                'message' => 'I understood this as Accept via house vote, but I need the voting house before I can save it.',
            ];
        }

        $newState = [
            'status_text' => $statusText,
            'outcome' => $this->voting->interpretStatus($statusText),
            'voting_house' => $house,
            'condition_text' => $notes,
        ];

        if ($existing && $this->statesEquivalent($this->overrideState($existing), $newState)) {
            return [
                'status' => 'no_change',
                'message' => (Lead::IVA_IPS[$ipKey] ?? $ipKey).' / '.$creditor->name.' is already set to '.$this->stateLabel($newState).'.',
            ];
        }

        $pending = [
            'operation' => 'set',
            'ip_key' => $ipKey,
            'ip_label' => Lead::IVA_IPS[$ipKey] ?? $ipKey,
            'creditor_id' => (int) $creditor->id,
            'creditor_name' => $creditor->name,
            'source_message' => $sourceMessage,
            'before' => $existing ? $this->overrideState($existing) : null,
            'workbook' => $workbook,
            'after' => $newState,
        ];

        return [
            'status' => 'ready',
            'pending' => $pending,
            'message' => $this->confirmationText($pending),
        ];
    }

    public function executePending(array $pending, ?int $userId, ?int $leadId, ?int $conversationId): array
    {
        $creditor = Creditor::findOrFail((int) $pending['creditor_id']);
        $ipKey = (string) $pending['ip_key'];

        if (($pending['operation'] ?? null) === 'revert') {
            return $this->revertOverride(
                $creditor,
                $ipKey,
                $userId,
                $leadId,
                $conversationId,
                (string) ($pending['source_message'] ?? ''),
                'assistant_chat'
            );
        }

        $after = $pending['after'] ?? [];

        return $this->setOverride(
            $creditor,
            $ipKey,
            (string) ($after['status_text'] ?? ''),
            $after['voting_house'] ?? null,
            $after['condition_text'] ?? null,
            $userId,
            $leadId,
            $conversationId,
            (string) ($pending['source_message'] ?? ''),
            'assistant_chat'
        );
    }

    public function setOverride(
        Creditor $creditor,
        string $ipKey,
        string $statusText,
        ?string $votingHouse,
        ?string $conditionText,
        ?int $userId,
        ?int $leadId,
        ?int $conversationId,
        string $sourceMessage,
        string $source = 'assistant_chat',
    ): array {
        $normalisedIp = $this->normaliseIpKey($ipKey);
        $normalisedStatus = $this->normaliseStatusText($statusText);

        if (!$normalisedIp || !$normalisedStatus) {
            throw new \InvalidArgumentException('Invalid IP or voting status.');
        }

        $votingHouse = filled($votingHouse) ? trim((string) $votingHouse) : null;
        $conditionText = filled($conditionText) ? trim((string) $conditionText) : null;

        if ($normalisedStatus === 'Accept - via house vote' && !$votingHouse) {
            throw new \InvalidArgumentException('Voting house is required for an Accept - via house vote rule.');
        }

        return DB::transaction(function () use (
            $creditor,
            $normalisedIp,
            $normalisedStatus,
            $votingHouse,
            $conditionText,
            $userId,
            $leadId,
            $conversationId,
            $sourceMessage,
            $source
        ) {
            $existing = $this->overrideRow((int) $creditor->id, $normalisedIp);
            $before = $existing ? $this->overrideState($existing) : null;

            $after = [
                'status_text' => $normalisedStatus,
                'outcome' => $this->voting->interpretStatus($normalisedStatus),
                'voting_house' => $votingHouse,
                'condition_text' => $conditionText,
            ];

            $values = [
                'status_text' => $after['status_text'],
                'outcome' => $after['outcome'],
                'voting_house' => $after['voting_house'],
                'condition_text' => $after['condition_text'],
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('ip_creditor_voting_overrides')->where('id', $existing->id)->update($values);
            } else {
                DB::table('ip_creditor_voting_overrides')->insert(array_merge($values, [
                    'creditor_id' => $creditor->id,
                    'ip_key' => $normalisedIp,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]));
            }

            $this->audit(
                (int) $creditor->id,
                $normalisedIp,
                $existing ? 'update' : 'set',
                $before,
                $after,
                $source,
                $sourceMessage,
                $conversationId,
                $leadId,
                $userId
            );

            return [
                'operation' => 'set',
                'ip_key' => $normalisedIp,
                'ip_label' => Lead::IVA_IPS[$normalisedIp] ?? $normalisedIp,
                'creditor_id' => (int) $creditor->id,
                'creditor_name' => $creditor->name,
                'state' => $after,
                'message' => 'Updated '.(Lead::IVA_IPS[$normalisedIp] ?? $normalisedIp).' / '.$creditor->name.' to '.$this->stateLabel($after).' for all cases.',
            ];
        });
    }

    public function revertOverride(
        Creditor $creditor,
        string $ipKey,
        ?int $userId,
        ?int $leadId,
        ?int $conversationId,
        string $sourceMessage,
        string $source = 'assistant_chat',
    ): array {
        $normalisedIp = $this->normaliseIpKey($ipKey);
        if (!$normalisedIp) {
            throw new \InvalidArgumentException('Invalid IP.');
        }

        return DB::transaction(function () use ($creditor, $normalisedIp, $userId, $leadId, $conversationId, $sourceMessage, $source) {
            $existing = $this->overrideRow((int) $creditor->id, $normalisedIp);

            if (!$existing) {
                return [
                    'operation' => 'revert',
                    'ip_key' => $normalisedIp,
                    'ip_label' => Lead::IVA_IPS[$normalisedIp] ?? $normalisedIp,
                    'creditor_id' => (int) $creditor->id,
                    'creditor_name' => $creditor->name,
                    'message' => 'No manual override existed, so nothing was changed.',
                ];
            }

            $before = $this->overrideState($existing);
            DB::table('ip_creditor_voting_overrides')->where('id', $existing->id)->delete();

            $this->audit(
                (int) $creditor->id,
                $normalisedIp,
                'revert',
                $before,
                null,
                $source,
                $sourceMessage,
                $conversationId,
                $leadId,
                $userId
            );

            $workbook = $this->workbookBaseline((int) $creditor->id, $normalisedIp);

            return [
                'operation' => 'revert',
                'ip_key' => $normalisedIp,
                'ip_label' => Lead::IVA_IPS[$normalisedIp] ?? $normalisedIp,
                'creditor_id' => (int) $creditor->id,
                'creditor_name' => $creditor->name,
                'workbook' => $workbook,
                'message' => 'Reverted '.(Lead::IVA_IPS[$normalisedIp] ?? $normalisedIp).' / '.$creditor->name.' to the workbook rule: '.$this->stateLabel($workbook).'.',
            ];
        });
    }

    public function matchCreditor(string $query): array
    {
        $needle = $this->normaliseCreditorName($query);
        if ($needle === '') {
            return ['status' => 'not_found', 'message' => 'I could not match that creditor name.'];
        }

        $creditors = DB::table('creditors')->get(['id', 'name']);
        $aliases = DB::table('creditor_aliases')->get(['creditor_id', 'alias']);

        $matches = [];

        foreach ($creditors as $creditor) {
            if ($this->normaliseCreditorName((string) $creditor->name) === $needle) {
                $matches[(int) $creditor->id] = (string) $creditor->name;
            }
        }

        foreach ($aliases as $alias) {
            if ($this->normaliseCreditorName((string) $alias->alias) === $needle) {
                $name = $creditors->firstWhere('id', $alias->creditor_id)?->name;
                if ($name) {
                    $matches[(int) $alias->creditor_id] = (string) $name;
                }
            }
        }

        if (count($matches) === 1) {
            $id = (int) array_key_first($matches);
            return ['status' => 'matched', 'creditor' => Creditor::findOrFail($id)];
        }

        if (count($matches) > 1) {
            return [
                'status' => 'ambiguous',
                'message' => 'I found more than one exact creditor match: '.implode(', ', array_values($matches)).'. Please name the exact creditor.',
                'candidates' => array_values($matches),
            ];
        }

        foreach ($creditors as $creditor) {
            $candidate = $this->normaliseCreditorName((string) $creditor->name);
            if ($candidate !== '' && (str_contains($candidate, $needle) || str_contains($needle, $candidate))) {
                $matches[(int) $creditor->id] = (string) $creditor->name;
            }
        }

        foreach ($aliases as $alias) {
            $candidate = $this->normaliseCreditorName((string) $alias->alias);
            if ($candidate !== '' && (str_contains($candidate, $needle) || str_contains($needle, $candidate))) {
                $name = $creditors->firstWhere('id', $alias->creditor_id)?->name;
                if ($name) {
                    $matches[(int) $alias->creditor_id] = (string) $name;
                }
            }
        }

        if (count($matches) === 1) {
            $id = (int) array_key_first($matches);
            return ['status' => 'matched', 'creditor' => Creditor::findOrFail($id)];
        }

        if (count($matches) > 1) {
            $candidates = array_slice(array_values($matches), 0, 8);
            return [
                'status' => 'ambiguous',
                'message' => 'I found several possible creditors: '.implode(', ', $candidates).'. Please tell me which one you mean.',
                'candidates' => $candidates,
            ];
        }

        return [
            'status' => 'not_found',
            'message' => 'I could not find a creditor matching “'.$query.'” in the Jinx creditor list or aliases.',
        ];
    }

    private function workbookBaseline(int $creditorId, string $ipKey): array
    {
        $rows = DB::table('decision_creditor_source_rows')
            ->where('creditor_id', $creditorId)
            ->where('partner_key', $ipKey)
            ->get(['status_text', 'detail_text', 'representative_key', 'source_name', 'sheet', 'source_row']);

        if ($rows->isEmpty()) {
            return [
                'status_text' => null,
                'outcome' => 'missing',
                'voting_house' => null,
                'condition_text' => null,
                'source' => 'No workbook criteria',
            ];
        }

        $statuses = $rows->pluck('status_text')->filter(fn ($value) => filled($value))->map(fn ($value) => trim((string) $value))->unique()->values();
        $houses = $rows->pluck('representative_key')->filter(fn ($value) => filled($value))->map(fn ($value) => trim((string) $value))->unique()->values();
        $notes = $rows->pluck('detail_text')->filter(fn ($value) => filled($value))->map(fn ($value) => trim((string) $value))->unique()->values();

        $statusText = $statuses->count() === 1 ? $statuses->first() : ($statuses->count() > 1 ? $statuses->implode(' / ') : null);
        $house = $houses->count() === 1 ? $houses->first() : null;

        $outcomes = $statuses->map(fn ($status) => $this->voting->interpretStatus((string) $status))->unique()->values();
        if ($statuses->isEmpty() && $house) {
            $outcome = 'accept';
        } elseif ($outcomes->count() === 1) {
            $outcome = (string) $outcomes->first();
        } else {
            $outcome = 'unknown';
        }

        return [
            'status_text' => $statusText,
            'outcome' => $outcome,
            'voting_house' => $house,
            'condition_text' => $notes->isNotEmpty() ? $notes->implode(' | ') : null,
            'source' => trim((string) ($rows->first()->source_name ?? 'Workbook')),
        ];
    }

    private function overrideRow(int $creditorId, string $ipKey): ?object
    {
        return DB::table('ip_creditor_voting_overrides')
            ->where('creditor_id', $creditorId)
            ->where('ip_key', $ipKey)
            ->first();
    }

    private function overrideState(object $row): array
    {
        return [
            'status_text' => (string) $row->status_text,
            'outcome' => $this->voting->interpretStatus((string) $row->status_text),
            'voting_house' => filled($row->voting_house) ? (string) $row->voting_house : null,
            'condition_text' => filled($row->condition_text) ? (string) $row->condition_text : null,
        ];
    }

    private function confirmationText(array $pending): string
    {
        $ip = $pending['ip_label'];
        $creditor = $pending['creditor_name'];

        if (($pending['operation'] ?? null) === 'revert') {
            $before = $this->stateLabel($pending['before'] ?? null);
            $workbook = $this->stateLabel($pending['workbook'] ?? null);

            return $ip.' / '.$creditor.' currently has a manual override of '.$before.'. Remove it and return all '.$ip.' cases to the workbook rule ('.$workbook.')? Say yes or no.';
        }

        $current = $pending['before']
            ? 'manual override '.$this->stateLabel($pending['before'])
            : 'workbook rule '.$this->stateLabel($pending['workbook'] ?? null);
        $after = $this->stateLabel($pending['after'] ?? null);

        return $ip.' / '.$creditor.' currently uses '.$current.'. Change it to '.$after.' for all '.$ip.' cases? The original workbook data will stay untouched. Say yes or no.';
    }

    private function stateLabel(?array $state): string
    {
        if (!$state) {
            return 'no rule';
        }

        $status = trim((string) ($state['status_text'] ?? ''));
        $outcome = (string) ($state['outcome'] ?? 'unknown');
        $house = trim((string) ($state['voting_house'] ?? ''));
        $notes = trim((string) ($state['condition_text'] ?? ''));

        if ($status === '') {
            $status = match ($outcome) {
                'accept' => 'Accept',
                'reject' => 'Reject',
                'non_voting' => 'Non-vote',
                'missing' => 'no workbook criteria',
                default => 'Review',
            };
        }

        if ($house !== '') {
            $status .= ' via '.$house;
        }

        if ($notes !== '') {
            $status .= ' — '.$notes;
        }

        return $status;
    }

    private function statesEquivalent(array $a, array $b): bool
    {
        foreach (['status_text', 'outcome', 'voting_house', 'condition_text'] as $key) {
            if (trim((string) ($a[$key] ?? '')) !== trim((string) ($b[$key] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    private function audit(
        int $creditorId,
        string $ipKey,
        string $action,
        ?array $before,
        ?array $after,
        string $source,
        string $sourceMessage,
        ?int $conversationId,
        ?int $leadId,
        ?int $userId,
    ): void {
        DB::table('ip_creditor_voting_override_audits')->insert([
            'creditor_id' => $creditorId,
            'ip_key' => $ipKey,
            'action' => $action,
            'before_state' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'after_state' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'source' => $source,
            'source_message' => $sourceMessage !== '' ? $sourceMessage : null,
            'assistant_conversation_id' => $conversationId,
            'lead_id' => $leadId,
            'changed_by' => $userId,
            'created_at' => now(),
        ]);
    }

    private function normaliseCreditorName(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = str_replace(['&', '–', '—'], ['and', '-', '-'], $value);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }
}
