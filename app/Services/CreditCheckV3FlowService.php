<?php

namespace App\Services;

use App\Models\Creditor;
use App\Models\CreditCheckJobLog;
use App\Models\Debt;
use App\Models\DebtDocument;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreditCheckV3FlowService
{
    public function __construct(
        private readonly CreditCheckV3JobLogService $jobLogService
    ) {
    }

    /**
     * @return array{http: int, payload: array<string, mixed>}
     */
    public function startForLead(Lead $lead, ?int $invokedByUserId = null): array
    {
        $log = CreditCheckJobLog::create([
            'lead_id' => $lead->id,
            'invoked_by_user_id' => $invokedByUserId,
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

            if (! $response->successful()) {
                $log->update([
                    'status' => CreditCheckJobLog::STATUS_FAILED,
                    'ended_at' => now(),
                    'friendly_status' => 'Failed',
                    'error_message' => is_array($json) ? (string) ($json['message'] ?? 'Listener rejected start request') : 'Listener rejected start request',
                ]);

                return [
                    'http' => $response->status(),
                    'payload' => is_array($json) ? $json : [
                        'ok' => false,
                        'message' => 'Listener rejected start request',
                        'credit_check_job_log_id' => $log->id,
                    ],
                ];
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

                return [
                    'http' => $response->status(),
                    'payload' => is_array($json) ? $json : [
                        'ok' => false,
                        'message' => $msg,
                        'credit_check_job_log_id' => $log->id,
                    ],
                ];
            }

            $queued = (bool) ($json['queued'] ?? false);
            $log->update([
                'external_job_id' => $jobId,
                'status' => CreditCheckJobLog::STATUS_RUNNING,
                'friendly_status' => $queued ? 'Queued on local listener' : 'Preparing secure browser session',
            ]);

            $rj = is_array($log->result_json) ? $log->result_json : [];
            $lines = isset($rj['activity_lines']) && is_array($rj['activity_lines']) ? $rj['activity_lines'] : [];
            $lines[] = $queued ? 'Queued on local listener' : 'Preparing secure browser session';
            $rj['activity_lines'] = $lines;
            $log->update(['result_json' => $rj]);

            $safeJson = is_array($json) ? $json : ['ok' => true, 'jobId' => $jobId];
            $safeJson['credit_check_job_log_id'] = $log->id;

            return [
                'http' => $response->status(),
                'payload' => $safeJson,
            ];
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

            return [
                'http' => 500,
                'payload' => [
                    'ok' => false,
                    'message' => 'Unable to contact local listener.',
                    'credit_check_job_log_id' => $log->id,
                ],
            ];
        }
    }

    /**
     * @return array{state: mixed, questions: mixed, reportData: mixed, pdfState: mixed}
     */
    public function fetchListenerStatusBundle(string $jobId): array
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
     * @return array{http: int, payload: array<string, mixed>}
     */
    public function statusForJob(string $jobId): array
    {
        try {
            $bundle = $this->fetchListenerStatusBundle($jobId);

            return [
                'http' => 200,
                'payload' => [
                    'ok' => true,
                    'state' => $bundle['state'],
                    'questions' => $bundle['questions'],
                    'reportData' => $bundle['reportData'],
                    'pdfState' => $bundle['pdfState'],
                ],
            ];
        } catch (Throwable) {
            return [
                'http' => 500,
                'payload' => [
                    'ok' => false,
                    'message' => 'Unable to contact local listener.',
                ],
            ];
        }
    }

    /**
     * @param array<int, mixed> $answers
     * @return array{http: int, payload: array<string, mixed>}
     */
    public function submitAnswersForJob(string $jobId, array $answers): array
    {
        try {
            $response = Http::acceptJson()->post(
                $this->jobLogService->listenerBase().'/jobs/'.$jobId.'/answers',
                [
                    'answers' => $answers,
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

            return [
                'http' => $response->status(),
                'payload' => is_array($json) ? $json : ['ok' => $response->successful()],
            ];
        } catch (Throwable) {
            return [
                'http' => 500,
                'payload' => [
                    'ok' => false,
                    'message' => 'Unable to contact local listener.',
                ],
            ];
        }
    }

    /**
     * @param callable(Lead, CreditCheckJobLog, array<string,mixed>): void|null $afterSync
     * @return array{payload: array<string,mixed>, bundle: array<string,mixed>|null, log: CreditCheckJobLog|null}
     */
    public function panelPollForLead(Lead $lead, ?callable $afterSync = null): array
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
                $bundle = $this->fetchListenerStatusBundle((string) $log->external_job_id);
                $lastPanelBundle = $bundle;
                $this->jobLogService->appendRawSnapshot($log, 'panel_poll', $bundle);
                $this->jobLogService->syncFromListenerBundle($log->fresh(), $bundle);
                $log->refresh();
                $this->cacheUsefulReportPayloadIfPresent($log, $bundle);
                $log->refresh();

                if ($afterSync !== null) {
                    $afterSync($lead, $log, $bundle);
                    $log->refresh();
                }

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
            if (! is_array($securityQuestions)) {
                $securityQuestions = [];
            }
        }

        return [
            'payload' => [
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
            ],
            'bundle' => is_array($lastPanelBundle) ? $lastPanelBundle : null,
            'log' => $log,
        ];
    }

    /**
     * @param array<string, mixed> $reportPayload
     */
    private function isUsefulReportPayload(array $reportPayload): bool
    {
        foreach (['debts', 'accounts', 'credit_accounts', 'county_court_judgments', 'ccjs', 'judgments', 'public_records'] as $key) {
            if (array_key_exists($key, $reportPayload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function cacheUsefulReportPayloadIfPresent(CreditCheckJobLog $log, array $bundle): void
    {
        $reportPayload = $this->extractListenerPayload((array) ($bundle['reportData'] ?? []));
        if (! $this->isUsefulReportPayload($reportPayload)) {
            if ($reportPayload !== []) {
                Log::info('credit_check_v3_current_report_payload_unusable', [
                    'lead_id' => $log->lead_id,
                    'job_id' => $log->external_job_id,
                    'log_id' => $log->id,
                    'keys' => array_keys($reportPayload),
                ]);
            }

            return;
        }

        $rj = is_array($log->result_json) ? $log->result_json : [];
        $rj['cached_report_payload'] = $reportPayload;
        $rj['last_report_payload'] = $reportPayload;
        $log->update(['result_json' => $rj]);

        Log::info('credit_check_v3_cached_report_payload', [
            'lead_id' => $log->lead_id,
            'job_id' => $log->external_job_id,
            'log_id' => $log->id,
            'keys' => array_keys($reportPayload),
            'debts_count' => count($this->extractDebtsRows($reportPayload)),
            'ccjs_count' => count($this->extractCcjRows($reportPayload)),
        ]);
    }

    /**
     * @return array{http: int, payload: array<string, mixed>}
     */
    public function importFromJobLog(CreditCheckJobLog $log): array
    {
        $lead = Lead::find($log->lead_id);
        if (! $lead) {
            return ['http' => 404, 'payload' => ['ok' => false, 'message' => 'Lead not found.']];
        }

        if ($log->status !== CreditCheckJobLog::STATUS_SUCCESS) {
            return ['http' => 409, 'payload' => ['ok' => false, 'message' => 'Job is not in success state.']];
        }

        $result = is_array($log->result_json) ? $log->result_json : json_decode((string) $log->result_json, true);
        $result = is_array($result) ? $result : [];

        $reportPayload = [];
        if (! empty($result['last_report_payload']) && is_array($result['last_report_payload'])) {
            $reportPayload = $result['last_report_payload'];
        } elseif (! empty($result['cached_report_payload']) && is_array($result['cached_report_payload'])) {
            $reportPayload = $result['cached_report_payload'];
        }

        $debts = is_array($reportPayload['debts'] ?? null) ? $reportPayload['debts'] : [];
        $ccjs = is_array($reportPayload['county_court_judgments'] ?? null) ? $reportPayload['county_court_judgments'] : [];
        if ($debts === [] && $ccjs === []) {
            return ['http' => 422, 'payload' => ['ok' => false, 'message' => 'No importable debts/CCJs payload found.']];
        }

        Log::info('credit_check_v3_db_import_started', ['lead_id' => $lead->id, 'job_id' => $log->external_job_id]);
        Log::info('credit_check_v3_db_import_counts', ['lead_id' => $lead->id, 'job_id' => $log->external_job_id, 'debts_seen' => count($debts), 'ccjs_seen' => count($ccjs)]);

        $importedDebts = $this->importDebtsFromReportData($lead, $debts, (string) $log->external_job_id, 'db_import');
        $importedCcjs = $this->importCountyCourtJudgmentsFromReportData($lead, $ccjs, (string) $log->external_job_id, 'db_import');

        $result['panel_import_done'] = true;
        $log->update([
            'status' => 'success_imported',
            'result_json' => $result,
        ]);

        Log::info('credit_check_v3_db_import_finished', [
            'lead_id' => $lead->id,
            'job_id' => $log->external_job_id,
            'debts_inserted' => $importedDebts,
            'ccjs_inserted' => $importedCcjs,
        ]);

        return ['http' => 200, 'payload' => ['ok' => true, 'imported' => ['debts' => $importedDebts, 'county_court_judgments' => $importedCcjs, 'total' => $importedDebts + $importedCcjs]]];
    }

    /**
     * @param array<int, array<string, mixed>> $debts
     */
    private function importDebtsFromReportData(Lead $lead, array $debts, string $jobId, string $pdfStatus): int
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
                Log::info('credit_check_v3_db_debt_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'missing_creditor_or_balance', 'row' => $row]);
                continue;
            }
            $creditor = $this->matchCreditorStrict($creditorName);
            if (! $creditor && ! $couldNotMatch) {
                Log::info('credit_check_v3_db_debt_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'no_could_not_match_creditor_seeded', 'row' => $row]);
                continue;
            }
            $assignedCreditor = $creditor ?: $couldNotMatch;
            $reference = $creditor ? null : ('Raw creditor: '.$creditorName);
            $dedupeKey = $assignedCreditor->id.'|'.number_format($balance, 2, '.', '').'|'.($reference ?? '');
            if (isset($seen[$dedupeKey])) {
                Log::info('credit_check_v3_db_debt_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'duplicate_in_payload', 'row' => $row]);
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
                Log::info('credit_check_v3_db_debt_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'already_exists', 'row' => $row]);
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
            Log::info('credit_check_v3_db_debt_inserted', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'debt_id' => $debt->id, 'balance' => $balance]);
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $judgments
     */
    private function importCountyCourtJudgmentsFromReportData(Lead $lead, array $judgments, string $jobId, string $pdfStatus): int
    {
        if ($judgments === []) {
            Log::info('credit_check_v3_db_ccj_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'missing_county_court_judgment_creditor']);
            return 0;
        }
        $ccjCreditor = Creditor::where('name', 'County Court Judgment')->first();
        if (! $ccjCreditor) {
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
                Log::info('credit_check_v3_db_ccj_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'missing_amount', 'row' => $row]);
                continue;
            }
            $reference = trim(implode(' | ', array_filter([$caseNumber, $judgementDate, $address], fn ($v) => $v !== '')));
            if ($reference === '') {
                Log::info('credit_check_v3_db_ccj_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'missing_reference_fields', 'row' => $row]);
                continue;
            }
            $dedupeKey = number_format($amount, 2, '.', '').'|'.$reference;
            if (isset($seen[$dedupeKey])) {
                Log::info('credit_check_v3_db_ccj_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'duplicate_in_payload', 'row' => $row]);
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
                Log::info('credit_check_v3_db_ccj_skipped', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'reason' => 'already_exists', 'row' => $row]);
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
            Log::info('credit_check_v3_db_ccj_inserted', ['lead_id' => $lead->id, 'job_id' => $jobId, 'pdf_status' => $pdfStatus, 'debt_id' => $debt->id, 'balance' => $amount]);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $reportPayload
     * @return array<int, array<string, mixed>>
     */
    private function extractDebtsRows(array $reportPayload): array
    {
        $candidates = [
            $reportPayload['debts'] ?? null,
            $reportPayload['accounts'] ?? null,
            $reportPayload['credit_accounts'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== [] && array_is_list($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $reportPayload
     * @return array<int, array<string, mixed>>
     */
    private function extractCcjRows(array $reportPayload): array
    {
        $publicRecords = $reportPayload['public_records'] ?? null;
        $fromPublicRecords = [];
        if (is_array($publicRecords) && array_is_list($publicRecords)) {
            foreach ($publicRecords as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $type = strtolower(trim((string) ($row['type'] ?? $row['record_type'] ?? '')));
                if (str_contains($type, 'county court') || str_contains($type, 'judgment') || str_contains($type, 'ccj')) {
                    $fromPublicRecords[] = $row;
                }
            }
        }

        $candidates = [
            $reportPayload['county_court_judgments'] ?? null,
            $reportPayload['ccjs'] ?? null,
            $reportPayload['judgments'] ?? null,
            $fromPublicRecords,
        ];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== [] && array_is_list($candidate)) {
                return $candidate;
            }
        }

        return [];
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
            return ! in_array($part, $removeWords, true);
        });

        return trim(implode(' ', $parts));
    }
}
