<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use App\Models\CreditCheckJobLog;
use App\Models\Debt;
use App\Models\DebtDocument;
use App\Models\Lead;
use App\Models\VotingPractice;
use App\Services\CreditCheckV3FlowService;
use App\Services\CreditCheckV3JobLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreditCheckV3Controller extends Controller
{
    public function __construct(
        private CreditCheckV3JobLogService $jobLogService,
        private CreditCheckV3FlowService $creditCheckV3FlowService
    ) {}

    public function page(Lead $lead)
    {
        return view('leads.credit-check-v3', compact('lead'));
    }

    public function run(Lead $lead): JsonResponse
    {
        $result = $this->creditCheckV3FlowService->startForLead($lead, auth()->id());

        return response()->json($result['payload'], $result['http']);
    }

    public function status(string $jobId): JsonResponse
    {
        $result = $this->creditCheckV3FlowService->statusForJob($jobId);

        return response()->json($result['payload'], $result['http']);
    }

    public function panelPoll(Lead $lead): JsonResponse
    {
        $result = $this->creditCheckV3FlowService->panelPollForLead(
            $lead,
            function (Lead $pollLead, CreditCheckJobLog $log, array $bundle): void {
                $this->maybePanelAutoImport($pollLead, $log, $bundle);
            }
        );

        return response()->json($result['payload']);
    }

    public function debtsSectionHtml(Lead $lead)
    {
        $lead->load(['debts.creditor', 'debts.document']);
        $practices = VotingPractice::orderBy('id')->get();

        return response()->view('leads.partials.lead-debts-section-inner', compact('lead', 'practices'));
    }

    public function submitAnswers(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        Log::info('Agent credit-check v3 answers payload sample', [
            'job_id' => $jobId,
            'answers' => $validated['answers'],
        ]);

        $result = $this->creditCheckV3FlowService->submitAnswersForJob($jobId, $validated['answers']);

        return response()->json($result['payload'], $result['http']);
    }

    public function importReportData(Lead $lead, string $jobId): JsonResponse
    {
        $result = $this->creditCheckV3FlowService->importReportDataForLead($lead, $jobId);

        return response()->json(
            $result['payload'],
            $result['http'] ?? 500
        );
    }

    /**
     * @return array{http: int, payload: array<string, mixed>}
     */
    private function executeReportImport(Lead $lead, string $jobId): array
    {
        return $this->creditCheckV3FlowService->importReportDataForLead($lead, $jobId);
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function maybePanelAutoImport(Lead $lead, CreditCheckJobLog $log, array $bundle): void
    {
        if (! $this->creditCheckV3FlowService->shouldAttemptImportFromBundle($log, $bundle)) {
            return;
        }

        $result = $this->executeReportImport($lead, (string) $log->external_job_id);
        if (! ($result['payload']['ok'] ?? false)) {
            $log->refresh();
            $rj = is_array($log->result_json) ? $log->result_json : [];
            $rj['panel_import_failed'] = true;
            $rj['panel_import_error'] = $result['payload']['message'] ?? 'Import failed';
            $log->update([
                'result_json' => $rj,
                'error_message' => (string) ($result['payload']['message'] ?? 'Import failed'),
            ]);

            return;
        }

        $log->refresh();
    }

    /**
     * @param  array<string, mixed>  $pdfPayload
     * @param  array<string, mixed>  $payload
     */
    private function updateJobLogAfterImport(Lead $lead, string $jobId, array $pdfPayload, array $payload): void
    {
        $log = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->where('external_job_id', $jobId)
            ->orderByDesc('id')
            ->first();

        if (!$log) {
            return;
        }

        if ($log->status === CreditCheckJobLog::STATUS_FAILED) {
            $rj = is_array($log->result_json) ? $log->result_json : [];
            $rj['import_result'] = $payload;
            $log->update(['result_json' => $rj]);

            return;
        }

        $rj = is_array($log->result_json) ? $log->result_json : [];
        $rj['panel_import_done'] = true;
        $rj['import_result'] = $payload;

        $savedPath = $pdfPayload['savedPath'] ?? null;
        $log->update([
            'result_json' => $rj,
            'saved_pdf_path' => is_string($savedPath) ? $savedPath : $log->saved_pdf_path,
            'status' => CreditCheckJobLog::STATUS_SUCCESS,
            'ended_at' => $log->ended_at ?? now(),
            'friendly_status' => 'Completed',
        ]);
    }

    /**
     * @return array{state: mixed, questions: mixed, reportData: mixed, pdfState: mixed}
     */
    private function fetchListenerStatusBundle(string $jobId): array
    {
        $base = $this->jobLogService->listenerBase();
        $stateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/state');
        $questionsResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/questions');
        $reportDataResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/report-data');
        $pdfStateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/pdf-state');

        return [
            'state' => $stateResponse->json(),
            'questions' => $questionsResponse->json(),
            'reportData' => $reportDataResponse->json(),
            'pdfState' => $pdfStateResponse->json(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $debts
     */
    private function importDebtsFromReportData(Lead $lead, array $debts): int
    {
        if ($debts === []) {
            return 0;
        }
        $count = 0;
        $seen = [];
        $couldNotMatch = Creditor::where('name', 'Could Not Match')->first();

        foreach ($debts as $row) {
            $creditorName = trim((string) ($row['creditor'] ?? $row['organisation'] ?? ''));
            $balance = $this->normalizeCurrencyValue($row['balance'] ?? null);
            if ($creditorName === '' || $balance === null || $balance <= 0) {
                continue;
            }
            $creditor = $this->matchCreditorStrict($creditorName);
            if (!$creditor && !$couldNotMatch) {
                continue;
            }
            $assignedCreditor = $creditor ?: $couldNotMatch;
            $reference = $creditor ? null : ('Raw creditor: '.$creditorName);
            $dedupeKey = $assignedCreditor->id.'|'.number_format($balance, 2, '.', '').'|'.($reference ?? '');
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
                'lead_id' => $lead->id,
                'creditor_id' => $assignedCreditor->id,
                'balance' => $balance,
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

    /**
     * @param array<int, array<string, mixed>> $judgments
     */
    private function importCountyCourtJudgmentsFromReportData(Lead $lead, array $judgments): int
    {
        if ($judgments === []) {
            return 0;
        }
        $ccjCreditor = Creditor::where('name', 'County Court Judgment')->first();
        if (!$ccjCreditor) {
            return 0;
        }
        $count = 0;
        $seen = [];
        foreach ($judgments as $row) {
            $amount = $this->normalizeCurrencyValue($row['amount'] ?? $row['balance'] ?? null);
            $caseNumber = trim((string) ($row['case_number'] ?? ''));
            $judgementDate = trim((string) ($row['judgement_date'] ?? $row['judgment_date'] ?? ''));
            $address = trim((string) ($row['address'] ?? ''));
            if ($amount === null || $amount <= 0) {
                continue;
            }
            $reference = trim(implode(' | ', array_filter([$caseNumber, $judgementDate, $address], fn ($v) => $v !== '')));
            if ($reference === '') {
                continue;
            }
            $dedupeKey = number_format($amount, 2, '.', '').'|'.$reference;
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
        if (!is_string($value)) {
            return null;
        }
        if (!preg_match('/([0-9][0-9,]*(?:\.\d{1,2})?)/', $value, $m)) {
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
