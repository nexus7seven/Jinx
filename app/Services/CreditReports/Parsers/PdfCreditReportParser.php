<?php

namespace App\Services\CreditReports\Parsers;

use RuntimeException;
use Smalot\PdfParser\Parser;

class PdfCreditReportParser implements CreditReportParserInterface
{
    public function __construct(private readonly Parser $parser) {}

    public function parse(string $absolutePath): array
    {
        try {
            $document = $this->parser->parseFile($absolutePath);
            $text = trim($document->getText());
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not read PDF credit report text.', 0, $e);
        }

        return $this->parseText($text);
    }

    /**
     * @return array{
     *   text:string,
     *   debts:array<int, array<string, mixed>>,
     *   county_court_judgments:array<int, array<string, mixed>>
     * }
     */
    public function parseText(string $text): array
    {
        $normalizedText = $this->normalizeText($text);
        if ($normalizedText === '') {
            return [
                'text' => '',
                'debts' => [],
                'county_court_judgments' => [],
            ];
        }

        $lines = $this->lines($normalizedText);
        $financialLines = $this->sliceBetween($lines, 'Financial Account Information', 'Search History');
        $judgmentLines = $this->sliceBetween($lines, 'Public Information', 'Notices of Correction');

        return [
            'text' => $normalizedText,
            'debts' => $this->extractDebts($financialLines),
            'county_court_judgments' => $this->extractJudgments($judgmentLines),
        ];
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split("/\n/u", $text)), static fn ($line) => $line !== ''));
    }

    /**
     * TransUnion PDFs often wrap long organisation names: the first line has no £, the next line
     * holds "…Retail Limited £amount date status". Both extractAccountHeader (inline match on the
     * second line) and the two-line join (at the first index) would otherwise emit two headers,
     * the second with a fragment creditor (e.g. "Retail Limited", "Card LTD").
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function stitchSplitAccountSummaryLines(array $lines): array
    {
        $n = count($lines);
        if ($n < 2) {
            return $lines;
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i + 1 < $n && $this->isCreditorContinuationLine($lines[$i])) {
                $combined = trim($lines[$i].' '.$lines[$i + 1]);
                $merged = $this->parseAccountSummaryLine($combined);
                $nextAlone = $this->parseAccountSummaryLine(trim($lines[$i + 1]));
                if ($merged !== null && $nextAlone !== null
                    && ($merged['creditor'] ?? '') !== ($nextAlone['creditor'] ?? '')) {
                    $out[] = $combined;
                    $i++;

                    continue;
                }
            }

            $out[] = $lines[$i];
        }

        return $out;
    }

    /**
     * First line of a split TransUnion account summary: looks like a creditor fragment, no £ yet.
     */
    private function isCreditorContinuationLine(string $line): bool
    {
        if (str_contains($line, '£')) {
            return false;
        }

        if (! $this->isLikelyAccountName($line)) {
            return false;
        }

        $lower = mb_strtolower(trim($line));

        foreach ([
            'credit cards',
            'personal loans and mortgages',
            'other accounts',
            'financial account information',
        ] as $heading) {
            if ($lower === $heading) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function extractDebts(array $lines): array
    {
        $lines = $this->stitchSplitAccountSummaryLines($lines);
        $debts = [];
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $meta = $this->extractAccountHeaderMeta($lines, $i);
            if ($meta === null) {
                continue;
            }

            $header = $meta['header'];
            $consumed = $meta['consumed'];

            $name = $header['creditor'];
            $balance = $header['balance'];
            if ($name === '' || $balance === null) {
                continue;
            }

            $status = $header['status'];
            // Search after every line this header consumed; otherwise the £ line of a split summary
            // is mistaken for the next account (duplicate fragment creditors).
            $nextHeader = $this->findNextAccountHeader($lines, $i + $consumed);
            $blockEnd = $nextHeader === null ? min($lineCount - 1, $i + 80) : min($nextHeader - 1, $i + 80);

            $debts[] = [
                'creditor' => $name,
                'account_type' => $this->extractLabeledValue($lines, $i, $blockEnd, 'Account type'),
                'balance' => $balance,
                'status' => $status,
                'account_start_date' => $this->extractLabeledDate($lines, $i, $blockEnd, 'Account start date'),
                'default_date' => $this->extractLabeledDate($lines, $i, $blockEnd, 'Date of default'),
                'default_balance' => $this->extractLabeledCurrency($lines, $i, $blockEnd, 'Default balance'),
                'regular_payment' => $this->extractLabeledCurrency($lines, $i, $blockEnd, 'Regular payment'),
                'repayment_frequency' => $this->extractLabeledValue($lines, $i, $blockEnd, 'Repayment frequency'),
                'account_number' => $this->extractLabeledValue($lines, $i, $blockEnd, 'Account number'),
                'updated_date' => $header['updated_date'],
            ];

            if ($nextHeader !== null) {
                $i = max($i, $nextHeader - 1);
            } else {
                $i += $consumed - 1;
            }
        }

        return $debts;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function extractJudgments(array $lines): array
    {
        $judgments = [];
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $triplet = $this->extractJudgmentTriplet($lines, $i);
            if ($triplet === null) {
                continue;
            }

            $caseNumber = $triplet['case_number'];
            $blockEnd = min($lineCount - 1, $i + 40);
            $status = $triplet['status'];
            $type = $triplet['type'];

            $amount = $this->extractLabeledCurrency($lines, $i, $blockEnd, 'Amount');
            if ($amount === null) {
                $amount = $this->findFirstCurrencyInWindow($lines, $i, $blockEnd);
            }

            if ($amount === null || $amount <= 0) {
                continue;
            }

            $judgments[] = [
                'case_number' => $caseNumber,
                'type' => $type,
                'status' => $status,
                'judgment_date' => $this->extractLabeledDate($lines, $i, $blockEnd, 'Judgment date'),
                'amount' => $amount,
                'court_name' => $this->extractLabeledValue($lines, $i, $blockEnd, 'Court name'),
                'name_or_address' => $this->extractLabeledValue($lines, $i, $blockEnd, 'Address'),
            ];
        }

        return $judgments;
    }

    /**
     * @param array<int, string> $lines
     */
    private function findFirstCurrencyInWindow(array $lines, int $start, int $end): ?float
    {
        $upper = min(count($lines) - 1, $end);
        for ($i = max(0, $start); $i <= $upper; $i++) {
            $value = $this->parseCurrency($lines[$i]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     */
    private function findStatusInWindow(array $lines, int $start, int $end): ?string
    {
        $knownStatuses = [
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
        ];

        $upper = min(count($lines) - 1, $end);
        for ($i = max(0, $start); $i <= $upper; $i++) {
            $line = mb_strtolower(trim($lines[$i]));
            if (in_array($line, $knownStatuses, true)) {
                return $lines[$i];
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     */
    private function findNextAccountHeader(array $lines, int $start): ?int
    {
        for ($i = $start; $i < count($lines); $i++) {
            if ($this->extractAccountHeader($lines, $i) !== null) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     */
    private function extractLabeledValue(array $lines, int $start, int $end, string $label): ?string
    {
        $escaped = preg_quote($label, '/');
        $upper = min(count($lines) - 1, $end);
        for ($i = max(0, $start); $i <= $upper; $i++) {
            $line = $lines[$i];
            if (preg_match('/^'.$escaped.'\s*:?\s*(.+)$/i', $line, $m)) {
                return trim($m[1]);
            }

            if (strcasecmp($line, $label) === 0) {
                $next = $lines[$i + 1] ?? null;
                if ($next !== null && $next !== '') {
                    return trim($next);
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     */
    private function extractLabeledCurrency(array $lines, int $start, int $end, string $label): ?float
    {
        $value = $this->extractLabeledValue($lines, $start, $end, $label);
        if ($value === null) {
            return null;
        }

        return $this->parseCurrency($value);
    }

    /**
     * @param array<int, string> $lines
     */
    private function extractLabeledDate(array $lines, int $start, int $end, string $label): ?string
    {
        $value = $this->extractLabeledValue($lines, $start, $end, $label);
        if ($value === null) {
            return null;
        }

        return $this->normalizeDate($value);
    }

    private function parseCurrency(string $input): ?float
    {
        if (! preg_match('/£\s*([0-9][0-9,]*(?:\.\d{2})?)/i', $input, $m)) {
            return null;
        }

        return (float) str_replace(',', '', $m[1]);
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        $formats = ['j M Y', 'd M Y', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y'];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function isLikelyAccountName(string $line): bool
    {
        $line = trim($line);
        $lower = mb_strtolower($line);

        if ($line === '' || mb_strlen($line) < 3 || mb_strlen($line) > 90) {
            return false;
        }

        $disallowed = [
            'organisation',
            'balance',
            'updated',
            'status',
            'account number',
            'account type',
            'public information',
            'judgments',
            'case number',
            'financial accounts',
            'name',
            'address',
            'date of birth',
        ];

        foreach ($disallowed as $bad) {
            if ($lower === $bad || str_starts_with($lower, $bad)) {
                return false;
            }
        }

        if (preg_match('/^£/', $line)) {
            return false;
        }

        if (preg_match('/^[A-Z0-9]{6,14}$/', $line)) {
            return false;
        }

        return (bool) preg_match('/[A-Za-z]/', $line);
    }

    /**
     * @return array{header: array{creditor: string, balance: float, status: ?string, updated_date: ?string}, consumed: int}|null
     *         consumed is 1 (single-line summary) or 2 (continuation line + £ line merged).
     */
    private function extractAccountHeaderMeta(array $lines, int $i): ?array
    {
        $line = trim($lines[$i] ?? '');
        $next = trim($lines[$i + 1] ?? '');

        $inline = $this->parseAccountSummaryLine($line);
        if ($inline !== null) {
            return ['header' => $inline, 'consumed' => 1];
        }

        if (! $this->isLikelyAccountName($line)) {
            return null;
        }

        $split = $this->parseAccountSummaryLine($line.' '.$next);
        if ($split !== null) {
            return ['header' => $split, 'consumed' => 2];
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     * @return array{creditor:string,balance:float,status:?string,updated_date:?string}|null
     */
    private function extractAccountHeader(array $lines, int $i): ?array
    {
        $meta = $this->extractAccountHeaderMeta($lines, $i);

        return $meta !== null ? $meta['header'] : null;
    }

    /**
     * @return array{creditor:string,balance:float,status:?string,updated_date:?string}|null
     */
    private function parseAccountSummaryLine(string $line): ?array
    {
        $statusAlternation = implode('|', array_map(static fn ($s) => preg_quote($s, '/'), [
            'Up to date',
            'Default',
            'Delinquent',
            'Settled',
            'Satisfied',
            'Partially settled',
            'Late payment',
            'Active',
            'Arrangement',
            'Payment holiday',
        ]));

        $datePattern = '(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}\s+[A-Za-z]{3}\s+\d{4})';
        $pattern = '/^(?<name>.+?)\s+£\s*(?<balance>[0-9][0-9,]*(?:\.\d{1,2})?)\s+(?<updated>'.$datePattern.')\s+(?<status>'.$statusAlternation.')$/i';

        if (! preg_match($pattern, trim($line), $m)) {
            return null;
        }

        return [
            'creditor' => trim($m['name']),
            'balance' => (float) str_replace(',', '', $m['balance']),
            'status' => trim($m['status']),
            'updated_date' => $this->normalizeDate($m['updated']),
        ];
    }

    /**
     * @param array<int, string> $lines
     * @return array{case_number:string,type:string,status:string}|null
     */
    private function extractJudgmentTriplet(array $lines, int $i): ?array
    {
        $line = trim($lines[$i] ?? '');
        if (preg_match('/^(?<case>[A-Z0-9]{6,14})\s+(?<type>County Court Judgment)\s+(?<status>Active|Satisfied|Settled)$/i', $line, $m)) {
            return [
                'case_number' => strtoupper(trim($m['case'])),
                'type' => trim($m['type']),
                'status' => trim($m['status']),
            ];
        }

        $case = trim($lines[$i] ?? '');
        $type = trim($lines[$i + 1] ?? '');
        $status = trim($lines[$i + 2] ?? '');
        if (preg_match('/^[A-Z0-9]{6,14}$/', $case) && strcasecmp($type, 'County Court Judgment') === 0 && $status !== '') {
            return [
                'case_number' => strtoupper($case),
                'type' => $type,
                'status' => $status,
            ];
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function sliceBetween(array $lines, string $startNeedle, string $endNeedle): array
    {
        $start = 0;
        $end = count($lines) - 1;

        foreach ($lines as $idx => $line) {
            if (stripos($line, $startNeedle) !== false) {
                $start = $idx;
                break;
            }
        }

        for ($i = $start; $i < count($lines); $i++) {
            if (stripos($lines[$i], $endNeedle) !== false) {
                $end = $i - 1;
                break;
            }
        }

        if ($end < $start) {
            return [];
        }

        return array_slice($lines, $start, $end - $start + 1);
    }
}
