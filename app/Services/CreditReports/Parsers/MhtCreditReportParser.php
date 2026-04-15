<?php

namespace App\Services\CreditReports\Parsers;

class MhtCreditReportParser implements CreditReportParserInterface
{
    public function parse(string $absolutePath): array
    {
        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            return [
                'text' => '',
                'debts' => [],
                'county_court_judgments' => [],
            ];
        }

        $text = $this->extractTextFromMht($raw);

        return [
            'text' => $text,
            'debts' => $this->extractDebts($text),
            'county_court_judgments' => [],
        ];
    }

    private function extractTextFromMht(string $raw): string
    {
        $decodedRaw = quoted_printable_decode($raw);
        $htmlParts = [];

        if (preg_match_all('/Content-Type:\s*text\/html.*?\R\R(.*?)(?=\R--|$)/is', $decodedRaw, $matches)) {
            foreach ($matches[1] as $part) {
                $htmlParts[] = trim($part);
            }
        }

        if (!$htmlParts && preg_match_all('/<html\b.*?<\/html>/is', $decodedRaw, $matches)) {
            foreach ($matches[0] as $part) {
                $htmlParts[] = trim($part);
            }
        }

        $textChunks = [];
        foreach ($htmlParts as $html) {
            $html = preg_replace('/=\r?\n/', '', $html);
            $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
            $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);

            $text = strip_tags($html);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace("/\r\n|\r/", "\n", $text);
            $text = preg_replace("/[ \t]+/", ' ', $text);
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
            $text = trim($text);

            if ($text !== '') {
                $textChunks[] = $text;
            }
        }

        return trim(implode("\n\n", $textChunks));
    }

    /**
     * Reuses the existing MHT account block heuristic.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractDebts(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $debts = [];
        $lines = array_values(array_filter(array_map('trim', preg_split("/\n/", $text))));

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $next1 = $lines[$i + 1] ?? '';
            $next2 = $lines[$i + 2] ?? '';
            $next3 = $lines[$i + 3] ?? '';

            if (! $this->isLikelyAccountName($line)) {
                continue;
            }

            if (! preg_match('/^£\s*([0-9][0-9,]*(?:\.\d{2})?)$/i', $next1, $balMatch)) {
                continue;
            }

            if (! $this->isLikelyDateLine($next2)) {
                continue;
            }

            if (! $this->isLikelyAccountStatus($next3)) {
                continue;
            }

            $balance = (float) str_replace(',', '', $balMatch[1]);
            if ($balance <= 0) {
                continue;
            }

            $debts[] = [
                'creditor' => trim($line),
                'account_type' => null,
                'balance' => $balance,
                'status' => trim($next3),
                'account_start_date' => null,
                'default_date' => null,
                'default_balance' => null,
                'regular_payment' => null,
                'repayment_frequency' => null,
                'account_number' => null,
                'updated_date' => trim($next2),
            ];
        }

        return $debts;
    }

    private function isLikelyAccountName(string $line): bool
    {
        $line = trim($line);
        $lower = mb_strtolower($line);

        if ($line === '' || mb_strlen($line) < 3 || mb_strlen($line) > 80) {
            return false;
        }

        $bad = [
            'organisation',
            'balance',
            'updated',
            'status',
            'account number',
            'payment history',
            'credit limit',
            'default balance',
            'opened',
            'started',
            'name',
            'address',
            'court name',
            'judgment date',
            'amount',
            'type',
            'public information',
            'other accounts',
            'bankruptcies',
            'insolvencies',
            'judgments',
            'case number',
        ];

        foreach ($bad as $badLine) {
            if ($lower === $badLine || str_starts_with($lower, $badLine)) {
                return false;
            }
        }

        if (preg_match('/^£/', $line)) {
            return false;
        }

        if (preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}$/', $line)) {
            return false;
        }

        if (preg_match('/^\d{1,2}\s+[A-Za-z]{3}\s+\d{4}$/', $line)) {
            return false;
        }

        if (preg_match('/^[A-Z0-9]{6,10}$/', $line)) {
            return false;
        }

        return (bool) preg_match('/[A-Za-z]/', $line);
    }

    private function isLikelyDateLine(string $line): bool
    {
        $line = trim($line);

        return (bool) preg_match('/^\d{1,2}\s+[A-Za-z]{3}\s+\d{4}$/', $line)
            || (bool) preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}$/', $line);
    }

    private function isLikelyAccountStatus(string $line): bool
    {
        $line = mb_strtolower(trim($line));

        return in_array($line, [
            'up to date',
            'default',
            'delinquent',
            'settled',
            'satisfied',
            'partially settled',
            'late payment',
            'active',
            'arrangement',
            'payment holiday',
        ], true);
    }
}
