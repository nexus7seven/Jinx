<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Debt;
use App\Models\Creditor;
use App\Models\DebtDocument;
use App\Models\CreditReport;
use App\Models\CreditReportFile;
use App\Services\CreditReports\CreditReportFileTypeDetector;
use App\Services\CreditReports\CreditReportParserFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;

class CreditReportController extends Controller
{
    public function __construct(
        private readonly CreditReportFileTypeDetector $fileTypeDetector,
        private readonly CreditReportParserFactory $parserFactory,
    ) {}

    public function store(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);

        $request->validate([
            'report_files' => ['required', 'array', 'min:1'],
            'report_files.*' => ['required', 'file', 'max:15360', 'extensions:mht,mhtml,pdf'],
        ]);

        $creditReport = CreditReport::create([
            'lead_id'  => $lead->id,
            'provider' => 'transunion_upload',
            'status'   => 'processing',
        ]);

        $combined = [];
        $normalizedDebts = [];
        $normalizedJudgments = [];
        $parsedFileCount = 0;
        $errors = [];

        foreach ($request->file('report_files') as $index => $file) {
            $fileType = $this->fileTypeDetector->detect($file);
            if ($fileType === null) {
                $errors[] = "Unsupported file type for {$file->getClientOriginalName()}.";
                Log::warning('Credit report file skipped: unsupported type', [
                    'lead_id' => $lead->id,
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                ]);
                continue;
            }

            $storedPath = $file->store('credit-reports');
            $absolutePath = Storage::path($storedPath);

            try {
                $parser = $this->parserFactory->make($fileType);
                $parsed = $parser->parse($absolutePath);
            } catch (\Throwable $e) {
                $errors[] = "Could not parse {$file->getClientOriginalName()}.";
                Log::error('Credit report parse failed', [
                    'lead_id' => $lead->id,
                    'name' => $file->getClientOriginalName(),
                    'type' => $fileType,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $extractedText = $parsed['text'] ?? '';
            $debts = is_array($parsed['debts'] ?? null) ? $parsed['debts'] : [];
            $judgments = is_array($parsed['county_court_judgments'] ?? null) ? $parsed['county_court_judgments'] : [];

            if ($fileType === CreditReportFileTypeDetector::TYPE_PDF && ! $this->pdfParseIsUsable($extractedText, $debts, $judgments)) {
                Storage::delete($storedPath);
                $errors[] = "PDF {$file->getClientOriginalName()} had no extractable credit data (empty text or no accounts/judgments).";
                Log::warning('Credit report PDF rejected: unusable parse result', [
                    'lead_id' => $lead->id,
                    'name' => $file->getClientOriginalName(),
                    'text_length' => strlen(trim($extractedText)),
                    'debts_extracted' => count($debts),
                    'ccjs_extracted' => count($judgments),
                ]);
                continue;
            }

            $parsedFileCount++;

            Log::info('Credit report parser selected', [
                'lead_id' => $lead->id,
                'name' => $file->getClientOriginalName(),
                'type' => $fileType,
                'debts_extracted' => count($debts),
                'ccjs_extracted' => count($judgments),
            ]);

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

            $normalizedDebts = array_merge($normalizedDebts, $debts);
            $normalizedJudgments = array_merge($normalizedJudgments, $judgments);
        }

        if ($parsedFileCount === 0) {
            $creditReport->status = 'failed';
            $creditReport->combined_raw_text = '';
            $creditReport->save();

            $errorMessage = $errors ? implode(' ', $errors) : 'No supported credit report files could be parsed.';

            return redirect('/lead/' . $lead->id . '#credit-report-upload')
                ->with('credit_report_error', $errorMessage);
        }

        $combinedText = implode("\n\n", $combined);

        if ($normalizedJudgments !== [] && Creditor::where('name', 'County Court Judgment')->doesntExist()) {
            Log::error('Credit report import blocked: CCJs parsed but County Court Judgment creditor is missing', [
                'lead_id' => $lead->id,
                'credit_report_id' => $creditReport->id,
                'ccjs_parsed' => count($normalizedJudgments),
            ]);

            $creditReport->combined_raw_text = $combinedText;
            $creditReport->status = 'failed';
            $creditReport->save();

            return redirect('/lead/' . $lead->id . '#credit-report-upload')
                ->with(
                    'credit_report_error',
                    'This report includes County Court Judgments, but the system creditor "County Court Judgment" is not configured. Add it under creditors (or contact support) and upload again. No debts were imported.'
                );
        }

        $creditReport->combined_raw_text = $combinedText;
        $creditReport->status = 'processed';
        $creditReport->save();

        $importedAccounts = $this->importDebtsFromNormalized($lead, $normalizedDebts);
        $importedJudgments = $this->importCountyCourtJudgmentsFromNormalized($lead, $normalizedJudgments);

        $totalImported = $importedAccounts + $importedJudgments;

        Log::info('Credit report import complete', [
            'lead_id' => $lead->id,
            'report_id' => $creditReport->id,
            'debts_imported' => $importedAccounts,
            'ccjs_imported' => $importedJudgments,
            'total_imported' => $totalImported,
        ]);

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

    /**
     * PDF-only: reject parses that produced nothing useful, without relying on the PDF library throwing.
     *
     * @param array<int, array<string, mixed>> $debts
     * @param array<int, array<string, mixed>> $judgments
     */
    private function pdfParseIsUsable(string $text, array $debts, array $judgments): bool
    {
        if (trim($text) === '') {
            return false;
        }

        if ($debts === [] && $judgments === []) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $debts
     */
    private function importDebtsFromNormalized(Lead $lead, array $debts): int
    {
        if ($debts === []) {
            return 0;
        }

        $count = 0;
        $seen = [];
        $couldNotMatch = Creditor::where('name', 'Could Not Match')->first();

        foreach ($debts as $debtRow) {
            $creditorName = trim((string) ($debtRow['creditor'] ?? ''));
            $balance = $this->normalizeCurrencyValue($debtRow['balance'] ?? null);

            if ($creditorName === '' || $balance === null) {
                continue;
            }

            if ($balance <= 0) {
                continue;
            }

            $creditor = $this->matchCreditorStrict($creditorName);

            if (!$creditor && !$couldNotMatch) {
                continue;
            }

            $assignedCreditor = $creditor ?: $couldNotMatch;
            $reference = $creditor ? null : ('Raw creditor: ' . $creditorName);

            if ($creditor === null && $couldNotMatch && $assignedCreditor->name === 'Could Not Match') {
                Log::notice('Credit report debt assigned to Could Not Match', [
                    'lead_id' => $lead->id,
                    'raw_creditor_name' => $creditorName,
                    'balance' => $balance,
                ]);
            }

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
    
    /**
     * @param array<int, array<string, mixed>> $judgments
     */
    private function importCountyCourtJudgmentsFromNormalized(Lead $lead, array $judgments): int
    {
        if ($judgments === []) {
            return 0;
        }

        $ccjCreditor = Creditor::where('name', 'County Court Judgment')->first();
        if (! $ccjCreditor) {
            Log::critical('CCJ import invoked without County Court Judgment creditor (configuration error)', [
                'lead_id' => $lead->id,
                'ccjs_parsed' => count($judgments),
            ]);
            throw new LogicException(
                'County Court Judgment creditor is missing; import should have been blocked in the controller.'
            );
        }

        $count = 0;
        $seen = [];

        foreach ($judgments as $judgmentRow) {
            $caseRef = trim((string) ($judgmentRow['case_number'] ?? ''));
            $amount = $this->normalizeCurrencyValue($judgmentRow['amount'] ?? null);

            if ($caseRef === '' || ! preg_match('/^[A-Z0-9]{6,14}$/', $caseRef)) {
                continue;
            }

            if ($amount === null || $amount <= 0) {
                continue;
            }

            $reference = $caseRef;
            $dedupeKey = $caseRef . '|' . number_format($amount, 2, '.', '');
            if (isset($seen[$dedupeKey])) {
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
                continue;
            }

            $debt = Debt::create([
                'lead_id' => $lead->id,
                'creditor_id' => $ccjCreditor->id,
                'balance' => $amount,
                'source_expected' => 'credit_check',
                'reference' => $reference,
            ]);

            DebtDocument::create([
                'debt_id' => $debt->id,
                'proof_type' => 'credit_check',
                'is_complete' => true,
            ]);

            $count++;
        }

        return $count;
    }

    private function normalizeCurrencyValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        if (! preg_match('/([0-9][0-9,]*(?:\.\d{1,2})?)/', $value, $m)) {
            return null;
        }

        return (float) str_replace(',', '', $m[1]);
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