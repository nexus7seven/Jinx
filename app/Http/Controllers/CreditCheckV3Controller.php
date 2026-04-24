<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use App\Models\Debt;
use App\Models\DebtDocument;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreditCheckV3Controller extends Controller
{
    public function page(Lead $lead)
    {
        return view('leads.credit-check-v3', compact('lead'));
    }

    public function run(Lead $lead): JsonResponse
    {
        $payload = [
            'leadId' => $lead->id,
            'lead' => [
                'title' => $lead->title,
                'first_name' => $lead->first_name,
                'middle_name' => $lead->middle_name,
                'last_name' => $lead->last_name,
                'dob' => $lead->dob,
                'phone_number' => $lead->phone_number,
                'postcode' => $lead->postcode,
                'house_number' => $lead->house_number,
                'house_name' => $lead->house_name,
                'building_number' => $lead->building_number,
                'address_line_1' => $lead->address_line_1,
            ],
            'meta' => [
                'source' => 'jinx_credit_check_v3',
                'lead_id' => $lead->id,
            ],
            'targetUrl' => 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou',
        ];

        try {
            $response = Http::acceptJson()->post(
                rtrim((string) config('services.credit_check_v3_listener.base_url'), '/').'/jobs/start',
                $payload
            );

            return response()->json($response->json(), $response->status());
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }
    }

    public function status(string $jobId): JsonResponse
    {
        try {
            $base = rtrim((string) config('services.credit_check_v3_listener.base_url'), '/');
            $stateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/state');
            $questionsResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/questions');
            $reportDataResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/report-data');
            $pdfStateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/pdf-state');

            return response()->json([
                'ok' => true,
                'state' => $stateResponse->json(),
                'questions' => $questionsResponse->json(),
                'reportData' => $reportDataResponse->json(),
                'pdfState' => $pdfStateResponse->json(),
            ]);
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }
    }

    public function submitAnswers(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        try {
            $response = Http::acceptJson()->post(
                rtrim((string) config('services.credit_check_v3_listener.base_url'), '/').'/jobs/'.$jobId.'/answers',
                [
                    'answers' => $validated['answers'],
                ]
            );

            return response()->json($response->json(), $response->status());
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }
    }

    public function importReportData(Lead $lead, string $jobId): JsonResponse
    {
        try {
            $base = rtrim((string) config('services.credit_check_v3_listener.base_url'), '/');
            $stateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/state');
            $reportDataResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/report-data');
            $pdfStateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/pdf-state');
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }

        $state = (array) ($stateResponse->json() ?? []);
        $activeJob = (array) ($state['activeJob'] ?? []);
        $meta = (array) ($activeJob['meta'] ?? []);
        $listenerLeadId = (int) ($activeJob['leadId'] ?? $meta['lead_id'] ?? 0);
        if ($listenerLeadId <= 0 || $listenerLeadId !== (int) $lead->id) {
            return response()->json([
                'ok' => false,
                'message' => 'Listener lead mismatch for import.',
                'listenerLeadId' => $listenerLeadId,
                'leadId' => (int) $lead->id,
            ], 409);
        }

        $pdfPayload = (array) (($pdfStateResponse->json() ?? [])['payload'] ?? []);
        $pdfStatus = (string) ($pdfPayload['status'] ?? '');
        if (!in_array($pdfStatus, ['moved_primary', 'moved_fallback'], true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Cannot import before PDF is moved.',
                'pdfStatus' => $pdfStatus !== '' ? $pdfStatus : 'missing',
            ], 409);
        }

        $reportPayload = (array) (($reportDataResponse->json() ?? [])['payload'] ?? []);
        $debts = is_array($reportPayload['debts'] ?? null) ? $reportPayload['debts'] : [];
        $ccjs = is_array($reportPayload['county_court_judgments'] ?? null)
            ? $reportPayload['county_court_judgments']
            : [];

        $importedDebts = $this->importDebtsFromReportData($lead, $debts);
        $importedCcjs = $this->importCountyCourtJudgmentsFromReportData($lead, $ccjs);
        $totalImported = $importedDebts + $importedCcjs;

        Log::info('Credit check v3 report import complete', [
            'lead_id' => $lead->id,
            'job_id' => $jobId,
            'debts_imported' => $importedDebts,
            'ccjs_imported' => $importedCcjs,
            'total_imported' => $totalImported,
            'pdf_status' => $pdfStatus,
        ]);

        $completeJson = null;
        $completeStatus = null;
        try {
            Http::acceptJson()->post($base.'/jobs/'.$jobId.'/status', [
                'step' => 'import_completed',
                'imported' => [
                    'debts' => $importedDebts,
                    'county_court_judgments' => $importedCcjs,
                    'total' => $totalImported,
                ],
                'leadId' => (int) $lead->id,
            ]);
            $completeResponse = Http::acceptJson()->post($base.'/jobs/'.$jobId.'/complete');
            $completeStatus = $completeResponse->status();
            $completeJson = $completeResponse->json();
            Log::info('Credit check v3 listener complete attempted', [
                'lead_id' => $lead->id,
                'job_id' => $jobId,
                'status' => $completeStatus,
                'result' => $completeJson,
            ]);
        } catch (Throwable $e) {
            Log::warning('Credit check v3 listener complete failed', [
                'lead_id' => $lead->id,
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'imported' => [
                'debts' => $importedDebts,
                'county_court_judgments' => $importedCcjs,
                'total' => $totalImported,
            ],
            'pdf' => [
                'status' => $pdfStatus,
                'savedPath' => $pdfPayload['savedPath'] ?? null,
            ],
            'listenerComplete' => [
                'status' => $completeStatus,
                'result' => $completeJson,
            ],
        ]);
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

