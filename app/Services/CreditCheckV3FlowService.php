<?php

namespace App\Services;

use App\Models\CreditCheckJobLog;
use App\Models\Debt;
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
}
