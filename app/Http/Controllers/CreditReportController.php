<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Debt;
use App\Models\Creditor;
use App\Models\DebtDocument;
use App\Models\CreditReport;
use App\Models\CreditReportFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CreditReportController extends Controller
{
    public function store(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);

        $request->validate([
            'report_files' => ['required', 'array', 'min:1'],
            'report_files.*' => ['required', 'file', 'max:15360'],
        ]);

        $creditReport = CreditReport::create([
            'lead_id'  => $lead->id,
            'provider' => 'transunion_mht',
            'status'   => 'processing',
        ]);

        $combined = [];

        foreach ($request->file('report_files') as $index => $file) {
            $ext = strtolower($file->getClientOriginalExtension());

            if (!in_array($ext, ['mht', 'mhtml'])) {
                continue;
            }

            $storedPath = $file->store('credit-reports');
            $raw = Storage::get($storedPath);
            $extractedText = $this->extractTextFromMht($raw);

// DEBUG (TEMP)
if (str_contains($file->getClientOriginalName(), 'ccj') || str_contains($file->getClientOriginalName(), 'public')) {
    file_put_contents(storage_path('app/ccj_debug.txt'), $extractedText);
}
            CreditReportFile::create([
                'credit_report_id' => $creditReport->id,
                'original_name'    => $file->getClientOriginalName(),
                'stored_path'      => $storedPath,
                'mime_type'        => $file->getMimeType(),
                'sort_order'       => $index,
                'extracted_text'   => $extractedText,
            ]);

            if ($extractedText) {
                $combined[] = "===== FILE: {$file->getClientOriginalName()} =====\n" . $extractedText;
            }
        }

        $combinedText = implode("\n\n", $combined);

        $creditReport->combined_raw_text = $combinedText;
        $creditReport->status = 'processed';
        $creditReport->save();

$importedAccounts = $this->importAccountsFromText($lead, $combinedText);
$importedJudgments = $this->importJudgmentsFromFiles($lead, $creditReport);

        $totalImported = $importedAccounts + $importedJudgments;

        return redirect('/lead/' . $lead->id . '#credit-report-upload')
            ->with('credit_report_success', "Credit report files uploaded. {$totalImported} debt(s) imported.");
    }

    public function destroy($id)
    {
        $report = CreditReport::with('files')->findOrFail($id);

        foreach ($report->files as $file) {
            if ($file->stored_path && Storage::exists($file->stored_path)) {
                Storage::delete($file->stored_path);
            }
        }

        $leadId = $report->lead_id;
        $report->delete();

        return redirect('/lead/' . $leadId . '#credit-report-upload')
            ->with('credit_report_success', 'Credit report batch deleted.');
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

    private function importAccountsFromText(Lead $lead, string $text): int
    {
        if (!$text) {
            return 0;
        }

        $count = 0;
        $seen = [];
        $couldNotMatch = Creditor::where('name', 'Could Not Match')->first();

        $lines = array_values(array_filter(array_map('trim', preg_split("/\n/", $text))));

        for ($i = 0; $i < count($lines); $i++) {
            $line  = $lines[$i];
            $next1 = $lines[$i + 1] ?? '';
            $next2 = $lines[$i + 2] ?? '';
            $next3 = $lines[$i + 3] ?? '';

            if (!$this->isLikelyAccountName($line)) {
                continue;
            }

            if (!preg_match('/^£\s*([0-9][0-9,]*(?:\.\d{2})?)$/i', $next1, $balMatch)) {
                continue;
            }

            if (!$this->isLikelyDateLine($next2)) {
                continue;
            }

            if (!$this->isLikelyAccountStatus($next3)) {
                continue;
            }

            $creditorName = trim($line);
            $balance = (float) str_replace(',', '', $balMatch[1]);

            if ($balance <= 0) {
                continue;
            }

            $creditor = $this->matchCreditorStrict($creditorName);

            if (!$creditor && !$couldNotMatch) {
                continue;
            }

            $assignedCreditor = $creditor ?: $couldNotMatch;
            $reference = $creditor ? null : ('Raw creditor: ' . $creditorName);

            $dedupeKey = $assignedCreditor->id . '|' . number_format($balance, 2, '.', '') . '|' . ($reference ?? '');
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $exists = Debt::where('lead_id', $lead->id)
                ->where('creditor_id', $assignedCreditor->id)
                ->where('balance', $balance)
                ->where('source_expected', 'credit_check')
                ->where(function ($q) use ($reference) {
                    if ($reference === null) {
                        $q->whereNull('reference');
                    } else {
                        $q->where('reference', $reference);
                    }
                })
                ->exists();

            if ($exists) {
                continue;
            }

            $debt = Debt::create([
                'lead_id'         => $lead->id,
                'creditor_id'     => $assignedCreditor->id,
                'balance'         => $balance,
                'source_expected' => 'credit_check',
                'reference'       => $reference,
            ]);

            DebtDocument::create([
                'debt_id'     => $debt->id,
                'proof_type'  => 'credit_check',
                'is_complete' => true,
            ]);

            $count++;
        }

        return $count;
    }
	
private function importJudgmentsFromFiles(Lead $lead, CreditReport $creditReport): int
{
    file_put_contents(storage_path('app/ccj_debug.txt'), "ENTERED importJudgmentsFromFiles()\n");

    $ccjCreditor = Creditor::where('name', 'County Court Judgment')->first();

    if (!$ccjCreditor) {
        file_put_contents(storage_path('app/ccj_debug.txt'), "NO CCJ CREDITOR FOUND\n", FILE_APPEND);
        return 0;
    }

    $count = 0;
    $seen = [];

    $creditReport->loadMissing('files');

    file_put_contents(
        storage_path('app/ccj_debug.txt'),
        "FILES COUNT: " . $creditReport->files->count() . "\n",
        FILE_APPEND
    );

    foreach ($creditReport->files as $file) {
        $text = $file->extracted_text ?? '';

        file_put_contents(
            storage_path('app/ccj_debug.txt'),
            "\n--- FILE ID {$file->id} / {$file->original_name} ---\n",
            FILE_APPEND
        );

        if ($text === '') {
            file_put_contents(storage_path('app/ccj_debug.txt'), "EMPTY extracted_text\n", FILE_APPEND);
            continue;
        }

        $rows = preg_split("/\r\n|\r|\n/", $text);
        $rows = array_map(function ($row) {
            $row = str_replace("\xC2\xA0", ' ', $row);
            $row = preg_replace('/[ \t]+/u', ' ', $row);
            return trim($row);
        }, $rows);

        $rowCount = count($rows);

        file_put_contents(
            storage_path('app/ccj_debug.txt'),
            "ROW COUNT: {$rowCount}\n",
            FILE_APPEND
        );

        for ($i = 0; $i < $rowCount; $i++) {
            if (stripos($rows[$i], 'County Court Judgment') === false) {
                continue;
            }

            file_put_contents(
                storage_path('app/ccj_debug.txt'),
                "FOUND CCJ ANCHOR AT ROW {$i}: {$rows[$i]}\n",
                FILE_APPEND
            );

            $caseIndex = $i - 5;
            $amountLabelIndex = $i + 48;

            file_put_contents(
                storage_path('app/ccj_debug.txt'),
                "TRY CASE INDEX {$caseIndex}, AMOUNT LABEL INDEX {$amountLabelIndex}\n",
                FILE_APPEND
            );

            if ($caseIndex < 0 || $amountLabelIndex >= $rowCount) {
                file_put_contents(storage_path('app/ccj_debug.txt'), "SKIP: index out of range\n", FILE_APPEND);
                continue;
            }

            $caseRef = trim($rows[$caseIndex] ?? '');

            file_put_contents(
                storage_path('app/ccj_debug.txt'),
                "RAW CASE REF FROM -5: [{$caseRef}]\n",
                FILE_APPEND
            );

            if ($caseRef === '') {
                file_put_contents(storage_path('app/ccj_debug.txt'), "SKIP: blank case ref at -5\n", FILE_APPEND);
                continue;
            }

            if (!preg_match('/^[A-Z0-9]{6,10}$/', $caseRef)) {
                $caseRef = '';
                for ($u = max(0, $i - 8); $u <= max(0, $i - 2) && $u < $rowCount; $u++) {
                    $candidate = trim($rows[$u] ?? '');
                    if (preg_match('/^[A-Z0-9]{6,10}$/', $candidate)) {
                        $caseRef = $candidate;
                        file_put_contents(
                            storage_path('app/ccj_debug.txt'),
                            "FALLBACK CASE REF FOUND AT ROW {$u}: {$caseRef}\n",
                            FILE_APPEND
                        );
                        break;
                    }
                }
            }

            if ($caseRef === '') {
                file_put_contents(storage_path('app/ccj_debug.txt'), "SKIP: no valid case ref found\n", FILE_APPEND);
                continue;
            }

            $amount = null;

            for ($a = max(0, $amountLabelIndex - 3); $a <= min($rowCount - 1, $amountLabelIndex + 3); $a++) {
                $amountLabel = trim($rows[$a] ?? '');
                if (strcasecmp($amountLabel, 'Amount') === 0) {
                    file_put_contents(
                        storage_path('app/ccj_debug.txt'),
                        "FOUND AMOUNT LABEL AT ROW {$a}\n",
                        FILE_APPEND
                    );

                    for ($v = $a + 1; $v <= min($rowCount - 1, $a + 4); $v++) {
                        $amountCandidate = trim($rows[$v] ?? '');
                        file_put_contents(
                            storage_path('app/ccj_debug.txt'),
                            "CHECK AMOUNT ROW {$v}: [{$amountCandidate}]\n",
                            FILE_APPEND
                        );

                        if (preg_match('/£\s*([0-9][0-9,]*(?:\.\d{2})?)/i', $amountCandidate, $m)) {
                            $amount = (float) str_replace(',', '', $m[1]);
                            file_put_contents(
                                storage_path('app/ccj_debug.txt'),
                                "PARSED AMOUNT: {$amount}\n",
                                FILE_APPEND
                            );
                            break 2;
                        }
                    }
                }
            }

            if ($amount === null || $amount <= 0) {
                file_put_contents(storage_path('app/ccj_debug.txt'), "SKIP: no amount found\n", FILE_APPEND);
                continue;
            }

            $reference = $caseRef;
            $dedupeKey = $caseRef . '|' . number_format($amount, 2, '.', '');

            if (isset($seen[$dedupeKey])) {
                file_put_contents(storage_path('app/ccj_debug.txt'), "SKIP: already seen in this run\n", FILE_APPEND);
                continue;
            }
            $seen[$dedupeKey] = true;

            $exists = Debt::where('lead_id', $lead->id)
                ->where('creditor_id', $ccjCreditor->id)
                ->where('balance', $amount)
                ->where('source_expected', 'credit_check')
                ->where('reference', $reference)
                ->exists();

            if ($exists) {
                file_put_contents(storage_path('app/ccj_debug.txt'), "SKIP: already exists in DB\n", FILE_APPEND);
                continue;
            }

            Debt::create([
                'lead_id'         => $lead->id,
                'creditor_id'     => $ccjCreditor->id,
                'balance'         => $amount,
                'source_expected' => 'credit_check',
                'reference'       => $reference,
            ]);

            file_put_contents(
                storage_path('app/ccj_debug.txt'),
                "CREATED CCJ: {$reference} / {$amount}\n",
                FILE_APPEND
            );

            $debt = Debt::where('lead_id', $lead->id)
                ->where('creditor_id', $ccjCreditor->id)
                ->where('balance', $amount)
                ->where('reference', $reference)
                ->latest('id')
                ->first();

            if ($debt) {
                DebtDocument::create([
                    'debt_id'     => $debt->id,
                    'proof_type'  => 'credit_check',
                    'is_complete' => true,
                ]);
            }

            $count++;
        }
    }

    file_put_contents(
        storage_path('app/ccj_debug.txt'),
        "\nTOTAL CREATED: {$count}\n",
        FILE_APPEND
    );

    return $count;
}

private function normalizeJudgmentText(string $text): string
{
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace("/\r\n|\r/u", "\n", $text);
    $text = preg_replace("/\n{2,}/u", "\n", $text);

    return trim($text);
}


private function normalizeParsedLine(string $line): string
{
    $line = str_replace("\xC2\xA0", ' ', $line);
    $line = preg_replace('/\h+/u', ' ', $line);
    $line = trim($line);

    return $line;
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

    private function matchCreditorStrict(string $rawName): ?Creditor
    {
        $rawNorm = $this->normalizeString($rawName);
        $rawReduced = $this->normalizeReduced($rawName);

        if (class_exists(\App\Models\CreditorAlias::class)) {
            $alias = \App\Models\CreditorAlias::with('creditor')
                ->where('normalized_alias', $rawNorm)
                ->first();

            if ($alias && $alias->creditor) {
                return $alias->creditor;
            }

            $aliasReduced = \App\Models\CreditorAlias::with('creditor')->get();

            foreach ($aliasReduced as $aliasRow) {
                if ($this->normalizeReduced($aliasRow->alias) === $rawReduced && $aliasRow->creditor) {
                    return $aliasRow->creditor;
                }
            }
        }

        $creditors = Creditor::orderBy('name')->get();

        foreach ($creditors as $creditor) {
            if ($this->normalizeString($creditor->name) === $rawNorm) {
                return $creditor;
            }
        }

        foreach ($creditors as $creditor) {
            if ($this->normalizeReduced($creditor->name) === $rawReduced) {
                return $creditor;
            }
        }

        return null;
    }

    private function normalizeString(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    private function normalizeReduced(string $value): string
    {
        $value = $this->normalizeString($value);

        $removeWords = [
            'limited',
            'ltd',
            'plc',
            'uk',
            'bank',
            'group',
            'finance',
            'financial',
            'services',
            'current',
            'accounts',
            'account',
        ];

        $parts = array_filter(explode(' ', $value), function ($part) use ($removeWords) {
            return !in_array($part, $removeWords, true);
        });

        return trim(implode(' ', $parts));
    }
}