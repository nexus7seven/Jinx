<?php

namespace App\Http\Controllers;

use App\Models\CreditCheckJobLog;
use App\Models\Lead;
use App\Models\VotingPractice;
use App\Services\CreditCheckV3FlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CreditCheckV3Controller extends Controller
{
    public function __construct(
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
        $result = $this->creditCheckV3FlowService->panelPollForLead($lead);
        $autoImport = $this->maybeAutoImportCompletedCreditCheck($lead);

        if ($autoImport !== null) {
            $result['payload']['auto_import'] = $autoImport;
        }

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
        $log = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->where('external_job_id', $jobId)
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return response()->json([
                'ok' => false,
                'message' => 'Credit check job log not found for lead/job.',
            ], 404);
        }

        $result = $this->creditCheckV3FlowService->importFromJobLog($log);

        return response()->json(
            $result['payload'],
            $result['http'] ?? 500
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function maybeAutoImportCompletedCreditCheck(Lead $lead): ?array
    {
        $log = CreditCheckJobLog::query()
            ->where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->first();

        if (! $log) {
            return null;
        }

        $resultJson = is_array($log->result_json) ? $log->result_json : [];
        $panelImportDone = ($resultJson['panel_import_done'] ?? false) === true;
        $panelImportFailed = ($resultJson['panel_import_failed'] ?? false) === true;

        Log::info('credit_check_v3_auto_import_check', [
            'lead_id' => $lead->id,
            'log_id' => $log->id,
            'job_id' => $log->external_job_id,
            'status' => $log->status,
            'panel_import_done' => $panelImportDone,
            'panel_import_failed' => $panelImportFailed,
        ]);

        if ($log->status === 'success_imported' || $panelImportDone || $panelImportFailed) {
            return [
                'attempted' => false,
                'ok' => true,
                'status' => $log->status,
            ];
        }

        if ($log->status !== CreditCheckJobLog::STATUS_SUCCESS || empty($log->external_job_id)) {
            return [
                'attempted' => false,
                'ok' => false,
                'status' => $log->status,
            ];
        }

        Log::info('credit_check_v3_auto_import_triggered', [
            'lead_id' => $lead->id,
            'log_id' => $log->id,
            'job_id' => $log->external_job_id,
        ]);

        $importResult = $this->creditCheckV3FlowService->importFromJobLog($log);
        $ok = (bool) ($importResult['payload']['ok'] ?? false);
        $log->refresh();

        if (! $ok) {
            $resultJson = is_array($log->result_json) ? $log->result_json : [];
            $error = (string) ($importResult['payload']['message'] ?? 'Auto import failed.');
            $resultJson['panel_import_failed'] = true;
            $resultJson['panel_import_error'] = $error;
            $log->update(['result_json' => $resultJson]);
            $log->refresh();

            Log::warning('credit_check_v3_auto_import_failed', [
                'lead_id' => $lead->id,
                'log_id' => $log->id,
                'job_id' => $log->external_job_id,
                'reason' => $error,
                'error' => $error,
            ]);
        }

        Log::info('credit_check_v3_auto_import_result', [
            'lead_id' => $lead->id,
            'log_id' => $log->id,
            'job_id' => $log->external_job_id,
            'ok' => $ok,
            'imported' => $importResult['payload']['imported'] ?? null,
            'status_after' => $log->status,
        ]);

        return [
            'attempted' => true,
            'ok' => $ok,
            'status' => $log->status,
        ];
    }
}
