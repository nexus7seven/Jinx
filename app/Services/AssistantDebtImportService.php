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

        $added = [];
        $unresolved = [];
        foreach ($items as $item) {
            $match = $this->matchCreditor($item['creditor']);
            if ($match['status'] === 'matched') {
                $this->addDebt($lead, $match['creditor'], $item['balance']);
                $this->rememberAlias($match['creditor'], $item['creditor']);
                $added[] = $item;
            } else {
                $unresolved[] = $item;
            }
        }

        if ($unresolved === []) {
            return ['handled' => true, 'pending' => null, 'reply' => $this->doneReply(count($added), $items)];
        }

        $pending = ['remaining' => array_values($unresolved), 'added_count' => count($added), 'current' => array_shift($unresolved), 'stage' => 'voting_house'];
        $pending['remaining'] = array_values($unresolved);
        return ['handled' => true, 'pending' => $pending, 'reply' => $this->question($pending)];
    }

    public function continue(Lead $lead, array $pending, string $answer): array
    {
        $current = $pending['current'] ?? null;
        if (!is_array($current)) return ['pending' => null, 'reply' => 'The pending debt import could not be resumed. Please paste the debts again.'];

        if (($pending['stage'] ?? '') === 'voting_house') {
            $value = trim($answer);
            if ($value === '') return ['pending' => $pending, 'reply' => $this->question($pending)];
            $pending['voting_house'] = $value;
            $pending['stage'] = 'voting_practice1';
            return ['pending' => $pending, 'reply' => $this->question($pending)];
        }

        if (($pending['stage'] ?? '') === 'voting_practice1') {
            $practice = $this->normalisePractice($answer);
            if ($practice === null) return ['pending' => $pending, 'reply' => 'For '.$current['creditor'].', what should voting_practice1 be? Enter accept, reject or non_vote.'];

            $creditor = Creditor::create([
                'name' => $current['creditor'],
                'voting_house' => $pending['voting_house'],
                'voting_practice1' => $practice,
            ]);
            $this->rememberAlias($creditor, $current['creditor']);
            $this->addDebt($lead, $creditor, (float) $current['balance']);
            $pending['added_count'] = ((int) ($pending['added_count'] ?? 0)) + 1;

            $remaining = $pending['remaining'] ?? [];
            if ($remaining === []) {
                return ['pending' => null, 'reply' => 'Done. Added '.((int) $pending['added_count']).' debt'.(((int) $pending['added_count']) === 1 ? '' : 's').' to this client.'];
            }

            $pending = ['remaining' => array_values(array_slice($remaining, 1)), 'added_count' => $pending['added_count'], 'current' => $remaining[0], 'stage' => 'voting_house'];
            return ['pending' => $pending, 'reply' => $this->question($pending)];
        }

        return ['pending' => null, 'reply' => 'The pending debt import could not be resumed. Please paste the debts again.'];
    }

    private function parse(string $message): array
    {
        $items = [];
        foreach (preg_split('/\R/', $message) ?: [] as $line) {
            $line = trim(preg_replace('/^[\s\-*•]+/u', '', $line) ?? $line);
            if (!preg_match('/^(.+?)\s*(?:—|–|-)\s*£\s*([\d,]+(?:\.\d{1,2})?)/u', $line, $m)) continue;
            $name = trim($m[1]);
            if ($name === '') continue;
            $items[] = ['creditor' => $name, 'balance' => (float) str_replace(',', '', $m[2])];
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
                $normal = $this->normalise((string) $candidate);
                if ($normal === $needle) return ['status' => 'matched', 'creditor' => $creditor];
                similar_text($needle, $normal, $pct);
                if (str_contains($needle, $normal) || str_contains($normal, $needle)) $pct = max($pct, 92.0);
                if ($pct > $bestScore) { $second = $bestScore; $bestScore = $pct; $best = $creditor; }
                elseif ($pct > $second) $second = $pct;
            }
        }

        // Conservative automatic fuzzy match. Anything uncertain is treated as a new creditor
        // rather than silently attaching a client's debt to the wrong creditor.
        if ($best && $bestScore >= 88.0 && ($bestScore - $second) >= 8.0) return ['status' => 'matched', 'creditor' => $best];
        return ['status' => 'unmatched'];
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

    private function normalisePractice(string $answer): ?string
    {
        $value = Str::lower(trim($answer));
        return match ($value) {
            'accept', 'accepted', 'yes', 'approve', 'approved' => 'accept',
            'reject', 'rejected', 'no', 'decline', 'declined' => 'reject',
            'non_vote', 'non-vote', 'non vote', 'no vote', 'novote' => 'non_vote',
            default => null,
        };
    }

    private function question(array $pending): string
    {
        $name = $pending['current']['creditor'] ?? 'this creditor';
        if (($pending['stage'] ?? '') === 'voting_house') return 'I can’t find a creditor matching “'.$name.'”. What voting house should I use for the new creditor?';
        return 'For '.$name.', what should voting_practice1 be? Enter accept, reject or non_vote.';
    }

    private function doneReply(int $count, array $items): string
    {
        $total = array_sum(array_column($items, 'balance'));
        return 'Done. Added '.$count.' debt'.($count === 1 ? '' : 's').' to this client, totalling £'.number_format($total, 2).'.';
    }
}
