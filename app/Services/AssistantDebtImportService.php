<?php

namespace App\Services;

use App\Models\Creditor;
use App\Models\CreditorAlias;
use App\Models\Debt;
use App\Models\Lead;
use Illuminate\Support\Str;

class AssistantDebtImportService
{
    public function looksLikeImport(string $message): bool
    {
        return preg_match('/\b(?:add|import|put|record)\b.*\bdebts?\b/is', $message) === 1
            && preg_match('/£\s*[\d,]+(?:\.\d{1,2})?/', $message) === 1;
    }

    public function begin(Lead $lead, string $message): array
    {
        $items = $this->parse($message);
        if ($items === []) return ['handled' => false];
        $pending = ['remaining' => array_values($items), 'added_count' => 0, 'added_total' => 0.0];
        $result = $this->processNext($lead, $pending);
        return ['handled' => true, 'pending' => $result['pending'], 'reply' => $result['reply']];
    }

    public function continue(Lead $lead, array $pending, string $answer): array
    {
        $current = $pending['current'] ?? null;
        if (!is_array($current)) return ['pending' => null, 'reply' => 'The pending debt import could not be resumed. Please paste the debts again.'];

        if (($pending['stage'] ?? '') === 'duplicate_confirmation') {
            $yes = $this->yesNo($answer);
            if ($yes === null) return ['pending' => $pending, 'reply' => $this->question($pending)];
            if ($yes) {
                $creditor = Creditor::find($pending['creditor_id'] ?? null);
                if ($creditor) $this->addAndCount($lead, $creditor, $current, $pending);
            }
            unset($pending['current'], $pending['stage'], $pending['creditor_id']);
            return $this->processNext($lead, $pending);
        }

        if (($pending['stage'] ?? '') === 'creditor_resolution') {
            $value = trim($answer);
            if ($value === '') return ['pending' => $pending, 'reply' => $this->question($pending)];

            if ($this->wantsNewCreditor($value)) {
                $pending['stage'] = 'voting_house';
                return ['pending' => $pending, 'reply' => $this->question($pending)];
            }

            $match = $this->matchCreditor($value);
            if ($match['status'] !== 'matched') {
                return ['pending' => $pending, 'reply' => 'I still can’t find an existing creditor matching “'.$value.'”. Tell me the existing creditor name, or say “add new” to create “'.$current['creditor'].'”.'];
            }

            $creditor = $match['creditor'];
            $this->rememberAlias($creditor, $current['creditor']);

            if ($this->clientAlreadyHasDebt($lead, $creditor, (float)$current['balance'])) {
                $pending['creditor_id'] = $creditor->id;
                $pending['stage'] = 'duplicate_confirmation';
                return ['pending' => $pending, 'reply' => $this->question($pending)];
            }

            $this->addAndCount($lead, $creditor, $current, $pending);
            unset($pending['current'], $pending['stage'], $pending['creditor_id']);
            return $this->processNext($lead, $pending);
        }

        if (($pending['stage'] ?? '') === 'voting_house') {
            $value = trim($answer);
            if ($value === '') return ['pending' => $pending, 'reply' => $this->question($pending)];
            $pending['voting_house'] = $value;
            $pending['stage'] = 'voting_practice1';
            return ['pending' => $pending, 'reply' => $this->question($pending)];
        }

        if (($pending['stage'] ?? '') === 'voting_practice1') {
            $practice = $this->normalisePractice($answer);
            if ($practice === null) return ['pending' => $pending, 'reply' => $this->question($pending)];
            $creditor = Creditor::create([
                'name' => $current['creditor'],
                'voting_house' => $pending['voting_house'],
                'voting_practice1' => $practice,
            ]);
            $this->rememberAlias($creditor, $current['creditor']);
            $this->addAndCount($lead, $creditor, $current, $pending);
            unset($pending['current'], $pending['stage'], $pending['voting_house']);
            return $this->processNext($lead, $pending);
        }

        return ['pending' => null, 'reply' => 'The pending debt import could not be resumed. Please paste the debts again.'];
    }

    private function processNext(Lead $lead, array $pending): array
    {
        $remaining = array_values($pending['remaining'] ?? []);
        while ($remaining !== []) {
            $item = array_shift($remaining);
            $pending['remaining'] = $remaining;
            $match = $this->matchCreditor($item['creditor']);

            if ($match['status'] !== 'matched') {
                $pending['current'] = $item;
                $pending['stage'] = 'creditor_resolution';
                return ['pending' => $pending, 'reply' => $this->question($pending)];
            }

            $creditor = $match['creditor'];
            $this->rememberAlias($creditor, $item['creditor']);
            if ($this->clientAlreadyHasDebt($lead, $creditor, (float)$item['balance'])) {
                $pending['current'] = $item;
                $pending['creditor_id'] = $creditor->id;
                $pending['stage'] = 'duplicate_confirmation';
                return ['pending' => $pending, 'reply' => $this->question($pending)];
            }
            $this->addAndCount($lead, $creditor, $item, $pending);
        }
        return ['pending' => null, 'reply' => $this->doneReply((int)($pending['added_count'] ?? 0), (float)($pending['added_total'] ?? 0))];
    }

    private function parse(string $message): array
    {
        $items = [];
        $body = preg_replace('/^.*?\bdebts?\b\s*[-:]*\s*/is', '', trim($message), 1) ?? trim($message);
        $pattern = '/(?:^|\s+-\s+|\R|[•*]\s*)(.+?)\s*(?:—|–|:|\s-\s)\s*£\s*([\d,]+(?:\.\d{1,2})?)(?:\s*\([^)]*\))?/u';
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = trim(preg_replace('/^[\s\-*•]+/u', '', $m[1]) ?? $m[1]);
                if ($name === '') continue;
                $items[] = ['creditor' => $name, 'balance' => (float) str_replace(',', '', $m[2])];
            }
        }
        if ($items === []) {
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                $line = trim(preg_replace('/^[\s\-*•]+/u', '', $line) ?? $line);
                if (!preg_match('/^(.+?)\s*(?:—|–|:|-)\s*£\s*([\d,]+(?:\.\d{1,2})?)/u', $line, $m)) continue;
                $items[] = ['creditor' => trim($m[1]), 'balance' => (float) str_replace(',', '', $m[2])];
            }
        }
        return $items;
    }

    private function matchCreditor(string $supplied): array
    {
        $needle = $this->normalise($supplied);
        $creditors = Creditor::with('aliases')->get();
        $best = null; $bestScore = 0.0; $second = 0.0;
        foreach ($creditors as $creditor) {
            $candidates = array_merge([$creditor->name], $creditor->aliases->pluck('alias')->all());
            foreach ($candidates as $candidate) {
                $normal = $this->normalise((string)$candidate);
                if ($normal === $needle) return ['status' => 'matched', 'creditor' => $creditor];
                similar_text($needle, $normal, $pct);
                if ($normal !== '' && (str_contains($needle, $normal) || str_contains($normal, $needle))) $pct = max($pct, 92.0);
                if ($pct > $bestScore) { $second = $bestScore; $bestScore = $pct; $best = $creditor; }
                elseif ($pct > $second) $second = $pct;
            }
        }
        if ($best && $bestScore >= 88.0 && ($bestScore - $second) >= 8.0) return ['status' => 'matched', 'creditor' => $best];
        return ['status' => 'unmatched'];
    }

    private function clientAlreadyHasDebt(Lead $lead, Creditor $creditor, float $balance): bool
    {
        // A duplicate means the same client already has the same canonical creditor AND
        // the same balance. Multiple accounts with one creditor but different balances are valid.
        return Debt::query()
            ->where('lead_id', $lead->id)
            ->where('creditor_id', $creditor->id)
            ->where('balance', round($balance, 2))
            ->exists();
    }

    private function addAndCount(Lead $lead, Creditor $creditor, array $item, array &$pending): void
    {
        $this->addDebt($lead, $creditor, (float)$item['balance']);
        $pending['added_count'] = ((int)($pending['added_count'] ?? 0)) + 1;
        $pending['added_total'] = ((float)($pending['added_total'] ?? 0)) + (float)$item['balance'];
    }

    private function normalise(string $value): string
    {
        $value = Str::ascii(Str::lower($value));
        $value = preg_replace('/\b(?:limited|ltd|plc|bank|finance|company|co|uk)\b/', ' ', $value) ?? $value;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value);
    }

    private function rememberAlias(Creditor $creditor, string $supplied): void
    {
        $normalized = $this->normalise($supplied);
        if ($normalized === '') return;
        CreditorAlias::firstOrCreate(['creditor_id' => $creditor->id, 'normalized_alias' => $normalized], ['alias' => trim($supplied)]);
    }

    private function addDebt(Lead $lead, Creditor $creditor, float $balance): void
    {
        Debt::create(['lead_id' => $lead->id, 'creditor_id' => $creditor->id, 'balance' => $balance, 'source_expected' => 'other', 'reference' => null]);
    }

    private function yesNo(string $answer): ?bool
    {
        return match (Str::lower(trim($answer))) {
            'yes', 'y', 'yeah', 'yep', 'duplicate', 'add it', 'add' => true,
            'no', 'n', 'nope', 'skip', 'skip it', 'dont', "don't" => false,
            default => null,
        };
    }

    private function wantsNewCreditor(string $answer): bool
    {
        return in_array(Str::lower(trim($answer)), ['add new', 'new', 'create new', 'new creditor', 'add as new', 'create'], true);
    }

    private function normalisePractice(string $answer): ?string
    {
        return match (Str::lower(trim($answer))) {
            'accept', 'accepted', 'yes', 'approve', 'approved' => 'accept',
            'reject', 'rejected', 'no', 'decline', 'declined' => 'reject',
            'non_vote', 'non-vote', 'non vote', 'no vote', 'novote' => 'non_vote',
            default => null,
        };
    }

    private function question(array $pending): string
    {
        $current = $pending['current'] ?? [];
        $name = $current['creditor'] ?? 'this creditor';
        if (($pending['stage'] ?? '') === 'duplicate_confirmation') {
            return 'This client already has a debt with '.$name.' for £'.number_format((float)($current['balance'] ?? 0), 2).'. Do you want to add this duplicate debt anyway? Yes or no.';
        }
        if (($pending['stage'] ?? '') === 'creditor_resolution') {
            return 'I can’t confidently match “'.$name.'” to an existing creditor. If it belongs under an existing creditor, tell me that creditor name and I’ll link it and save “'.$name.'” as an alias for future imports. Otherwise say “add new”.';
        }
        if (($pending['stage'] ?? '') === 'voting_house') return 'What voting house should I use for the new creditor “'.$name.'”?';
        return 'For '.$name.', what should voting_practice1 be? Enter accept, reject or non_vote.';
    }

    private function doneReply(int $count, float $total): string
    {
        return 'Done. Added '.$count.' debt'.($count === 1 ? '' : 's').' to this client, totalling £'.number_format($total, 2).'.';
    }
}
