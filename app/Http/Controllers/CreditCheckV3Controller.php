<?php

namespace App\Http\Controllers;

use App\Models\Creditor;
use App\Models\CreditCheckJobLog;
use App\Models\Debt;
use App\Models\DebtDocument;
use App\Models\Lead;
use App\Models\VotingPractice;
use App\Services\CreditCheckV3JobLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreditCheckV3Controller extends Controller
{
    public function __construct(
        private CreditCheckV3JobLogService $jobLogService
    ) {}

    public function page(Lead $lead)
    {
        return view('leads.credit-check-v3', compact('lead'));
    }

    public function run(Lead $lead): JsonResponse
    {
        $log = CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'invoked_by_user_id' => auth()->id(),
            'status' => CreditCheckJobLog::STATUS_PENDING,
            'friendly_status' => 'Preparing credit check',
            'started_at' => now(),
        ]);

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
                'credit_check_job_log_id' => $log->id,
            ],
            'targetUrl' => 'https://www.transunionstatreport.co.uk/CreditReport/AboutYou',
        ];

        try {
            $response = Http::acceptJson()->post(
                $this->jobLogService->listenerBase().'/jobs/start',
                $payload
            );

            $json = $response->json();
            $this->jobLogService->appendRawSnapshot($log->fresh(), 'listener_start_response', [
                'http' => $response->status(),
                'body' => $json,
            ]);

            if (!$response->successful()) {
                $log->update([
                    'status' => CreditCheckJobLog::STATUS_FAILED,
                    'ended_at' => now(),
                    'friendly_status' => 'Failed',
                    'error_message' => is_array($json) ? (string) ($json['message'] ?? 'Listener rejected start request') : 'Listener rejected start request',
                ]);

                return response()->json($json, $response->status());
            }

            $jobId = is_array($json) ? (string) ($json['jobId'] ?? '') : '';
            if ($jobId === '') {
                $msg = is_array($json) ? (string) ($json['message'] ?? 'Listener did not return a job id.') : 'Listener did not return a job id.';
                if (is_array($json) && array_key_exists('ok', $json) && $json['ok'] === false && ($json['message'] ?? '') === '') {
                    $msg = 'Listener rejected the credit check start request.';
                }
                $log->update([
                    'status' => CreditCheckJobLog::STATUS_FAILED,
                    'ended_at' => now(),
                    'friendly_status' => 'Failed',
                    'error_message' => $msg,
                ]);

                return response()->json($json, $response->status());
            }

            $queued = (bool) ($json['queued'] ?? false);
            $log->update([
                'external_job_id' => $jobId,
                'status' => CreditCheckJobLog::STATUS_RUNNING,
                'friendly_status' => $queued ? 'Queued on local listener' : 'Preparing secure browser session',
            ]);

            $rj = is_array($log->result_json) ? $log->result_json : [];
            $lines = isset($rj['activity_lines']) && is_array($rj['activity_lines']) ? $rj['activity_lines'] : [];
            $firstLine = $queued ? 'Queued on local listener' : 'Preparing secure browser session';
            $lines[] = $firstLine;
            $rj['activity_lines'] = $lines;
            $log->update(['result_json' => $rj]);

            $json['credit_check_job_log_id'] = $log->id;

            return response()->json($json, $response->status());
        } catch (Throwable $e) {
            $log->update([
                'status' => CreditCheckJobLog::STATUS_FAILED,
                'ended_at' => now(),
                'friendly_status' => 'Failed',
                'error_message' => 'Unable to contact local listener.',
            ]);
            $this->jobLogService->appendRawSnapshot($log->fresh(), 'listener_start_exception', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
                'credit_check_job_log_id' => $log->id,
            ], 500);
        }
    }

    public function status(string $jobId): JsonResponse
    {
        try {
            $bundle = $this->fetchListenerStatusBundle($jobId);

            return response()->json([
                'ok' => true,
                'state' => $bundle['state'],
                'questions' => $bundle['questions'],
                'reportData' => $bundle['reportData'],
                'pdfState' => $bundle['pdfState'],
            ]);
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }
    }

    public function panelPoll(Lead $lead): JsonResponse
    {
        $health = $this->jobLogService->fetchListenerHealth();
        $listenerStatus = $this->jobLogService->listenerStatusLabel($health);
        if ($health['reachable'] && $health['active_job'] === true) {
            $listenerStatus = 'Listener connected';
        }

        $log = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->whereIn('status', [CreditCheckJobLog::STATUS_PENDING, CreditCheckJobLog::STATUS_RUNNING])
            ->orderByDesc('id')
            ->first();

        $activityLines = [];
        if ($log && is_array($log->result_json)) {
            $activityLines = isset($log->result_json['activity_lines']) && is_array($log->result_json['activity_lines'])
                ? $log->result_json['activity_lines']
                : [];
        }

        $lastPanelBundle = null;
        if ($log && $log->external_job_id) {
            try {
                $bundle = $this->fetchListenerStatusBundle($log->external_job_id);
                $lastPanelBundle = $bundle;
                $this->jobLogService->appendRawSnapshot($log, 'panel_poll', $bundle);
                $this->jobLogService->syncFromListenerBundle($log->fresh(), $bundle);
                $log->refresh();

                $this->maybePanelAutoImport($lead, $log->fresh(), $bundle);

                $log->refresh();
                $activityLines = isset($log->result_json['activity_lines']) && is_array($log->result_json['activity_lines'])
                    ? $log->result_json['activity_lines']
                    : [];
            } catch (Throwable $e) {
                Log::warning('credit_check_v3_panel_poll_listener', [
                    'lead_id' => $lead->id,
                    'job_id' => $log->external_job_id,
                    'error' => $e->getMessage(),
                ]);
                if ($health['reachable']) {
                    $listenerStatus = 'Listener unavailable';
                }
            }
        }

        $log = $log?->fresh();

        $debtsAgg = Debt::where('lead_id', $lead->id)
            ->selectRaw('COUNT(*) as c, MAX(updated_at) as mx')
            ->first();
        $debtsCount = (int) ($debtsAgg->c ?? 0);
        $debtsUpdatedAt = $debtsAgg->mx ? (string) $debtsAgg->mx : '';
        $debtsSignature = hash('sha256', $debtsCount.'|'.$debtsUpdatedAt);

        $running = $log?->isActive() ?? false;
        $startedAtIso = $log?->started_at?->toIso8601String();
        $elapsedSeconds = $log && $log->started_at
            ? (int) floor($log->started_at->diffInSeconds(now()))
            : null;

        $securityQuestions = [];
        if ($running && is_array($lastPanelBundle)) {
            $qRoot = (array) ($lastPanelBundle['questions'] ?? []);
            $qPayload = $qRoot['payload'] ?? null;
            $securityQuestions = is_array($qPayload) && is_array($qPayload['questions'] ?? null)
                ? $qPayload['questions']
                : (is_array($qRoot['questions'] ?? null) ? $qRoot['questions'] : []);
            if (!is_array($securityQuestions)) {
                $securityQuestions = [];
            }
        }

        return response()->json([
            'ok' => true,
            'listener_status' => $listenerStatus,
            'active_job_exists' => $running,
            'job_status' => $log?->status,
            'friendly_status' => $running ? $log?->friendly_status : null,
            'started_at' => $startedAtIso,
            'elapsed_seconds' => $elapsedSeconds,
            'debts_signature' => $debtsSignature,
            'debts_count' => $debtsCount,
            'debts_updated_at' => $debtsUpdatedAt,
            'activity_lines' => $running ? $activityLines : [],
            'running' => $running,
            'credit_check_job_log_id' => $log?->id,
            'external_job_id' => $log?->external_job_id,
            'security_questions' => $running ? $securityQuestions : [],
        ]);
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

        try {
            $response = Http::acceptJson()->post(
                $this->jobLogService->listenerBase().'/jobs/'.$jobId.'/answers',
                [
                    'answers' => $validated['answers'],
                ]
            );

            $json = $response->json();
            $log = CreditCheckJobLog::query()
                ->where('external_job_id', $jobId)
                ->orderByDesc('id')
                ->first();
            if ($log) {
                $this->jobLogService->appendRawSnapshot($log, 'answers_submitted', [
                    'http' => $response->status(),
                    'body' => $json,
                ]);
            }

            return response()->json($json, $response->status());
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'message' => 'Unable to contact local listener.',
            ], 500);
        }
    }

    public function importReportData(Lead $lead, string $jobId): JsonResponse
    {
        $result = $this->executeReportImport($lead, $jobId);

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
        try {
            $base = $this->jobLogService->listenerBase();
            $stateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/state');
            $reportDataResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/report-data');
            $pdfStateResponse = Http::acceptJson()->get($base.'/jobs/'.$jobId.'/pdf-state');
        } catch (Throwable) {
            return [
                'http' => 500,
                'payload' => [
                    'ok' => false,
                    'message' => 'Unable to contact local listener.',
                ],
            ];
        }

        $state = (array) ($stateResponse->json() ?? []);
        $activeJob = (array) ($state['activeJob'] ?? []);
        $meta = (array) ($activeJob['meta'] ?? []);
        $listenerLeadId = (int) ($activeJob['leadId'] ?? $meta['lead_id'] ?? 0);
        if ($listenerLeadId <= 0 || $listenerLeadId !== (int) $lead->id) {
            return [
                'http' => 409,
                'payload' => [
                    'ok' => false,
                    'message' => 'Listener lead mismatch for import.',
                    'listenerLeadId' => $listenerLeadId,
                    'leadId' => (int) $lead->id,
                ],
            ];
        }

        $pdfPayload = (array) (($pdfStateResponse->json() ?? [])['payload'] ?? []);
        $pdfStatus = (string) ($pdfPayload['status'] ?? '');
        if (!in_array($pdfStatus, ['moved_primary', 'moved_fallback'], true)) {
            return [
                'http' => 409,
                'payload' => [
                    'ok' => false,
                    'message' => 'Cannot import before PDF is moved.',
                    'pdfStatus' => $pdfStatus !== '' ? $pdfStatus : 'missing',
                ],
            ];
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

        $payload = [
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
        ];

        $this->updateJobLogAfterImport($lead, $jobId, $pdfPayload, $payload);

        return ['http' => 200, 'payload' => $payload];
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function maybePanelAutoImport(Lead $lead, CreditCheckJobLog $log, array $bundle): void
    {
        if (!$log->isActive()) {
            return;
        }

        $rj = is_array($log->result_json) ? $log->result_json : [];
        if (!empty($rj['panel_import_done']) || !empty($rj['panel_import_failed'])) {
            return;
        }

        $pdfPayload = (array) (($bundle['pdfState'] ?? [])['payload'] ?? []);
        $pdfStatus = (string) ($pdfPayload['status'] ?? '');
        if (!in_array($pdfStatus, ['moved_primary', 'moved_fallback'], true)) {
            return;
        }

        $reportPayload = (array) (($bundle['reportData'] ?? [])['payload'] ?? []);
        $debts = is_array($reportPayload['debts'] ?? null) ? $reportPayload['debts'] : [];
        $ccjs = is_array($reportPayload['county_court_judgments'] ?? null)
            ? $reportPayload['county_court_judgments']
            : [];
        if (count($debts) === 0 && count($ccjs) === 0) {
            return;
        }

        $result = $this->executeReportImport($lead, (string) $log->external_job_id);
        if (!($result['payload']['ok'] ?? false)) {
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
