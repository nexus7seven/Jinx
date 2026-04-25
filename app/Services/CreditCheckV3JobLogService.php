<?php

namespace App\Services;

use App\Models\CreditCheckJobLog;
use Illuminate\Support\Facades\Http;
use Throwable;

class CreditCheckV3JobLogService
{
    public function listenerBase(): string
    {
        return rtrim((string) config('services.credit_check_v3_listener.base_url'), '/');
    }

    /**
     * @return array{reachable: bool, active_job: bool|null, raw: mixed}
     */
    public function fetchListenerHealth(): array
    {
        try {
            $response = Http::acceptJson()->timeout(3)->get($this->listenerBase().'/health');
            $json = $response->json();

            return [
                'reachable' => $response->successful() && is_array($json) && (($json['ok'] ?? false) === true),
                'active_job' => is_array($json) ? ($json['activeJob'] ?? null) : null,
                'raw' => $json,
            ];
        } catch (Throwable) {
            return [
                'reachable' => false,
                'active_job' => null,
                'raw' => null,
            ];
        }
    }

    public function listenerStatusLabel(array $health): string
    {
        if (!$health['reachable']) {
            return 'Listener offline';
        }

        return 'Listener ready';
    }

    /**
     * @param  array<string, mixed>  $bundle
     *  Same shape as CreditCheckV3Controller::status JSON body (state, questions, reportData, pdfState).
     */
    public function appendRawSnapshot(CreditCheckJobLog $log, string $label, array $bundle): void
    {
        $line = '['.now()->toIso8601String()."] {$label}: ".json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $existing = (string) ($log->raw_log ?? '');
        $log->raw_log = $existing === '' ? $line : $existing."\n".$line;
        $log->save();
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    public function syncFromListenerBundle(CreditCheckJobLog $log, array $bundle): void
    {
        $state = (array) ($bundle['state'] ?? []);
        $latest = (array) ($state['latestStatus'] ?? []);
        $latestData = (array) ($latest['data'] ?? []);
        $step = (string) ($latestData['step'] ?? '');

        $line = $this->mapStepToFriendlyLine($step, $latestData, $bundle);
        $result = is_array($log->result_json) ? $log->result_json : [];
        $lines = isset($result['activity_lines']) && is_array($result['activity_lines'])
            ? $result['activity_lines']
            : [];
        if ($line !== null && $line !== '' && (count($lines) === 0 || (string) end($lines) !== $line)) {
            $lines[] = $line;
            if (count($lines) > 80) {
                $lines = array_slice($lines, -80);
            }
        }
        $result['activity_lines'] = $lines;
        $log->result_json = $result;

        if ($line !== null && $line !== '') {
            $log->friendly_status = $line;
        }

        $immediateFailSteps = [
            'job_failed',
            'otp_poll_exhausted',
            'identity_validation_failed',
            'report_flow_failed',
            'report_data_store_failed',
            'report_page_health_failed',
            'pdf_click_failed',
            'pdf_save_failed',
            'pdf_save_failed_timeout',
            'save_as_confirm_failed',
            'save_as_confirm_timeout',
            'step_timeout',
        ];

        $finalReason = strtolower((string) ($latestData['reason'] ?? ''));

        if ($step === 'job_finalized') {
            $finalStatus = strtolower((string) ($latestData['status'] ?? ''));
            $log->ended_at = $log->ended_at ?? now();
            if ($finalStatus === 'success') {
                $log->status = CreditCheckJobLog::STATUS_SUCCESS;
                $log->friendly_status = (string) ($latestData['userStatusTitle'] ?? 'Completed');
            } elseif ($finalStatus === 'timeout') {
                $log->status = CreditCheckJobLog::STATUS_TIMEOUT;
                $log->friendly_status = (string) ($latestData['userStatusTitle'] ?? 'Credit check timed out');
                $log->error_message = (string) ($latestData['userStatusMessage'] ?? $log->friendly_status);
            } elseif ($finalStatus === 'cancelled') {
                $log->status = CreditCheckJobLog::STATUS_CANCELLED;
                $log->friendly_status = (string) ($latestData['userStatusTitle'] ?? 'Cancelled');
                $log->error_message = (string) ($latestData['userStatusMessage'] ?? $log->friendly_status);
            } else {
                $log->status = CreditCheckJobLog::STATUS_FAILED;
                $title = (string) ($latestData['userStatusTitle'] ?? 'Failed');
                $msg = (string) ($latestData['userStatusMessage'] ?? '');
                if ($finalReason === 'otp_poll_exhausted') {
                    $title = $title !== '' && $title !== 'Failed' ? $title : 'Verification code was not received';
                    $msg = $msg !== '' ? $msg : 'OTP polling exhausted without code';
                }
                $log->friendly_status = $title;
                $log->error_message = $msg !== '' ? $msg : $title;
            }
        } elseif ($step !== '' && in_array($step, $immediateFailSteps, true)) {
            $log->status = CreditCheckJobLog::STATUS_FAILED;
            $log->ended_at = $log->ended_at ?? now();
            $title = (string) ($latestData['userStatusTitle'] ?? '');
            if ($title === '') {
                $title = (string) ($line ?? 'Failed');
            }
            $msg = (string) ($latestData['userStatusMessage'] ?? '');
            if ($step === 'otp_poll_exhausted' || $finalReason === 'otp_poll_exhausted') {
                $title = $title !== '' && $title !== 'Failed' ? $title : 'Verification code was not received';
                $msg = $msg !== '' ? $msg : 'OTP polling exhausted without code';
            }
            $log->friendly_status = $title !== '' ? $title : 'Failed';
            $log->error_message = $msg !== '' ? $msg : ($title !== '' ? $title : 'Credit check failed');
        } elseif ($step === 'security_questions' || $step === 'kba_answers_applied') {
            $log->status = CreditCheckJobLog::STATUS_RUNNING;
        } elseif ($step !== '') {
            $log->status = CreditCheckJobLog::STATUS_RUNNING;
        }

        $queued = (bool) ($state['queued'] ?? false);
        $active = (bool) ($state['active'] ?? false);
        if ($queued && !$active && !$log->isTerminal()) {
            $log->friendly_status = $log->friendly_status ?? 'Queued on local listener';
        }

        $log->save();
    }

    /**
     * @param  array<string, mixed>  $latestData
     * @param  array<string, mixed>  $bundle
     */
    private function mapStepToFriendlyLine(string $step, array $latestData, array $bundle): ?string
    {
        return match ($step) {
            'job_finalized' => (string) ($latestData['userStatusTitle'] ?? 'Completed'),
            'security_questions' => 'Awaiting verification',
            'kba_answers_applied' => 'Completing verification',
            'otp_submitted', 'page_detected' => 'Submitting details',
            'report_iframe_ready', 'report_financial_nav_clicked', 'report_public_nav_clicked' => 'Finding address',
            'report_data_scraped', 'report_financial_scraped', 'report_public_empty_debug' => 'Downloading report',
            'pdf_click_attempted', 'pdf_saved', 'save_as_confirm_started', 'save_as_confirm_completed' => 'Saving PDF',
            'import_completed' => 'Completed',
            'job_failed', 'identity_validation_failed', 'report_flow_failed',
            'report_data_store_failed', 'report_page_health_failed', 'pdf_click_failed',
            'pdf_save_failed', 'pdf_save_failed_timeout', 'save_as_confirm_failed', 'save_as_confirm_timeout',
            'otp_poll_exhausted' => 'Verification code was not received',
            'step_timeout' => (string) ($latestData['userStatusTitle'] ?? 'Failed'),
            default => $this->mapStepHeuristic($step, $bundle),
        };
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function mapStepHeuristic(string $step, array $bundle): ?string
    {
        if ($step === '') {
            return null;
        }
        if (str_contains($step, 'address') || str_contains($step, 'postcode')) {
            return 'Confirming address';
        }
        if (str_contains($step, 'about') || str_contains($step, 'submit')) {
            return 'Submitting details';
        }

        return 'Preparing secure browser session';
    }
}
